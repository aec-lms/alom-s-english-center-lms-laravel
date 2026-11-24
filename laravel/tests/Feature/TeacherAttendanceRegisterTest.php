<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use Plugins\Modules\TeacherManagement\Models\Teacher;
use Plugins\Modules\AttendanceManagement\Models\TeacherAttendanceRecord;
use Tests\TestCase;

class TeacherAttendanceRegisterTest extends TestCase
{
    public function test_admin_can_record_and_report_teacher_attendance(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management','teacher-management','attendance-management']);
        $branch=DB::table('branches')->insertGetId(['code'=>'TATT-01','name'=>'Teacher Attendance','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $admin=$this->createUserWithRole('admin',$branch);
        $permission=Permission::query()->where('slug','attendance.manage')->value('id');
        $admin->roles()->first()->permissions()->syncWithoutDetaching([$permission]);
        $teacherUser=$this->createUserWithRole('teacher',$branch,'teacher-attendance@aec.test');
        $teacher=Teacher::query()->create(['user_id'=>$teacherUser->id,'branch_id'=>$branch,'employee_code'=>'TATT-001','is_active'=>true]);

        $this->actingAs($admin)->get('/admin/attendance/teachers')->assertOk()->assertSee('Teacher Attendance');
        $this->actingAs($admin)->post('/admin/attendance/teachers',[
            'teacher_id'=>$teacher->id,'attendance_date'=>now()->toDateString(),'status'=>'late',
            'check_in_at'=>now()->subMinutes(20)->format('Y-m-d\\TH:i'),'remarks'=>'Traffic delay',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('teacher_attendance_records',[
            'teacher_id'=>$teacher->id,'branch_id'=>$branch,'status'=>'late','remarks'=>'Traffic delay','source'=>'admin',
        ]);
        $this->actingAs($admin)->get('/admin/attendance/teachers?status=late')->assertOk()->assertSee('Traffic delay');
    }

    public function test_teacher_can_check_in_and_check_out_only_once_per_day(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management','teacher-management','attendance-management']);
        $branch=DB::table('branches')->insertGetId(['code'=>'TATT-02','name'=>'Teacher Punch','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $teacherUser=$this->createUserWithRole('teacher',$branch,'teacher-punch@aec.test');
        $teacher=Teacher::query()->create(['user_id'=>$teacherUser->id,'branch_id'=>$branch,'employee_code'=>'TATT-002','is_active'=>true]);

        // Pin the first punch to a stable daytime hour so the 8-hour shift
        // remains on the same attendance date regardless of CI runner time.
        $this->travelTo(now()->startOfDay()->addHours(9));
        $this->actingAs($teacherUser)->post('/attendance/teacher/punch')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('teacher_attendance_records',['teacher_id'=>$teacher->id,'status'=>'present','source'=>'self']);
        $this->travel(8)->hours();
        $this->actingAs($teacherUser)->post('/attendance/teacher/punch')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull(TeacherAttendanceRecord::query()->where('teacher_id',$teacher->id)->firstOrFail()->fresh()->check_out_at);
        $this->actingAs($teacherUser)->post('/attendance/teacher/punch')->assertStatus(409);
        $this->assertDatabaseCount('teacher_attendance_records',1);
    }

    public function test_branch_manager_cannot_record_teacher_from_another_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management','teacher-management','attendance-management']);
        $branchA=DB::table('branches')->insertGetId(['code'=>'TATT-A','name'=>'Branch A','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $branchB=DB::table('branches')->insertGetId(['code'=>'TATT-B','name'=>'Branch B','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $manager=$this->createUserWithRole('branch_manager',$branchA);
        $permission=Permission::query()->where('slug','attendance.manage')->value('id');
        $manager->roles()->first()->permissions()->syncWithoutDetaching([$permission]);
        $teacherUser=$this->createUserWithRole('teacher',$branchB,'teacher-other-branch@aec.test');
        $teacher=Teacher::query()->create(['user_id'=>$teacherUser->id,'branch_id'=>$branchB,'employee_code'=>'TATT-003','is_active'=>true]);

        $this->actingAs($manager)->post('/admin/attendance/teachers',[
            'teacher_id'=>$teacher->id,'attendance_date'=>now()->toDateString(),'status'=>'present',
        ])->assertForbidden();
        $this->assertDatabaseMissing('teacher_attendance_records',['teacher_id'=>$teacher->id]);
    }
}
