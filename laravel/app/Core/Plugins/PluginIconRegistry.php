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
        'graduation-cap',
        'book-open',
        'clipboard-check',
        'calendar-check',
        'users',
        'building-2',
        'wallet',
        'credit-card',
        'receipt',
        'chart-no-axes-combined',
        'shield-check',
        'video',
        'play-circle',
        'messages-square',
        'bell',
        'mail',
        'file-check',
        'images',
        'panels-top-left',
        'layout-template',
        'settings',
        'puzzle',
        'shopping-bag',
        'shopping-cart',
        'tag',
        'megaphone',
        'headset',
        'globe',
        'languages',
        'award',
        'trophy',
        'brain',
        'sparkles',
        'database',
    ];

    private const SLUG_MAP = [
        'course-management' => 'graduation-cap',
        'course-assessments' => 'clipboard-check',
        'course-bundles' => 'book-open',
        'course-content-studio' => 'book-open',
        'course-wishlist' => 'book-open',
        'attendance-management' => 'calendar-check',
        'batch-management' => 'users',
        'branch-management' => 'building-2',
        'class-management' => 'building-2',
        'student-management' => 'users',
        'student-tracking' => 'chart-no-axes-combined',
        'guardian-portal' => 'users',
        'teacher-management' => 'users',
        'admission-management' => 'clipboard-check',
        'assignment-management' => 'file-check',
        'certificate-management' => 'award',
        'question-bank' => 'brain',
        'exam-management' => 'clipboard-check',
        'board-question-solver' => 'brain',
        'live-class' => 'video',
        'video-library' => 'play-circle',
        'support-center' => 'headset',
        'notification-center' => 'bell',
        'email-marketing' => 'mail',
        'cms-website' => 'globe',
        'page-builder' => 'panels-top-left',
        'slider-builder' => 'images',
        'carousel-builder' => 'images',
        'form-builder' => 'clipboard-check',
        'certificate-builder' => 'award',
        'theme-builder' => 'layout-template',
        'universal-payment' => 'credit-card',
        'gateway-sslcommerz' => 'credit-card',
        'gateway-stripe' => 'credit-card',
        'gateway-paypal' => 'wallet',
        'fees-management' => 'receipt',
        'expense-management' => 'wallet',
        'coupon-management' => 'tag',
        'flash-sale' => 'tag',
        'course-marketplace' => 'shopping-bag',
        'ebook-store' => 'book-open',
        'product-store' => 'shopping-cart',
        'role-ai-assistant' => 'sparkles',
        'admin-boosting' => 'chart-no-axes-combined',
        'seo-tools' => 'globe',
        'analytics' => 'chart-no-axes-combined',
        'backup-manager' => 'database',
        'security-center' => 'shield-check',
        'plugin-manager' => 'puzzle',
        'al-branding-loader' => 'sparkles',
        'quiz-contest' => 'trophy',
        'phonetics' => 'languages',
    ];


    public static function faviconSvg(string $name): string
    {
        $name = self::resolve('custom', 'general', $name);
        $paths = [
            'graduation-cap' => '<path d="m2 10 10-5 10 5-10 5-10-5Z"/><path d="M6 12v5c3.5 2.5 8.5 2.5 12 0v-5"/>',
            'book-open' => '<path d="M12 7v14M3 18V5a2 2 0 0 1 2-2h3a4 4 0 0 1 4 4 4 4 0 0 1 4-4h3a2 2 0 0 1 2 2v13h-5a4 4 0 0 0-4 3 4 4 0 0 0-4-3H3Z"/>',
            'clipboard-check' => '<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 4.5V3h6v1.5M8 13l2.5 2.5L16 10"/>',
            'calendar-check' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 15l2.5 2.5L16 12"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 8a3 3 0 0 1-2 2.83M20 21v-2a4 4 0 0 0-3-3.87"/>',
            'building-2' => '<path d="M3 21h18M5 21V5l7-3 7 3v16M9 9h1m4 0h1m-6 4h1m4 0h1"/>',
            'wallet' => '<path d="M4 6h15a2 2 0 0 1 2 2v11H5a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3h13"/><path d="M21 11h-5a2 2 0 0 0 0 4h5"/>',
            'credit-card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M7 15h3"/>',
            'receipt' => '<path d="M4 3v18l4-2 4 2 4-2 4 2V3l-4 2-4-2-4 2-4-2Z"/><path d="M8 9h8M8 13h6"/>',
            'chart-no-axes-combined' => '<path d="M3 3v18h18M7 14l4-4 4 4 6-7"/>',
            'shield-check' => '<path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/>',
            'video' => '<rect x="3" y="5" width="13" height="14" rx="2"/><path d="m16 10 5-3v10l-5-3"/>',
            'play-circle' => '<circle cx="12" cy="12" r="10"/><path d="m10 8 6 4-6 4V8Z"/>',
            'messages-square' => '<path d="M21 11.5a8.5 8.5 0 0 1-9 8.5 9 9 0 0 1-4-.9L3 21l1.9-4.2A8.5 8.5 0 1 1 21 11.5Z"/>',
            'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
            'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 7L2 7"/>',
            'file-check' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 15l2 2 5-5"/>',
            'images' => '<rect x="3" y="3" width="18" height="14" rx="2"/><circle cx="8.5" cy="8" r="1.5"/><path d="m21 13-5-5L5 17"/>',
            'panels-top-left' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
            'layout-template' => '<rect x="3" y="3" width="8" height="7" rx="1"/><rect x="13" y="3" width="8" height="4" rx="1"/><rect x="13" y="9" width="8" height="12" rx="1"/><rect x="3" y="12" width="8" height="9" rx="1"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19 15 21 17l-2 3-3-1-2 2h-4l-1-3-3-1-2-3 2-2V9l3-2 2-3h4l2 3 3 1 1 3-2 2Z"/>',
            'puzzle' => '<path d="M19 13h-2a2 2 0 1 1-4 0V5h-3v2a2 2 0 1 1-4 0V5H4v14h6v-2a2 2 0 1 1 4 0v2h5v-6Z"/>',
            'shopping-bag' => '<path d="M5 8h14l1 13H4L5 8ZM9 8a3 3 0 0 1 6 0"/>',
            'shopping-cart' => '<path d="M3 3h2l2 13h12l3-9H6"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/>',
            'tag' => '<path d="M20 13 11 22l-9-9V2h11l7 7a3 3 0 0 1 0 4Z"/><circle cx="7" cy="7" r="1"/>',
            'megaphone' => '<path d="m3 11 18-5v12L3 13v-2ZM11 14l2 7H8l-2-8"/>',
            'headset' => '<path d="M3 13v-2a9 9 0 0 1 18 0v2M3 13h4v7H5a2 2 0 0 1-2-2v-5ZM21 13h-4v7h2a2 2 0 0 0 2-2v-5Z"/>',
            'globe' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>',
            'languages' => '<path d="m5 8 6 6M4 14l6-6 2-3M2 5h12M7 2v3M14 21l5-12 5 12M16 17h6"/>',
            'award' => '<circle cx="12" cy="8" r="6"/><path d="m8 13-1 9 5-3 5 3-1-9"/>',
            'trophy' => '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4ZM7 6H3v2a5 5 0 0 0 5 5M17 6h4v2a5 5 0 0 1-5 5"/>',
            'brain' => '<path d="M12 18V5a3 3 0 0 0-5.8-1A4 4 0 0 0 4 11a4 4 0 0 0 2 7 3 3 0 0 0 6 0ZM12 18V5a3 3 0 0 1 5.8-1A4 4 0 0 1 20 11a4 4 0 0 1-2 7 3 3 0 0 1-6 0Z"/>',
            'sparkles' => '<path d="m12 3 1.5 5.5L19 10l-5.5 1.5L12 17l-1.5-5.5L5 10l5.5-1.5L12 3ZM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8L19 16Z"/>',
            'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/>',
        ];

        $path = $paths[$name] ?? $paths['puzzle'];

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#334155" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
    }

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
            'enrollment' => 'users',
            'teacher' => 'users',
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
