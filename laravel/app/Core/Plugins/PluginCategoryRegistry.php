<?php

declare(strict_types=1);

namespace App\Core\Plugins;

/**
 * Backwards-compatible display taxonomy for plugin packages.
 *
 * Manifest category strings remain untouched because they are persisted in
 * PluginRecord and may be consumed by older integrations and icon resolution.
 */
final class PluginCategoryRegistry
{
    private const LABELS = [
        'academic-learning' => 'Academic & Learning',
        'student-lifecycle' => 'Student & People Lifecycle',
        'finance-commerce' => 'Finance & Commerce',
        'communication-support' => 'Communication & Support',
        'content-cms' => 'Content & Website CMS',
        'marketing-growth' => 'Marketing & Growth',
        'analytics-operations' => 'Analytics & Organization Operations',
        'integrations-api' => 'Integrations & APIs',
        'ai-automation' => 'AI & Automation',
        'platform-branding' => 'Platform Branding',
        'other' => 'Other',
    ];

    private const PLUGIN_GROUPS = [
        'academic-learning' => [
            'assignment-management', 'attendance-management', 'batch-management',
            'board-question-solver', 'certificate-management', 'class-management',
            'course-assessments', 'course-content-studio', 'course-management',
            'course-wishlist', 'exam-management', 'learn-and-earn',
            'learning-paths', 'learning-rewards', 'library-management', 'phonetics', 'question-bank', 'quiz-contest',
        ],
        'student-lifecycle' => [
            'admission-management', 'guardian-management', 'guardian-portal',
            'student-management', 'student-tracing', 'student-tracking', 'teacher-management',
        ],
        'finance-commerce' => [
            'commerce-cart', 'course-bundles', 'expense-management', 'fees-management',
            'gateway-card', 'gateway-paypal', 'gateway-sslcommerz', 'gateway-stripe',
            'payment-management', 'referral-income', 'storefront-catalog', 'universal-payment',
        ],
        'communication-support' => [
            'email-notifications', 'notice-board', 'notification-center',
            'push-notifications', 'sms-gateway', 'support-center',
        ],
        'content-cms' => [
            'carousel-builder', 'cms-website', 'media-library', 'page-builder', 'slider-builder',
        ],
        'marketing-growth' => [
            'admin-boosting', 'flash-sale', 'gmail-marketing', 'google-console',
            'marketing-management', 'meta-pixel', 'seo-manager', 'visitor-popup-ads',
        ],
        'analytics-operations' => [
            'branch-management', 'hr-staff-management', 'reports-analytics',
        ],
        'integrations-api' => ['mobile-app-api'],
        'ai-automation' => ['role-ai-assistant'],
        'platform-branding' => ['al-branding-loader'],
    ];

    private const LEGACY_CATEGORY_FALLBACKS = [
        'academic' => 'academic-learning',
        'education' => 'academic-learning',
        'finance' => 'finance-commerce',
        'payment-gateway' => 'finance-commerce',
        'commerce' => 'finance-commerce',
        'communication' => 'communication-support',
        'communications' => 'communication-support',
        'website' => 'content-cms',
        'content' => 'content-cms',
        'appearance' => 'platform-branding',
        'marketing' => 'marketing-growth',
        'seo' => 'marketing-growth',
        'analytics' => 'analytics-operations',
        'operations' => 'analytics-operations',
        'integration' => 'integrations-api',
        'ai' => 'ai-automation',
    ];

    public static function keyFor(string $slug, string $legacyCategory = ''): string
    {
        foreach (self::PLUGIN_GROUPS as $key => $slugs) {
            if (in_array($slug, $slugs, true)) {
                return $key;
            }
        }

        return self::LEGACY_CATEGORY_FALLBACKS[strtolower(trim($legacyCategory))] ?? 'other';
    }

    public static function labelFor(string $slug, string $legacyCategory = ''): string
    {
        return self::LABELS[self::keyFor($slug, $legacyCategory)];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return self::LABELS;
    }

    /** @return list<string> */
    public static function knownSlugs(): array
    {
        return array_values(array_merge(...array_values(self::PLUGIN_GROUPS)));
    }
}
