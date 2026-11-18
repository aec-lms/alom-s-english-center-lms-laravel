<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Plugins\PluginManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\ExamManagement\Models\Exam;
use Plugins\Modules\UniversalPayment\Models\UniversalPaymentTransaction;
use Tests\TestCase;

class PaidLearnAndEarnCheckoutTest extends TestCase
{
    public function test_paid_exam_access_is_granted_only_after_server_verified_sslcommerz_payment(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'course-management',
            'exam-management',
            'learn-and-earn',
            'payment-management',
            'universal-payment',
            'gateway-sslcommerz',
        ]);

        app(PluginManager::class)->saveSettings('gateway-sslcommerz', [
            'store_id' => 'test-store',
            'store_password' => 'test-secret',
            'sandbox_mode' => '1',
        ], ['store_password']);

        $course = Course::query()->create([
            'code' => 'LEARN-EARN-PAID',
            'title' => 'Paid Exam Course',
            'slug' => 'paid-exam-course',
            'price' => 0,
            'status' => 'published',
        ]);

        $exam = Exam::query()->create([
            'course_id' => $course->id,
            'title' => 'Paid Grammar Exam',
            'duration_minutes' => 30,
            'pass_marks' => 5,
            'learn_earn_type' => 'paid',
            'access_price' => 150,
            'is_published' => true,
        ]);

        $student = $this->createUserWithRole('student');
        $expectedReference = '';

        Http::fake(function ($request) use (&$expectedReference) {
            if (str_contains($request->url(), '/gwprocess/v4/api.php')) {
                return Http::response([
                    'status' => 'SUCCESS',
                    'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gwprocess/v4/gateway.php?Q=paid-exam',
                    'sessionkey' => 'paid-exam-session',
                ]);
            }

            return Http::response([
                'status' => 'VALID',
                'tran_id' => $expectedReference,
                'amount' => '150.00',
                'currency' => 'BDT',
                'risk_level' => '0',
                'bank_tran_id' => 'BANK-PAID-EXAM',
            ]);
        });

        $this->actingAs($student)->post("/learn-and-earn/exams/{$exam->id}/checkout", [
            'payer_contact' => '01700000000',
        ])->assertRedirect('https://sandbox.sslcommerz.com/gwprocess/v4/gateway.php?Q=paid-exam');

        $transaction = UniversalPaymentTransaction::query()
            ->where('user_id', $student->id)
            ->where('learn_earn_exam_id', $exam->id)
            ->firstOrFail();

        $expectedReference = $transaction->reference;
        $this->assertSame(150.0, (float) $transaction->amount);
        $this->assertSame('pending', $transaction->status);

        $this->actingAs($student)->post("/learn-and-earn/exams/{$exam->id}/checkout", [
            'payer_contact' => '01700000000',
        ])->assertStatus(409);
        $this->assertSame(1, UniversalPaymentTransaction::query()
            ->where('user_id', $student->id)
            ->where('learn_earn_exam_id', $exam->id)
            ->count());

        $this->actingAs($student)->get("/learn-and-earn/exams/{$exam->id}")->assertForbidden();

        $this->post('/universal-payment/sslcommerz/success', [
            'tran_id' => $transaction->reference,
            'val_id' => 'VALID-PAID-EXAM',
        ])->assertRedirect("/learn-and-earn/exams/{$exam->id}");

        $this->assertDatabaseHas('learn_earn_exam_access', [
            'exam_id' => $exam->id,
            'user_id' => $student->id,
            'status' => 'granted',
            'payment_reference' => $transaction->reference,
        ]);
        $this->assertSame('verified', $transaction->fresh()->status);

        $this->actingAs($student)->get("/learn-and-earn/exams/{$exam->id}")->assertOk();

        $this->post('/universal-payment/sslcommerz/success', [
            'tran_id' => $transaction->reference,
            'val_id' => 'VALID-PAID-EXAM',
        ])->assertRedirect("/learn-and-earn/exams/{$exam->id}");

        $this->assertSame(1, DB::table('learn_earn_exam_access')
            ->where('exam_id', $exam->id)->where('user_id', $student->id)->count());
    }
    public function test_ambiguous_gateway_timeout_holds_exam_checkout_for_reconciliation_instead_of_allowing_double_payment(): void
    {
        $this->markSystemInstalled();
        $this->activatePlugins([
            'course-management',
            'exam-management',
            'learn-and-earn',
            'universal-payment',
            'gateway-sslcommerz',
        ]);

        app(PluginManager::class)->saveSettings('gateway-sslcommerz', [
            'store_id' => 'test-store',
            'store_password' => 'test-secret',
            'sandbox_mode' => '1',
        ], ['store_password']);

        $course = Course::query()->create([
            'code' => 'LEARN-EARN-TIMEOUT',
            'title' => 'Timeout Exam Course',
            'slug' => 'timeout-exam-course',
            'price' => 0,
            'status' => 'published',
        ]);
        $exam = Exam::query()->create([
            'course_id' => $course->id,
            'title' => 'Timeout Protected Exam',
            'duration_minutes' => 30,
            'pass_marks' => 5,
            'learn_earn_type' => 'paid',
            'access_price' => 175,
            'is_published' => true,
        ]);
        $student = $this->createUserWithRole('student');

        Http::fake(function () {
            throw new ConnectionException('Simulated provider timeout after session request.');
        });

        $this->actingAs($student)
            ->from("/learn-and-earn/exams/{$exam->id}")
            ->post("/learn-and-earn/exams/{$exam->id}/checkout", [
                'payer_contact' => '01700000000',
            ])
            ->assertRedirect("/learn-and-earn/exams/{$exam->id}")
            ->assertSessionHasErrors('payment');

        $transaction = UniversalPaymentTransaction::query()
            ->where('user_id', $student->id)
            ->where('learn_earn_exam_id', $exam->id)
            ->firstOrFail();
        $this->assertSame('risk_review', $transaction->status);
        $this->assertStringContainsString('outcome is unknown', (string) $transaction->notes);

        $this->actingAs($student)
            ->post("/learn-and-earn/exams/{$exam->id}/checkout", [
                'payer_contact' => '01700000000',
            ])
            ->assertStatus(409);

        $this->assertSame(1, UniversalPaymentTransaction::query()
            ->where('user_id', $student->id)
            ->where('learn_earn_exam_id', $exam->id)
            ->count());
        $this->assertDatabaseMissing('learn_earn_exam_access', [
            'exam_id' => $exam->id,
            'user_id' => $student->id,
            'status' => 'granted',
        ]);
    }

}
