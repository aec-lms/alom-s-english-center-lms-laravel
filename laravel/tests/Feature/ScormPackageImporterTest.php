<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Support\\Facades\\File;
use Plugins\\Modules\\Scorm\\Services\\ScormPackageImporter;
use RuntimeException;
use Tests\\TestCase;
use ZipArchive;

class ScormPackageImporterTest extends TestCase
{
    private function makeArchive(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'aec-scorm-');
        if ($path === false) {
            throw new RuntimeException('Unable to create test archive path.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create test ZIP archive.');
        }

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function validManifest(string $href = 'index.html'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<manifest xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2" identifier="MANIFEST">'
            . '<organizations default="ORG"><organization identifier="ORG"><title>English Module</title>'
            . '<item identifier="ITEM" identifierref="RES"><title>Unit One</title></item>'
            . '</organization></organizations><resources>'
            . '<resource identifier="RES" type="webcontent" href="' . htmlspecialchars($href, ENT_XML1) . '" />'
            . '</resources></manifest>';
    }

    public function test_valid_scorm_12_package_is_extracted_to_private_storage(): void
    {
        $archive = $this->makeArchive([
            'imsmanifest.xml' => $this->validManifest(),
            'index.html' => '<!doctype html><title>English Module</title>',
            'assets/player.js' => 'window.scormReady = true;',
        ]);
        $imported = null;

        try {
            $imported = app(ScormPackageImporter::class)->import($archive);
            $this->assertSame('English Module', $imported['title']);
            $this->assertSame('index.html', $imported['launch_file']);
            $this->assertSame(3, $imported['file_count']);
            $this->assertFileExists($imported['directory'] . '/index.html');
            $this->assertSame(64, strlen($imported['package_sha256']));
            $this->assertSame(0700, fileperms($imported['directory']) & 0777);
            $this->assertSame(0600, fileperms($imported['directory'] . '/index.html') & 0777);
        } finally {
            if ($imported && is_dir($imported['directory'])) {
                File::deleteDirectory($imported['directory']);
            }
            @unlink($archive);
        }
    }

    public function test_archive_path_traversal_is_rejected_before_extraction(): void
    {
        $archive = $this->makeArchive([
            'imsmanifest.xml' => $this->validManifest(),
            'index.html' => 'ok',
            '../outside.txt' => 'must not escape',
        ]);

        try {
            $this->expectException(RuntimeException::class);
            app(ScormPackageImporter::class)->import($archive);
        } finally {
            @unlink($archive);
        }
    }

    public function test_manifest_xxe_and_doctype_are_rejected(): void
    {
        $manifest = '<!DOCTYPE manifest [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<manifest><organizations><organization><title>&xxe;</title></organization></organizations></manifest>';
        $archive = $this->makeArchive(['imsmanifest.xml' => $manifest, 'index.html' => 'ok']);

        try {
            $this->expectException(RuntimeException::class);
            app(ScormPackageImporter::class)->import($archive);
        } finally {
            @unlink($archive);
        }
    }

    public function test_manifest_cannot_reference_a_launch_file_outside_the_package(): void
    {
        $archive = $this->makeArchive([
            'imsmanifest.xml' => $this->validManifest('../index.html'),
            'index.html' => 'ok',
        ]);

        try {
            $this->expectException(RuntimeException::class);
            app(ScormPackageImporter::class)->import($archive);
        } finally {
            @unlink($archive);
        }
    }

    public function test_missing_manifest_is_rejected(): void
    {
        $archive = $this->makeArchive(['index.html' => 'not a SCORM package']);

        try {
            $this->expectException(RuntimeException::class);
            app(ScormPackageImporter::class)->import($archive);
        } finally {
            @unlink($archive);
        }
    }
}
