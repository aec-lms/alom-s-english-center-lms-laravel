<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class PluginSiblingDependencyAuditTest extends TestCase
{
    public function test_direct_sibling_namespace_references_are_declared_as_required_or_optional_integrations(): void
    {
        $this->markSystemInstalled();

        $directories = File::directories(base_path('plugins/modules'));
        $namespaceToSlug = [];
        $manifests = [];

        foreach ($directories as $directory) {
            $manifestPath = $directory . DIRECTORY_SEPARATOR . 'plugin.json';
            if (!File::exists($manifestPath)) {
                continue;
            }

            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $slug = (string) ($manifest['slug'] ?? basename($directory));
            $namespaceToSlug[Str::studly($slug)] = $slug;
            $manifests[$slug] = $manifest;
        }

        $violations = [];
        foreach ($manifests as $slug => $manifest) {
            foreach (array_merge($manifest['dependencies'] ?? [], $manifest['optional_dependencies'] ?? []) as $dependency) {
                if (!isset($manifests[$dependency])) {
                    $violations[] = $slug . ' declares missing plugin dependency ' . $dependency . '.';
                }
            }
        }

        foreach ($directories as $directory) {
            $manifestPath = $directory . DIRECTORY_SEPARATOR . 'plugin.json';
            if (!File::exists($manifestPath)) {
                continue;
            }

            $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $slug = (string) ($manifest['slug'] ?? basename($directory));
            $declared = array_values(array_unique(array_merge(
                $manifest['dependencies'] ?? [],
                $manifest['optional_dependencies'] ?? []
            )));

            foreach (File::allFiles($directory) as $file) {
                if (strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $source = File::get($file->getPathname());
                if (!preg_match_all('~Plugins\\\\Modules\\\\([A-Za-z0-9_]+)\\\\~', $source, $matches)) {
                    continue;
                }

                foreach (array_unique($matches[1]) as $namespace) {
                    $siblingSlug = $namespaceToSlug[$namespace] ?? null;
                    if ($siblingSlug === null || $siblingSlug === $slug) {
                        continue;
                    }
                    if (!in_array($siblingSlug, $declared, true)) {
                        $violations[] = $slug . ' references ' . $siblingSlug . ' in ' . $file->getRelativePathname()
                            . ' but the manifest does not declare it as required or optional.';
                    }
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }
}
