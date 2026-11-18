<?php

declare(strict_types=1);

namespace Tests\Feature;

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
            ->assertDontSee('Third person singular takes')->assertDontSee('correct_option');

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
}
