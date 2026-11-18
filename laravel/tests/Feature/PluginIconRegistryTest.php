<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginIconRegistry;
use PHPUnit\Framework\TestCase;

class PluginIconRegistryTest extends TestCase
{
    public function test_existing_plugins_receive_purpose_specific_icons(): void
    {
        $this->assertSame('graduation-cap', PluginIconRegistry::resolve('course-management', 'academic'));
        $this->assertSame('calendar-check', PluginIconRegistry::resolve('attendance-management', 'academic'));
        $this->assertSame('credit-card', PluginIconRegistry::resolve('universal-payment', 'finance'));
        $this->assertSame('brain', PluginIconRegistry::resolve('question-bank', 'academic'));
        $this->assertSame('shield-check', PluginIconRegistry::resolve('security-center', 'security'));
    }

    public function test_manifest_icon_override_is_allow_listed_and_invalid_icons_fall_back_safely(): void
    {
        $this->assertSame('video', PluginIconRegistry::resolve('custom-plugin', 'general', 'video'));
        $this->assertSame('puzzle', PluginIconRegistry::resolve('custom-plugin', 'general', '<svg onload=alert(1)>'));
    }

    public function test_menu_labels_receive_contextual_icons(): void
    {
        $this->assertSame('graduation-cap', PluginIconRegistry::forMenuLabel('Course Enrollments', 'puzzle'));
        $this->assertSame('calendar-check', PluginIconRegistry::forMenuLabel('Attendance Records', 'puzzle'));
        $this->assertSame('puzzle', PluginIconRegistry::forMenuLabel('Unknown Tool', 'puzzle'));
    }

    public function test_every_resolved_icon_is_supported_by_the_svg_component(): void
    {
        $component = file_get_contents(resource_path('views/components/plugin-icon.blade.php'));
        $this->assertIsString($component);

        foreach (PluginIconRegistry::allowed() as $icon) {
            $this->assertStringContainsString("'{$icon}' =>", $component, "Missing SVG artwork for {$icon}.");
        }
    }
}
