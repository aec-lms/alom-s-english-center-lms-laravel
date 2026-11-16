<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BatchAndClassManagementTest extends TestCase
{
    public function test_admin_can_update_a_study_batch_and_academic_class(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['batch-management', 'class-management']);
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)->post('/admin/batches', [
            'batch_code' => 'BATCH-OLD',
            'name' => 'Old Batch',
            'course_title' => 'IELTS',
            'schedule' => 'Sun-Tue 10:00',
            'max_capacity' => 20,
            'status' => 'upcoming',
        ])->assertSessionHasNoErrors();
        $batchId = DB::table('study_batches')->where('batch_code', 'BATCH-OLD')->value('id');

        $this->actingAs($admin)->put('/admin/batches/' . $batchId, [
            'batch_code' => 'BATCH-NEW',
            'name' => 'Updated Batch',
            'course_title' => 'IELTS Advanced',
            'schedule' => 'Mon-Wed 11:00',
            'max_capacity' => 25,
            'status' => 'ongoing',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('study_batches', ['id' => $batchId, 'batch_code' => 'BATCH-NEW', 'name' => 'Updated Batch']);
        DB::table('study_batches')->where('id', $batchId)->update(['enrolled_count' => 3]);
        $this->actingAs($admin)->put('/admin/batches/' . $batchId, [
            'batch_code' => 'BATCH-NEW',
            'name' => 'Updated Batch',
            'course_title' => 'IELTS Advanced',
            'schedule' => 'Mon-Wed 11:00',
            'max_capacity' => 2,
            'status' => 'ongoing',
        ])->assertSessionHasErrors('max_capacity');

        $this->actingAs($admin)->post('/admin/classes', [
            'code' => 'CLASS-OLD',
            'name' => 'Old Class',
            'section' => 'A',
            'subject' => 'Writing',
            'day_of_week' => 'Sunday',
            'start_time' => '10:00',
            'end_time' => '11:00',
        ])->assertSessionHasNoErrors();
        $classId = DB::table('academic_classes')->where('code', 'CLASS-OLD')->value('id');

        $this->actingAs($admin)->put('/admin/classes/' . $classId, [
            'code' => 'CLASS-NEW',
            'name' => 'Updated Class',
            'section' => 'B',
            'subject' => 'Speaking',
            'day_of_week' => 'Monday',
            'start_time' => '12:00',
            'end_time' => '13:00',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('academic_classes', ['id' => $classId, 'code' => 'CLASS-NEW', 'name' => 'Updated Class']);
    }

    public function test_unassigned_branch_manager_cannot_view_batch_or_class_directories(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['batch-management', 'class-management']);
        $manager = $this->createUserWithRole('branch_manager');

        foreach (['batches.manage', 'classes.manage'] as $slug) {
            $permission = DB::table('permissions')->where('slug', $slug)->first();
            if ($permission) {
                $manager->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);
            }
        }

        $this->actingAs($manager)->get('/admin/batches')->assertForbidden();
        $this->actingAs($manager)->get('/admin/classes')->assertForbidden();
    }
}
