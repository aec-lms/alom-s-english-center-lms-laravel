<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CourseAssessmentsPluginTest extends TestCase
{
    private function admin(): object
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-assessments']);
        return $this->createUserWithRole('admin');
    }

    private function createSet(array $overrides = []): int
    {
        return DB::table('course_assessment_sets')->insertGetId(array_merge([
            'title'=>'English Grammar Test',
            'description'=>'A standalone assessment.',
            'status'=>'published',
            'time_limit_minutes'=>30,
            'max_attempts'=>2,
            'randomize_questions'=>false,
            'created_at'=>now(),
            'updated_at'=>now(),
        ],$overrides));
    }

    private function createQuestion(int $setId,array $overrides = []): int
    {
        return DB::table('course_assessment_questions')->insertGetId(array_merge([
            'assessment_set_id'=>$setId,
            'type'=>'mcq',
            'question'=>'Choose the correct form of the verb.',
            'options'=>json_encode(['go','goes','going']),
            'correct_option'=>1,
            'correct_answer'=>'goes',
            'explanation'=>'Third person singular takes -s.',
            'difficulty'=>'easy',
            'marks'=>2,
            'position'=>10,
            'created_at'=>now(),
            'updated_at'=>now(),
        ],$overrides));
    }

    public function test_course_assessment_plugin_is_standalone_and_admin_can_create_manual_question_set(): void
    {
        $admin=$this->admin();
        $this->actingAs($admin)->post(route('plugins.course-assessments.admin.store'),[
            'title'=>'Standalone English Assessment',
            'status'=>'published',
            'time_limit_minutes'=>20,
            'max_attempts'=>2,
            'questions'=>[
                ['type'=>'mcq','question'=>'Select the correct answer.','options'=>['A','B','C'],'correct_option'=>1,'marks'=>2,'difficulty'=>'easy'],
                ['type'=>'short_answer','question'=>'Write the plural of child.','correct_answer'=>'children','marks'=>3,'difficulty'=>'medium'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('course_assessment_sets',['title'=>'Standalone English Assessment','course_id'=>null,'status'=>'published']);
        $this->assertDatabaseCount('course_assessment_questions',2);
        $this->assertDatabaseHas('plugins',['slug'=>'course-assessments','is_installed'=>true,'is_enabled'=>true]);
    }

    public function test_question_bank_import_is_snapshotted_and_branch_scoped(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-assessments','question-bank']);
        $admin=$this->createUserWithRole('admin');
        $source=DB::table('question_bank_questions')->insertGetId([
            'exam_category'=>'bcs','question_type'=>'mcq','subject'=>'English','topic'=>'Tense','difficulty'=>'easy',
            'prompt'=>'Choose the past tense of go.','options'=>json_encode(['go','went','gone']),
            'correct_answer'=>'went','explanation'=>'Past form.','default_marks'=>2,'default_negative_marks'=>0,
            'tags'=>json_encode(['tense']),'is_active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);

        $this->actingAs($admin)->post(route('plugins.course-assessments.admin.store'),[
            'title'=>'Imported Grammar Set','status'=>'published','source_question_ids'=>[$source],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $snapshotId=(int)DB::table('course_assessment_questions')->value('id');
        DB::table('question_bank_questions')->where('id',$source)->update(['prompt'=>'Changed bank source wording.']);
        $this->assertDatabaseHas('course_assessment_questions',['id'=>$snapshotId,'source_question_id'=>$source,'question'=>'Choose the past tense of go.']);
    }

    public function test_student_attempt_autosaves_and_server_grades_without_exposing_answer_key(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-assessments']);
        $student=$this->createUserWithRole('student');
        $set=$this->createSet();
        $question=$this->createQuestion($set);

        $start=$this->actingAs($student)->post(route('plugins.course-assessments.student.start',$set));
        $start->assertRedirect();
        $attemptId=(int)basename(parse_url($start->headers->get('Location'),PHP_URL_PATH));

        $this->get(route('plugins.course-assessments.student.take',$attemptId))
            ->assertOk()->assertSee('Choose the correct form of the verb.')
            ->assertSee('goes')->assertDontSee('Third person singular takes')->assertDontSee('correct_answer');

        $attemptQuestion=(int)DB::table('course_assessment_attempt_questions')->where('attempt_id',$attemptId)->value('id');
        $this->putJson(route('plugins.course-assessments.student.answer',[$attemptId,$attemptQuestion]),['selected_option'=>1])
            ->assertOk()->assertJson(['saved'=>true]);

        $this->post(route('plugins.course-assessments.student.submit',$attemptId))->assertRedirect();
        $this->assertDatabaseHas('course_assessment_attempts',['id'=>$attemptId,'status'=>'submitted','auto_score'=>2]);
        $this->assertDatabaseHas('course_assessment_attempt_questions',['id'=>$attemptQuestion,'selected_option'=>1,'is_correct'=>1,'marks_awarded'=>2]);
    }

    public function test_written_answers_require_teacher_grading_and_attempt_limit_is_enforced(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-assessments']);
        $student=$this->createUserWithRole('student');
        $admin=$this->createUserWithRole('admin');
        $set=$this->createSet(['max_attempts'=>1]);
        $this->createQuestion($set,['type'=>'short_answer','options'=>null,'correct_option'=>null,'correct_answer'=>'children','question'=>'Write the plural of child.','marks'=>3]);
        $start=$this->actingAs($student)->post(route('plugins.course-assessments.student.start',$set));
        $attemptId=(int)basename(parse_url($start->headers->get('Location'),PHP_URL_PATH));
        $qId=(int)DB::table('course_assessment_attempt_questions')->where('attempt_id',$attemptId)->value('id');
        $this->putJson(route('plugins.course-assessments.student.answer',[$attemptId,$qId]),['answer_text'=>'childs'])->assertOk();
        $this->post(route('plugins.course-assessments.student.submit',$attemptId))->assertRedirect();
        $this->assertDatabaseHas('course_assessment_attempts',['id'=>$attemptId,'status'=>'pending_review']);

        $this->actingAs($admin)->patch(route('plugins.course-assessments.grading.update',$attemptId),[
            'scores'=>[$qId=>1],'feedback'=>[$qId=>'Check irregular plural.'],'teacher_feedback'=>'Please revise.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('course_assessment_attempts',['id'=>$attemptId,'status'=>'graded','manual_score'=>1,'final_score'=>1]);

        $this->actingAs($student)->from(route('plugins.course-assessments.student.index'))
            ->post(route('plugins.course-assessments.student.start',$set))
            ->assertForbidden();
        $this->assertDatabaseCount('course_assessment_attempts',1);
    }

    public function test_student_can_browse_standalone_assessments_without_course_management(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-assessments']);
        $student = $this->createUserWithRole('student');
        $set = $this->createSet(['title' => 'Standalone Visible Assessment']);

        $this->actingAs($student)
            ->get(route('plugins.course-assessments.student.index'))
            ->assertOk()
            ->assertSee('Standalone Visible Assessment');
    }

    public function test_answer_autosave_is_rejected_after_submit_and_after_deadline(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-assessments']);
        $student = $this->createUserWithRole('student');

        $submittedSet = $this->createSet(['title' => 'Submitted Attempt']);
        $this->createQuestion($submittedSet);
        $start = $this->actingAs($student)->post(route('plugins.course-assessments.student.start', $submittedSet));
        $attemptId = (int) basename(parse_url($start->headers->get('Location'), PHP_URL_PATH));
        $questionId = (int) DB::table('course_assessment_attempt_questions')->where('attempt_id', $attemptId)->value('id');
        $this->post(route('plugins.course-assessments.student.submit', $attemptId))->assertRedirect();
        $this->putJson(route('plugins.course-assessments.student.answer', [$attemptId, $questionId]), ['selected_option' => 1])
            ->assertStatus(409);

        $expiredSet = $this->createSet(['title' => 'Expired Attempt']);
        $this->createQuestion($expiredSet);
        $start = $this->actingAs($student)->post(route('plugins.course-assessments.student.start', $expiredSet));
        $expiredAttemptId = (int) basename(parse_url($start->headers->get('Location'), PHP_URL_PATH));
        $expiredQuestionId = (int) DB::table('course_assessment_attempt_questions')->where('attempt_id', $expiredAttemptId)->value('id');
        DB::table('course_assessment_attempts')->where('id', $expiredAttemptId)->update(['deadline_at' => now()->subSecond()]);
        $this->putJson(route('plugins.course-assessments.student.answer', [$expiredAttemptId, $expiredQuestionId]), ['selected_option' => 1])
            ->assertStatus(409);
    }

    public function test_branch_manager_cannot_create_assessment_from_inactive_branch(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'course-assessments']);
        $branchId = DB::table('branches')->insertGetId([
            'code' => 'ASSESS-INACTIVE',
            'name' => 'Inactive Assessment Branch',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleId = DB::table('roles')->where('slug', 'branch_manager')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'course_assessments.manage')->value('id');
        $this->assertNotNull($roleId);
        $this->assertNotNull($permissionId);
        DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        $manager = $this->createUserWithRole('branch_manager', $branchId);

        $this->actingAs($manager)->post(route('plugins.course-assessments.admin.store'), [
            'title' => 'Must Not Be Created',
            'status' => 'draft',
            'questions' => [
                ['type' => 'mcq', 'question' => 'Q?', 'options' => ['A', 'B'], 'correct_option' => 0, 'marks' => 1],
            ],
        ])->assertForbidden();

        $this->assertDatabaseMissing('course_assessment_sets', ['title' => 'Must Not Be Created']);
    }
    public function test_student_assessment_branch_uses_authoritative_student_profile_for_web_and_api(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['branch-management', 'student-management', 'course-assessments']);

        $branchA = DB::table('branches')->insertGetId([
            'code' => 'ASSESS-PROFILE-A-' . uniqid(), 'name' => 'Assessment Profile Branch A',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchB = DB::table('branches')->insertGetId([
            'code' => 'ASSESS-PROFILE-B-' . uniqid(), 'name' => 'Assessment Account Branch B',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $student = $this->createUserWithRole('student', (int) $branchB);
        DB::table('students')->insert([
            'user_id' => $student->id, 'branch_id' => $branchA,
            'student_code' => 'ASSESS-PROFILE-' . strtoupper(uniqid()),
            'admission_date' => now()->toDateString(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $profileSet = $this->createSet([
            'title' => 'Profile Branch Assessment', 'branch_id' => $branchA,
        ]);
        $accountSet = $this->createSet([
            'title' => 'Account Branch Assessment', 'branch_id' => $branchB,
        ]);
        $this->createQuestion($profileSet);
        $this->createQuestion($accountSet);

        $this->actingAs($student)->get(route('plugins.course-assessments.student.index'))
            ->assertOk()->assertSee('Profile Branch Assessment')->assertDontSee('Account Branch Assessment');
        $this->actingAs($student, 'sanctum')->getJson('/api/v1/my-learning/assessments')
            ->assertOk()->assertJsonFragment(['title' => 'Profile Branch Assessment'])
            ->assertJsonMissing(['title' => 'Account Branch Assessment']);

        $this->actingAs($student)->post(route('plugins.course-assessments.student.start', $profileSet))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($student)->post(route('plugins.course-assessments.student.start', $accountSet))
            ->assertForbidden();
    }


    public function test_revoked_student_role_immediately_blocks_all_web_attempt_endpoints(): void
    {
        $this->markSystemInstalled();
        // Rate-limit counters can be shared across repeated feature-test requests;
        // this regression isolates authorization outcomes from throttle responses.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->activatePlugins(['course-assessments']);
        $student = $this->createUserWithRole('student');
        $set = $this->createSet(['max_attempts' => 2]);
        $this->createQuestion($set);

        $start = $this->actingAs($student)->post(route('plugins.course-assessments.student.start', $set));
        $start->assertRedirect();
        $attemptId = (int) basename(parse_url($start->headers->get('Location'), PHP_URL_PATH));
        $token = $student->createToken('revoked-course-assessment-role')->plainTextToken;
        $questionId = (int) DB::table('course_assessment_attempt_questions')
            ->where('attempt_id', $attemptId)
            ->value('id');

        $studentRoleId = DB::table('roles')->where('slug', 'student')->value('id');
        $this->assertNotNull($studentRoleId);
        DB::table('role_user')
            ->where('user_id', $student->id)
            ->where('role_id', $studentRoleId)
            ->delete();

        // Refresh the authenticated model so this regression proves persisted-role
        // authorization rather than relying on a previously loaded role relation.
        $revokedStudent = $student->fresh();

        $this->actingAs($revokedStudent)
            ->get(route('plugins.course-assessments.student.index'))
            ->assertForbidden();
        $this->actingAs($revokedStudent)
            ->get(route('plugins.course-assessments.student.take', $attemptId))
            ->assertForbidden();
        $this->actingAs($revokedStudent)
            ->putJson(route('plugins.course-assessments.student.answer', [$attemptId, $questionId]), [
                'selected_option' => 1,
            ])->assertForbidden();
        $this->actingAs($revokedStudent)
            ->post(route('plugins.course-assessments.student.submit', $attemptId))
            ->assertForbidden();
        $this->actingAs($revokedStudent)
            ->get(route('plugins.course-assessments.student.history'))
            ->assertForbidden();

        // The same revoked identity must lose access through both supported API prefixes.
        foreach (['api/v1', 'api/mobile'] as $prefix) {
            $base = '/' . $prefix . '/my-learning/assessments';
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson($base)->assertForbidden();
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson($base . '/history')->assertForbidden();
            app('auth')->forgetGuards();
            $this->withToken($token)->postJson($base . '/' . $set . '/start')->assertForbidden();
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson($base . '/attempts/' . $attemptId)->assertForbidden();
            app('auth')->forgetGuards();
            $this->withToken($token)->putJson($base . '/attempts/' . $attemptId . '/questions/' . $questionId, [
                'selected_option' => 1,
            ])->assertForbidden();
            app('auth')->forgetGuards();
            $this->withToken($token)->postJson($base . '/attempts/' . $attemptId . '/submit')->assertForbidden();
        }

        $this->assertDatabaseHas('course_assessment_attempts', [
            'id' => $attemptId,
            'student_id' => $student->id,
            'status' => 'in_progress',
            'submitted_at' => null,
            'auto_score' => 0,
        ]);
        $this->assertDatabaseHas('course_assessment_attempt_questions', [
            'attempt_id' => $attemptId,
            'id' => $questionId,
            'selected_option' => null,
        ]);
    }
}
