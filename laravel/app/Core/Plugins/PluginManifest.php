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
        public readonly array $optionalDependencies = [],
        public readonly bool $isCore = false,
        public readonly array $adminMenu = [],
        public readonly string $icon = 'puzzle',
        public readonly array $dashboardWidgets = [],
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

        $dependencies = self::validatedDependencies($data, 'dependencies');
        $optionalDependencies = self::validatedDependencies($data, 'optional_dependencies');
        if (in_array($data['slug'], $dependencies, true) || in_array($data['slug'], $optionalDependencies, true)) {
            throw new InvalidArgumentException('A plugin cannot depend on itself.');
        }
        if (array_intersect($dependencies, $optionalDependencies) !== []) {
            throw new InvalidArgumentException('A plugin dependency cannot be both required and optional.');
        }
        if (array_key_exists('provider', $data) && $data['provider'] !== null
            && (!is_string($data['provider']) || preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/', $data['provider']) !== 1)
        ) {
            throw new InvalidArgumentException('Plugin provider must be a valid fully-qualified PHP class name.');
        }

        return new self(
            slug: $data['slug'],
            name: $data['name'],
            version: $data['version'],
            author: (string) ($data['author'] ?? "Alom's English Center"),
            category: (string) ($data['category'] ?? 'general'),
            description: (string) ($data['description'] ?? ''),
            coreCompatibility: (string) ($data['core_compatibility'] ?? '^2.0'),
            dependencies: $dependencies,
            providerClass: isset($data['provider']) && is_string($data['provider']) ? $data['provider'] : null,
            permissions: (array) ($data['permissions'] ?? []),
            settingsSchema: (array) ($data['settings_schema'] ?? []),
            basePath: $directory,
            optionalDependencies: $optionalDependencies,
            isCore: (bool) ($data['is_core'] ?? false),
            adminMenu: array_values(array_filter((array) ($data['admin_menu'] ?? []), 'is_array')),
            icon: PluginIconRegistry::resolve($data['slug'], (string) ($data['category'] ?? 'general'), isset($data['icon']) && is_string($data['icon']) ? $data['icon'] : null),
            dashboardWidgets: self::validatedDashboardWidgets($data, $directory),
        );
    }

    /** @return list<array{key:string,label:string,view:string,permission:?string,default:bool}> */
    private static function validatedDashboardWidgets(array $data, string $directory): array
    {
        $widgets = $data['dashboard_widgets'] ?? [];
        if (!is_array($widgets) || !array_is_list($widgets)) {
            throw new InvalidArgumentException('Plugin dashboard_widgets must be a JSON array.');
        }

        if (count($widgets) > 20) {
            throw new InvalidArgumentException('A plugin may register at most 20 dashboard widgets.');
        }

        $validated = [];
        $keys = [];
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                throw new InvalidArgumentException('Each plugin dashboard widget must be a JSON object.');
            }

            $key = $widget['key'] ?? null;
            $label = $widget['label'] ?? null;
            $view = $widget['view'] ?? null;
            $permission = $widget['permission'] ?? null;
            $default = $widget['default'] ?? false;

            if (!is_string($key) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key) !== 1 || in_array($key, $keys, true)) {
                throw new InvalidArgumentException('Plugin dashboard widget keys must be unique kebab-case identifiers.');
            }
            if (!is_string($label) || trim($label) === '' || mb_strlen($label) > 80) {
                throw new InvalidArgumentException('Plugin dashboard widget labels must contain 1 to 80 characters.');
            }
            if (!is_string($view) || preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/', $view) !== 1) {
                throw new InvalidArgumentException('Plugin dashboard widget views must be safe dotted view names.');
            }
            if ($permission !== null && (!is_string($permission) || preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $permission) !== 1)) {
                throw new InvalidArgumentException('Plugin dashboard widget permissions must be valid permission slugs.');
            }
            if (!is_bool($default)) {
                throw new InvalidArgumentException('Plugin dashboard widget default must be a boolean.');
            }

            $viewsRoot = rtrim($directory, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'Resources' . DIRECTORY_SEPARATOR . 'views';
            $viewPath = $viewsRoot . DIRECTORY_SEPARATOR
                . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.blade.php';
            $realViewsRoot = realpath($viewsRoot);
            $realViewPath = realpath($viewPath);
            if ($realViewsRoot === false || $realViewPath === false || !is_file($realViewPath)
                || !str_starts_with($realViewPath, $realViewsRoot . DIRECTORY_SEPARATOR)) {
                throw new InvalidArgumentException("Plugin dashboard widget view '{$view}' must resolve to a file inside the plugin's views directory.");
            }

            $keys[] = $key;
            $validated[] = [
                'key' => $data['slug'] . '.' . $key,
                'label' => trim($label),
                'view' => $view,
                'permission' => $permission,
                'default' => $default,
            ];
        }

        return $validated;
    }

    /** @return list<string> */
    private static function validatedDependencies(array $data, string $key): array
    {
        if (!array_key_exists($key, $data)) {
            return [];
        }

        $dependencies = $data[$key];
        if (!is_array($dependencies) || !array_is_list($dependencies)) {
            throw new InvalidArgumentException("Plugin {$key} must be a JSON array of plugin slugs.");
        }

        $validated = [];
        foreach ($dependencies as $dependency) {
            if (!is_string($dependency)
                || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $dependency) !== 1
                || in_array($dependency, $validated, true)
            ) {
                throw new InvalidArgumentException("Plugin {$key} contains an invalid or duplicate dependency slug.");
            }
            $validated[] = $dependency;
        }

        return $validated;
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
            'optional_dependencies' => $this->optionalDependencies,
            'provider' => $this->providerClass,
            'permissions' => $this->permissions,
            'settings_schema' => $this->settingsSchema,
            'is_core' => $this->isCore,
            'admin_menu' => $this->adminMenu,
            'icon' => $this->icon,
            'dashboard_widgets' => $this->dashboardWidgets,
        ];
    }
}
