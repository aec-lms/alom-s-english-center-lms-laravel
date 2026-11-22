<?php

declare(strict_types=1);

namespace Database\\Seeders;

use App\\Core\\Plugins\\PluginManager;
use App\\Models\\Role;
use App\\Models\\User;
use Illuminate\\Database\\Seeder;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Hash;
use Plugins\\Modules\\Scorm\\Services\\ScormPackageImporter;
use ZipArchive;

class ScormE2EFixtureSeeder extends Seeder
{
    public function run(): void
    {
        $plugins = app(PluginManager::class);
        $plugins->syncCatalog();
        foreach (['course-management', 'scorm'] as $slug) {
            $plugins->install($slug);
            $plugins->enable($slug);
        }

        $studentRole = Role::query()->where('slug', 'student')->firstOrFail();
        $student = User::query()->updateOrCreate(
            ['email' => 'scorm-e2e-student@aec.test'],
            [
                'name' => 'SCORM E2E Student',
                'password' => Hash::make('SCORM-E2E-Strong-Password-2026!'),
                'is_active' => true,
                'email_verified_at' => now(),
                'branch_id' => null,
            ]
        );
        $student->roles()->sync([$studentRole->id]);

        $courseId = DB::table('courses')->insertGetId([
            'branch_id' => null,
            'code' => 'SCORM-E2E-COURSE',
            'title' => 'SCORM Origin Isolation E2E Course',
            'slug' => 'scorm-origin-isolation-e2e',
            'description' => 'Real application SCORM origin-isolation fixture.',
            'price' => 0,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enrollments')->insert([
            'user_id' => $student->id,
            'course_id' => $courseId,
            'branch_id' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $zipPath = sys_get_temp_dir() . '/aec-scorm-e2e-' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new \\RuntimeException('Unable to create the SCORM E2E package archive.');
        }

        $manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="SCORM-E2E" version="1.0"
 xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2"
 xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2">
 <organizations default="ORG-1">
  <organization identifier="ORG-1">
   <title>SCORM Origin Isolation E2E Package</title>
   <item identifier="ITEM-1" identifierref="RES-1"><title>Isolation Lesson</title></item>
  </organization>
 </organizations>
 <resources>
  <resource identifier="RES-1" type="webcontent" adlcp:scormtype="sco" href="index.html">
   <file href="index.html"/>
  </resource>
 </resources>
</manifest>
XML;
        $sco = <<<'HTML'
<!doctype html>
<html><head><meta charset="utf-8"><title>SCORM Isolation Lesson</title></head>
<body>
<script>
(() => {
    const api = window.API;
    if (!api || api.LMSInitialize('') !== 'true') {
        document.body.dataset.apiReady = 'false';
        return;
    }

    const initialLocation = api.LMSGetValue('cmi.core.lesson_location');
    document.body.dataset.initialLocation = initialLocation;
    document.body.dataset.apiReady = 'true';
    document.body.dataset.parentDomBlocked = 'false';
    document.body.dataset.lmsDomBlocked = 'false';
    document.body.dataset.frameElementAvailable = String(window.frameElement !== null);
    document.body.dataset.sandboxRemovalSucceeded = 'false';
    document.body.dataset.cookieReadable = document.cookie.length > 0 ? 'true' : 'false';

    try {
        void parent.document.documentElement;
    } catch (error) {
        document.body.dataset.parentDomBlocked = 'true';
    }
    try {
        void top.document.documentElement;
    } catch (error) {
        document.body.dataset.lmsDomBlocked = 'true';
    }
    try {
        window.frameElement.removeAttribute('sandbox');
        document.body.dataset.sandboxRemovalSucceeded = 'true';
    } catch (error) {}

    if (!initialLocation) {
        api.LMSSetValue('cmi.core.lesson_location', 'unit-1/page-2');
        api.LMSSetValue('cmi.core.lesson_status', 'incomplete');
        api.LMSCommit('');
    }
})();
</script>
</body></html>
HTML;
        try {
            $zip->addFromString('imsmanifest.xml', $manifest);
            $zip->addFromString('index.html', $sco);
        } finally {
            $zip->close();
        }

        try {
            $imported = app(ScormPackageImporter::class)->import($zipPath);
            DB::table('scorm_packages')->insert([
                'course_id' => $courseId,
                'branch_id' => null,
                'created_by' => null,
                'title' => $imported['title'],
                'identifier' => 'SCORM-E2E',
                'version' => '1.2',
                'revision_number' => 1,
                'replaces_package_id' => null,
                'storage_directory' => basename($imported['directory']),
                'launch_file' => $imported['launch_file'],
                'manifest_file' => $imported['manifest'],
                'package_sha256' => $imported['package_sha256'],
                'file_count' => $imported['file_count'],
                'expanded_bytes' => $imported['total_bytes'],
                'status' => 'published',
                'published_at' => now(),
                'archived_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\\Throwable $exception) {
            if (isset($imported['directory']) && is_dir($imported['directory']) && !is_link($imported['directory'])) {
                \\Illuminate\\Support\\Facades\\File::deleteDirectory($imported['directory']);
            }
            throw $exception;
        } finally {
            if (is_file($zipPath) && !is_link($zipPath)) {
                @unlink($zipPath);
            }
        }
    }
}
