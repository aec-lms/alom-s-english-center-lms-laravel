<?php

declare(strict_types=1);

namespace App\Core\Plugins;

/**
 * Semantic, allow-listed icon identities for plugin manifests and navigation.
 * Plugins may choose an approved icon, while existing manifests receive a
 * deterministic icon based on their slug/category without requiring edits.
 */
final class PluginIconRegistry
{
    private const ICONS = [
        'graduation-cap', 'book-open', 'book-marked', 'clipboard-list',
        'clipboard-check', 'calendar-check', 'calendar-days', 'users',
        'user-round', 'user-check', 'user-cog', 'building-2', 'wallet',
        'credit-card', 'receipt', 'chart-no-axes-combined', 'chart-line',
        'shield-check', 'shield', 'lock-keyhole', 'video', 'play-circle',
        'message-circle', 'messages-square', 'bell', 'mail', 'file-text',
        'file-check', 'folder-open', 'image', 'images', 'panels-top-left',
        'layout-dashboard', 'layout-template', 'sliders-horizontal',
        'settings', 'puzzle', 'plug', 'shopping-bag', 'shopping-cart',
        'tag', 'ticket-percent', 'megaphone', 'target', 'headset',
        'globe', 'languages', 'award', 'trophy', 'brain', 'calculator',
        'clipboard-pen', 'clock', 'map-pin', 'qr-code', 'database',
        'server', 'cloud', 'chart-pie', 'receipt-text', 'truck',
        'briefcase-business', 'hand-coins', 'sparkles', 'newspaper',
    ];

    private const SLUG_MAP = [
        'course-management' => 'graduation-cap',
        'course-assessments' => 'clipboard-check',
        'course-bundles' => 'book-open',
        'course-content-studio' => 'book-marked',
        'course-wishlist' => 'heart',
        'attendance-management' => 'calendar-check',
        'batch-management' => 'users',
        'branch-management' => 'building-2',
        'class-management' => 'school',
        'student-management' => 'user-round',
        'student-tracking' => 'chart-no-axes-combined',
        'guardian-portal' => 'users',
        'teacher-management' => 'user-check',
        'admission-management' => 'clipboard-pen',
        'assignment-management' => 'file-check',
        'certificate-management' => 'award',
        'question-bank' => 'brain',
        'exam-management' => 'clipboard-list',
        'board-question-solver' => 'calculator',
        'live-class' => 'video',
        'video-library' => 'play-circle',
        'support-center' => 'headset',
        'notification-center' => 'bell',
        'email-marketing' => 'mail',
        'cms-website' => 'globe',
        'page-builder' => 'panels-top-left',
        'slider-builder' => 'images',
        'carousel-builder' => 'images',
        'form-builder' => 'clipboard-pen',
        'certificate-builder' => 'award',
        'theme-builder' => 'layout-template',
        'universal-payment' => 'credit-card',
        'gateway-sslcommerz' => 'credit-card',
        'gateway-stripe' => 'credit-card',
        'gateway-paypal' => 'wallet',
        'fees-management' => 'receipt',
        'expense-management' => 'hand-coins',
        'coupon-management' => 'ticket-percent',
        'flash-sale' => 'tag',
        'course-marketplace' => 'shopping-bag',
        'ebook-store' => 'book-open',
        'product-store' => 'shopping-cart',
        'role-ai-assistant' => 'sparkles',
        'admin-boosting' => 'chart-line',
        'seo-tools' => 'globe',
        'analytics' => 'chart-no-axes-combined',
        'backup-manager' => 'database',
        'security-center' => 'shield-check',
        'plugin-manager' => 'puzzle',
        'al-branding-loader' => 'sparkles',
        'quiz-contest' => 'trophy',
        'phonetics' => 'languages',
    ];

    public static function allowed(): array
    {
        return self::ICONS;
    }

    public static function resolve(string $slug, string $category = 'general', ?string $requested = null): string
    {
        if ($requested !== null && in_array($requested, self::ICONS, true)) {
            return $requested;
        }

        if (isset(self::SLUG_MAP[$slug]) && in_array(self::SLUG_MAP[$slug], self::ICONS, true)) {
            return self::SLUG_MAP[$slug];
        }

        return match (strtolower($category)) {
            'academic', 'education' => 'book-open',
            'finance', 'payment' => 'wallet',
            'communication', 'marketing' => 'megaphone',
            'security' => 'shield-check',
            'analytics', 'reporting' => 'chart-no-axes-combined',
            'builder', 'design' => 'layout-template',
            'media' => 'images',
            default => 'puzzle',
        };
    }

    public static function forMenuLabel(string $label, string $fallback): string
    {
        $label = strtolower($label);

        foreach ([
            'attendance' => 'calendar-check',
            'course' => 'graduation-cap',
            'enrollment' => 'user-check',
            'teacher' => 'user-round',
            'student' => 'users',
            'guardian' => 'users',
            'exam' => 'clipboard-check',
            'question' => 'brain',
            'payment' => 'credit-card',
            'invoice' => 'receipt',
            'fee' => 'wallet',
            'report' => 'chart-no-axes-combined',
            'setting' => 'settings',
            'notification' => 'bell',
            'message' => 'messages-square',
            'live class' => 'video',
            'assignment' => 'file-check',
            'certificate' => 'award',
            'batch' => 'users',
            'branch' => 'building-2',
            'page' => 'panels-top-left',
            'media' => 'images',
        ] as $needle => $icon) {
            if (str_contains($label, $needle)) {
                return $icon;
            }
        }

        return $fallback;
    }
}
