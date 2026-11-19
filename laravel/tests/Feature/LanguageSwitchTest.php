<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Tests\\TestCase;

class LanguageSwitchTest extends TestCase
{
    public function test_guest_can_switch_between_bengali_and_english_and_preference_persists(): void
    {
        $this->markSystemInstalled();

        $this->post('/language/bn')->assertRedirect('/');
        $this->assertSessionHas('locale', 'bn');
        $this->get('/login')->assertOk()->assertSee('স্বাগতম!')->assertSee('লগইন');

        $this->post('/language/en')->assertRedirect('/');
        $this->assertSessionHas('locale', 'en');
        $this->get('/login')->assertOk()->assertSee('Welcome Back!')->assertSee('Login');
    }

    public function test_institution_bengali_locale_is_normalized_to_supported_catalogue(): void
    {
        $this->markSystemInstalled();
        config(['app.locale' => 'bn-BD']);

        $this->get('/login')->assertOk()->assertSee('স্বাগতম!')->assertSee('লগইন');
    }

    public function test_language_switch_rejects_unsupported_locale_and_external_redirect_target(): void
    {
        $this->markSystemInstalled();

        $this->post('/language/fr')->assertNotFound();
        $this->withHeader('Referer', 'https://evil.example/redirect')
            ->post('/language/bn')
            ->assertRedirect('/');

        $this->assertSessionHas('locale', 'bn');
    }
}
