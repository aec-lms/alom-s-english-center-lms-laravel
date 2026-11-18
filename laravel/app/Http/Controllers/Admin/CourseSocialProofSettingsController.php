<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CourseSocialProofSettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(): View
    {
        $defaults = [
            'enabled' => true,
            'show_views' => true,
            'show_purchases' => true,
            'show_on_cards' => true,
            'show_on_details' => true,
        ];
        $raw = SystemSetting::query()->where('key', 'course.social_proof')->value('value');
        $stored = is_string($raw) ? json_decode($raw, true) : [];
        $settings = array_merge($defaults, is_array($stored) ? $stored : []);

        return view('admin.course-social-proof.edit', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'show_views' => ['nullable', 'boolean'],
            'show_purchases' => ['nullable', 'boolean'],
            'show_on_cards' => ['nullable', 'boolean'],
            'show_on_details' => ['nullable', 'boolean'],
        ]);

        $settings = [
            'enabled' => $request->boolean('enabled'),
            'show_views' => $request->boolean('show_views'),
            'show_purchases' => $request->boolean('show_purchases'),
            'show_on_cards' => $request->boolean('show_on_cards'),
            'show_on_details' => $request->boolean('show_on_details'),
        ];

        SystemSetting::query()->updateOrCreate(
            ['key' => 'course.social_proof'],
            [
                'value' => json_encode($settings, JSON_THROW_ON_ERROR),
                'is_encrypted' => false,
                'updated_by' => $request->user()->id,
            ]
        );

        $this->audit->log('admin.course_social_proof.updated', 'system_setting', 'course.social_proof', $settings, $request->user()->id);

        return redirect()->route('admin.course-social-proof.edit')->with('status', 'Course views and purchase display settings saved.');
    }
}
