<?php

declare(strict_types=1);

namespace App\Core\Plugins;

use InvalidArgumentException;

final class PluginManifest
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version,
        public readonly string $author,
        public readonly string $category,
        public readonly string $description,
        public readonly string $coreCompatibility,
        public readonly array $dependencies,
        public readonly ?string $providerClass,
        public readonly array $permissions,
        public readonly array $settingsSchema,
        public readonly string $basePath,
        public readonly bool $isCore = false,
    ) {}

    public static function fromDirectory(string $directory): self
    {
        $manifestPath = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'plugin.json';
        if (!is_file($manifestPath)) {
            throw new InvalidArgumentException("Plugin manifest not found at {$manifestPath}");
        }

        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            throw new InvalidArgumentException("Unable to read plugin manifest at {$manifestPath}");
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Plugin manifest must be a JSON object.');
        }

        foreach (['slug', 'name', 'version'] as $required) {
            if (empty($data[$required]) || !is_string($data[$required])) {
                throw new InvalidArgumentException("Plugin manifest missing required field: {$required}");
            }
        }

        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $data['slug'])) {
            throw new InvalidArgumentException("Invalid plugin slug: {$data['slug']}");
        }
        if (preg_match('/^\\d+\\.\\d+\\.\\d+(?:-[0-9A-Za-z.-]+)?(?:\\+[0-9A-Za-z.-]+)?$/', $data['version']) !== 1) {
            throw new InvalidArgumentException("Plugin version must use semantic versioning: {$data['version']}");
        }

        return new self(
            slug: $data['slug'],
            name: $data['name'],
            version: $data['version'],
            author: (string) ($data['author'] ?? "Alom's English Center"),
            category: (string) ($data['category'] ?? 'general'),
            description: (string) ($data['description'] ?? ''),
            coreCompatibility: (string) ($data['core_compatibility'] ?? '^2.0'),
            dependencies: array_values(array_filter((array) ($data['dependencies'] ?? []), 'is_string')),
            providerClass: isset($data['provider']) && is_string($data['provider']) ? $data['provider'] : null,
            permissions: (array) ($data['permissions'] ?? []),
            settingsSchema: (array) ($data['settings_schema'] ?? []),
            basePath: $directory,
            isCore: (bool) ($data['is_core'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'version' => $this->version,
            'author' => $this->author,
            'category' => $this->category,
            'description' => $this->description,
            'core_compatibility' => $this->coreCompatibility,
            'dependencies' => $this->dependencies,
            'provider' => $this->providerClass,
            'permissions' => $this->permissions,
            'settings_schema' => $this->settingsSchema,
            'is_core' => $this->isCore,
        ];
    }
}
