<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

class ScormPluginAdminTest extends TestCase
{
    private function setupScorm(): array
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['course-management', 'scorm']);

        $admin = $this->createUserWithRole('admin');
        $courseId = DB::table('courses')->insertGetId([
            'branch_id' => null,
            'code' => 'SCORM-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'title' => 'SCORM English Course',
            'slug' => 'scorm-english-' . bin2hex(random_bytes(3)),
            'description' => 'Course for SCORM package tests.',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$admin, $courseId];
    }

    private function packageUpload(string $title = 'Admin Imported English Unit'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'aec-scorm-admin-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('imsmanifest.xml', '<?xml version="1.0"?><manifest xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2" xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2"><organizations><organization><title>' . htmlspecialchars($title, ENT_XML1) . '</title><item identifier="ITEM" identifierref="RES"/></organization></organizations><resources><resource identifier="RES" type="webcontent" adlcp:scormtype="sco" href="index.html"/></resources></manifest>');
        $zip->addFromString('index.html', '<title>SCORM unit</title>');
        $zip->close();

        $bytes = file_get_contents($path);
        @unlink($path);
        if (!is_string($bytes)) {
            throw new \RuntimeException('Unable to read generated SCORM test package.');
        }

        return UploadedFile::fake()->createWithContent('english-unit.zip', $bytes);
    }

    public function test_admin_can_import_and_publish_a_scorm_package_as_a_course_resource(): void
    {
        [$admin, $courseId] = $this->setupScorm();

        $this->actingAs($admin)
            ->post(route('plugins.scorm.admin.store'), [
                'course_id' => $courseId,
                'package' => $this->packageUpload(),
            ])
            ->assertRedirect(route('plugins.scorm.admin.index'))
            ->assertSessionHas('status');

        $package = DB::table('scorm_packages')->where('course_id', $courseId)->first();
        $this->assertNotNull($package);
        $this->assertSame('draft', $package->status);
        $this->assertSame('Admin Imported English Unit', $package->title);
        $this->assertDirectoryExists(storage_path('app/scorm-packages/' . $package->storage_directory));

        $this->actingAs($admin)
            ->patch(route('plugins.scorm.admin.publish', $package->id))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('scorm_packages', ['id' => $package->id, 'status' => 'published']);
    }

    public function test_package_cannot_be_archived_while_a_learner_attempt_is_active(): void
    {
        [$admin, $courseId] = $this->setupScorm();
        $this->actingAs($admin)->post(route('plugins.scorm.admin.store'), [
            'course_id' => $courseId,
            'package' => $this->packageUpload(),
        ])->assertRedirect();

        $package = DB::table('scorm_packages')->where('course_id', $courseId)->first();
        $this->actingAs($admin)->patch(route('plugins.scorm.admin.publish', $package->id))->assertRedirect();
        $student = $this->createUserWithRole('student');
        DB::table('scorm_attempts')->insert([
            'scorm_package_id' => $package->id,
            'user_id' => $student->id,
            'enrollment_id' => null,
            'attempt_number' => 1,
            'lesson_status' => 'incomplete',
            'entry_mode' => 'ab-initio',
            'lesson_location' => '',
            'suspend_data' => null,
            'score_raw' => null,
            'score_min' => null,
            'score_max' => null,
            'total_time_seconds' => 0,
            'session_time_seconds' => 0,
            'cmi_data' => '{}',
            'completion_state' => 'in_progress',
            'started_at' => now(),
            'last_commit_at' => null,
            'finished_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = $this->actingAs($admin)->get(route('plugins.scorm.admin.index'));
        $report->assertOk()->assertSee('Attempts')->assertSee('Completed')->assertSee('Active');
        $this->assertStringContainsString('1', $report->getContent());

        $this->actingAs($admin)
            ->patch(route('plugins.scorm.admin.archive', $package->id))
            ->assertStatus(409);
        $this->assertDatabaseHas('scorm_packages', ['id' => $package->id, 'status' => 'published']);
    }


    public function test_scorm_plugin_installs_without_course_management_and_keeps_optional_integration_disabled(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['scorm']);

        $this->assertDatabaseHas('plugins', [
            'slug' => 'scorm',
            'is_installed' => true,
            'is_enabled' => true,
        ]);

        $admin = $this->createUserWithRole('admin');
        $this->actingAs($admin)->get(route('plugins.scorm.admin.index'))->assertOk();
    }

    public function test_replacement_is_staged_without_disabling_current_package_and_publishes_after_attempts_finish(): void
    {
        [$admin, $courseId] = $this->setupScorm();

        $this->actingAs($admin)->post(route('plugins.scorm.admin.store'), [
            'course_id' => $courseId,
            'package' => $this->packageUpload(),
        ])->assertRedirect();
        $current = DB::table('scorm_packages')->where('course_id', $courseId)->first();
        $this->actingAs($admin)->patch(route('plugins.scorm.admin.publish', $current->id))->assertRedirect();

        $student = $this->createUserWithRole('student');
        DB::table('enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $courseId,
            'branch_id' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('scorm_attempts')->insert([
            'scorm_package_id' => $current->id,
            'user_id' => $student->id,
            'enrollment_id' => DB::table('enrollments')->where('user_id', $student->id)->value('id'),
            'attempt_number' => 1,
            'lesson_status' => 'incomplete',
            'entry_mode' => 'ab-initio',
            'lesson_location' => '',
            'suspend_data' => null,
            'score_raw' => null,
            'score_min' => null,
            'score_max' => null,
            'total_time_seconds' => 0,
            'session_time_seconds' => 0,
            'cmi_data' => '{}',
            'completion_state' => 'in_progress',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('plugins.scorm.admin.store'), [
            'course_id' => $courseId,
            'replace_package_id' => $current->id,
            'package' => $this->packageUpload('Updated English Unit'),
        ])->assertRedirect();

        $replacement = DB::table('scorm_packages')->where('replaces_package_id', $current->id)->first();
        $this->assertNotNull($replacement);
        $this->assertSame(2, (int) $replacement->revision_number);
        $this->assertSame('draft', $replacement->status);
        $this->assertDatabaseHas('scorm_packages', ['id' => $current->id, 'status' => 'published']);

        $this->actingAs($admin)
            ->patch(route('plugins.scorm.admin.publish', $replacement->id))
            ->assertStatus(409);
        $this->assertDatabaseHas('scorm_packages', ['id' => $current->id, 'status' => 'published']);

        DB::table('scorm_attempts')->where('scorm_package_id', $current->id)->update([
            'completion_state' => 'completed',
            'lesson_status' => 'completed',
            'finished_at' => now(),
        ]);
        $this->actingAs($admin)
            ->patch(route('plugins.scorm.admin.publish', $replacement->id))
            ->assertRedirect();

        $this->assertDatabaseHas('scorm_packages', ['id' => $current->id, 'status' => 'superseded']);
        $this->assertDatabaseHas('scorm_packages', ['id' => $replacement->id, 'status' => 'published']);
        $this->assertDatabaseHas('scorm_attempts', ['scorm_package_id' => $current->id, 'completion_state' => 'completed']);

        $this->actingAs($admin)->get(route('plugins.scorm.admin.index'))
            ->assertOk()->assertSee('Attempts')->assertSee('Completed')->assertSee('revision 2');
        $this->actingAs($student)->get(route('plugins.scorm.student.index'))
            ->assertOk()->assertSee('Updated English Unit')->assertDontSee('Admin Imported English Unit');
    }


    public function test_admin_upload_rejects_non_zip_files(): void
    {
        [$admin, $courseId] = $this->setupScorm();

        $this->actingAs($admin)
            ->post(route('plugins.scorm.admin.store'), [
                'course_id' => $courseId,
                'package' => UploadedFile::fake()->create('not-a-package.txt', 1, 'text/plain'),
            ])
            ->assertSessionHasErrors('package');

        $this->assertDatabaseCount('scorm_packages', 0);
    }

}
