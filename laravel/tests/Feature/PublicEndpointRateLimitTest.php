<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class PublicEndpointRateLimitTest extends TestCase
{
    public function test_password_reset_email_endpoint_is_rate_limited_to_prevent_mail_abuse(): void
    {
        $this->markSystemInstalled();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post('/forgot-password', [
                'email' => 'unknown-rate-limit@example.test',
            ])->assertRedirect();
        }

        $this->post('/forgot-password', [
            'email' => 'unknown-rate-limit@example.test',
        ])->assertTooManyRequests();
    }

    public function test_public_contact_form_is_rate_limited_to_prevent_submission_spam(): void
    {
        $this->markSystemInstalled();

        $payload = [
            'name' => 'Rate Limit Test',
            'email' => 'contact-rate-limit@example.test',
            'subject' => 'Admissions question',
            'message' => 'Please send course details.',
        ];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/contact-us', $payload)->assertRedirect();
        }

        $this->post('/contact-us', $payload)->assertTooManyRequests();
    }
}
