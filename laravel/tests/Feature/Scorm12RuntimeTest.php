<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Plugins\\Modules\\Scorm\\Services\\Scorm12Runtime;
use Tests\\TestCase;

class Scorm12RuntimeTest extends TestCase
{
    private function runtime(): Scorm12Runtime
    {
        return new Scorm12Runtime('STUDENT-1', 'English Learner', 'unit=1', 'resume', 'normal');
    }

    public function test_runtime_requires_initialization_and_exposes_read_only_learner_context(): void
    {
        $runtime = $this->runtime();

        $this->assertSame('', $runtime->getValue('cmi.core.student_id'));
        $this->assertSame('301', $runtime->getLastError());
        $this->assertSame('true', $runtime->initialize());
        $this->assertSame('STUDENT-1', $runtime->getValue('cmi.core.student_id'));
        $this->assertSame('English Learner', $runtime->getValue('cmi.core.student_name'));
        $this->assertSame('resume', $runtime->getValue('cmi.core.entry'));
        $this->assertSame('3.4', $runtime->getValue('cmi._version'));
    }

    public function test_runtime_accepts_valid_writes_and_rejects_read_only_and_invalid_values(): void
    {
        $runtime = $this->runtime();
        $this->assertSame('true', $runtime->initialize());

        $this->assertSame('true', $runtime->setValue('cmi.core.lesson_location', 'unit-1/page-2'));
        $this->assertSame('unit-1/page-2', $runtime->getValue('cmi.core.lesson_location'));
        $this->assertSame('true', $runtime->setValue('cmi.core.lesson_status', 'incomplete'));
        $this->assertSame('true', $runtime->setValue('cmi.core.score.raw', '87.5'));
        $this->assertSame('true', $runtime->setValue('cmi.suspend_data', 'checkpoint-1'));

        $this->assertSame('false', $runtime->setValue('cmi.core.student_id', 'forged-user'));
        $this->assertSame('403', $runtime->getLastError());
        $this->assertSame('false', $runtime->setValue('cmi.core.score.raw', '999'));
        $this->assertSame('405', $runtime->getLastError());
        $this->assertSame('false', $runtime->setValue('cmi.core.lesson_status', 'approved'));
        $this->assertSame('405', $runtime->getLastError());
    }

    public function test_runtime_rejects_unknown_elements_and_write_only_reads(): void
    {
        $runtime = $this->runtime();
        $runtime->initialize();

        $this->assertSame('', $runtime->getValue('cmi.core.exit'));
        $this->assertSame('404', $runtime->getLastError());
        $this->assertSame('', $runtime->getValue('cmi.unknown.element'));
        $this->assertSame('201', $runtime->getLastError());
        $this->assertSame('true', $runtime->setValue('cmi.core.exit', 'suspend'));
        $this->assertSame('true', $runtime->setValue('cmi.core.session_time', '0000:01:25.50'));
    }

    public function test_commit_and_finish_are_single_lifecycle_operations(): void
    {
        $runtime = $this->runtime();
        $this->assertSame('false', $runtime->commit());
        $this->assertSame('301', $runtime->getLastError());

        $this->assertSame('true', $runtime->initialize());
        $this->assertSame('true', $runtime->commit());
        $this->assertSame('0', $runtime->getLastError());
        $this->assertSame('true', $runtime->finish());
        $this->assertTrue($runtime->isFinished());
        $this->assertSame('false', $runtime->commit());
        $this->assertSame('301', $runtime->getLastError());
    }

    public function test_saved_suspend_data_is_restored_for_a_resumed_attempt(): void
    {
        $runtime = new Scorm12Runtime('STUDENT-1', 'English Learner', '', 'resume', 'normal', '0000:10:00.00', [
            'cmi.core.lesson_location' => 'unit-3/page-4',
            'cmi.suspend_data' => 'checkpoint-3',
            'cmi.core.lesson_status' => 'incomplete',
        ]);
        $runtime->initialize();

        $this->assertSame('unit-3/page-4', $runtime->getValue('cmi.core.lesson_location'));
        $this->assertSame('checkpoint-3', $runtime->getValue('cmi.suspend_data'));
        $this->assertSame('incomplete', $runtime->getValue('cmi.core.lesson_status'));
    }
}
