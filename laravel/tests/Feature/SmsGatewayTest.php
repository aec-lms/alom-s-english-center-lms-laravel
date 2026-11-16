<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsGatewayTest extends TestCase
{
    public function test_greenweb_campaign_is_marked_sent_only_after_provider_acceptance(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['sms-gateway']);
        $admin = $this->createUserWithRole('admin');
        Http::fake([
            'api.greenweb.com.bd/*' => Http::response("Ok: SMS Sent Successfully To +8801712345678", 200),
        ]);

        $this->actingAs($admin)->post('/plugins/sms/settings', [
            'provider' => 'greenweb',
            'sender_id' => 'AECLMS',
            'api_token' => 'test-token',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/plugins/sms/campaigns', [
            'title' => 'Admission notice',
            'audience' => 'custom',
            'custom_numbers' => '01712345678',
            'message' => 'Admission is open.',
        ])->assertSessionHas('status', 'Greenweb accepted all SMS messages.');

        $this->assertDatabaseHas('sms_campaigns', [
            'title' => 'Admission notice',
            'recipient_count' => 1,
            'provider' => 'greenweb',
            'status' => 'sent',
        ]);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.greenweb.com.bd/api.php'
            && $request['to'] === '+8801712345678'
            && $request['message'] === 'Admission is open.');
    }

    public function test_greenweb_error_response_is_not_reported_as_sent(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['sms-gateway']);
        $admin = $this->createUserWithRole('admin');
        Http::fake([
            'api.greenweb.com.bd/*' => Http::response("Error: +8801712345678 Invalid Number !", 200),
        ]);

        $this->actingAs($admin)->post('/plugins/sms/settings', [
            'provider' => 'greenweb',
            'api_token' => 'test-token',
        ]);

        $this->actingAs($admin)->post('/plugins/sms/campaigns', [
            'title' => 'Rejected message',
            'audience' => 'custom',
            'custom_numbers' => '+8801712345678',
            'message' => 'Test',
        ])->assertSessionHas('status', 'Greenweb rejected all SMS messages.');

        $this->assertDatabaseHas('sms_campaigns', [
            'title' => 'Rejected message',
            'status' => 'failed',
        ]);
    }

    public function test_unimplemented_provider_and_invalid_custom_numbers_do_not_send_sms(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins(['sms-gateway']);
        $admin = $this->createUserWithRole('admin');
        Http::fake();

        $this->actingAs($admin)->post('/plugins/sms/settings', [
            'provider' => 'twilio',
            'api_token' => 'twilio-token',
        ]);

        $this->actingAs($admin)->post('/plugins/sms/campaigns', [
            'title' => 'Pending adapter',
            'audience' => 'custom',
            'custom_numbers' => '01712345678',
            'message' => 'Test',
        ])->assertSessionHas('status', "The selected provider 'twilio' is not connected yet. No SMS was sent.");

        $this->assertDatabaseHas('sms_campaigns', [
            'title' => 'Pending adapter',
            'status' => 'provider_not_implemented',
        ]);
        Http::assertNothingSent();

        $this->actingAs($admin)->post('/plugins/sms/campaigns', [
            'title' => 'Invalid number',
            'audience' => 'custom',
            'custom_numbers' => '12345',
            'message' => 'Test',
        ])->assertSessionHasErrors('custom_numbers');

        $this->assertSame(1, DB::table('sms_campaigns')->count());
        Http::assertNothingSent();
    }
}
