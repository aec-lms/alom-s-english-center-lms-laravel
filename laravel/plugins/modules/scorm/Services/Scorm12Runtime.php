<?php

declare(strict_types=1);

namespace Plugins\Modules\Scorm\Services;

class Scorm12Runtime
{
    private bool $initialized = false;
    private bool $finished = false;
    private string $lastError = '0';
    private array $data;

    private const READ_ONLY = [
        'cmi._version',
        'cmi.core._children',
        'cmi.core.student_id',
        'cmi.core.student_name',
        'cmi.core.credit',
        'cmi.core.entry',
        'cmi.core.lesson_mode',
        'cmi.core.total_time',
        'cmi.launch_data',
        'cmi.student_data._children',
        'cmi.student_data.mastery_score',
        'cmi.student_data.max_time_allowed',
        'cmi.student_data.time_limit_action',
        'cmi.interactions._children',
        'cmi.interactions._count',
    ];

    private const WRITE_ONLY = [
        'cmi.core.exit',
        'cmi.core.session_time',
    ];

    private const ERROR_STRINGS = [
        '0' => 'No error',
        '101' => 'General exception',
        '201' => 'Invalid argument error',
        '301' => 'Not initialized',
        '401' => 'Not implemented error',
        '402' => 'Invalid set value',
        '403' => 'Element is read only',
        '404' => 'Element is write only',
        '405' => 'Incorrect data type',
    ];

    public function __construct(
        private readonly string $studentId,
        private readonly string $studentName,
        private readonly string $launchData = '',
        private readonly string $entry = 'ab-initio',
        private readonly string $lessonMode = 'normal',
        private readonly string $totalTime = '0000:00:00.00',
        array $savedData = [],
    ) {
        $this->data = array_merge([
            'cmi.core.lesson_location' => '',
            'cmi.core.lesson_status' => 'not attempted',
            'cmi.core.score.raw' => '',
            'cmi.core.score.max' => '',
            'cmi.core.score.min' => '',
            'cmi.core.exit' => '',
            'cmi.core.session_time' => '0000:00:00.00',
            'cmi.suspend_data' => '',
            'cmi.comments' => '',
            'cmi.student_preference.audio' => '0',
            'cmi.student_preference.language' => '',
            'cmi.student_preference.speed' => '0',
            'cmi.student_preference.text' => '0',
        ], $savedData);
    }

    public function initialize(string $parameter = ''): string
    {
        if ($parameter !== '' || $this->initialized || $this->finished) {
            return $this->fail('101');
        }
        $this->initialized = true;
        $this->lastError = '0';

        return 'true';
    }

    public function finish(string $parameter = ''): string
    {
        if ($parameter !== '' || !$this->initialized || $this->finished) {
            return $this->fail('301');
        }
        $this->finished = true;
        $this->lastError = '0';

        return 'true';
    }

    public function getValue(string $element): string
    {
        if (!$this->initialized || $this->finished) {
            return $this->failValue('301');
        }
        if (in_array($element, self::WRITE_ONLY, true)) {
            return $this->failValue('404');
        }

        $fixed = [
            'cmi._version' => '3.4',
            'cmi.core._children' => 'student_id,student_name,lesson_location,credit,lesson_status,entry,score,total_time,lesson_mode,exit,session_time',
            'cmi.core.student_id' => $this->studentId,
            'cmi.core.student_name' => $this->studentName,
            'cmi.core.credit' => 'credit',
            'cmi.core.entry' => $this->entry,
            'cmi.core.lesson_mode' => $this->lessonMode,
            'cmi.core.total_time' => $this->totalTime,
            'cmi.launch_data' => $this->launchData,
            'cmi.student_data._children' => 'mastery_score,max_time_allowed,time_limit_action',
            'cmi.interactions._children' => 'id,objectives,time,type,correct_responses,weighting,student_response,result,latency',
            'cmi.interactions._count' => (string) ($this->data['cmi.interactions._count'] ?? '0'),
        ];

        if (array_key_exists($element, $fixed)) {
            $this->lastError = '0';
            return (string) $fixed[$element];
        }
        if (in_array($element, self::READ_ONLY, true)) {
            $this->lastError = '0';
            return '';
        }
        if (!array_key_exists($element, $this->data) && !$this->isSupportedDynamicElement($element)) {
            return $this->failValue('201');
        }

        $this->lastError = '0';
        return (string) ($this->data[$element] ?? '');
    }

    public function setValue(string $element, string $value): string
    {
        if (!$this->initialized || $this->finished) {
            return $this->fail('301');
        }
        if (in_array($element, self::READ_ONLY, true)) {
            return $this->fail('403');
        }
        if (in_array($element, self::WRITE_ONLY, true) && $element !== 'cmi.core.exit' && $element !== 'cmi.core.session_time') {
            return $this->fail('404');
        }

        $valid = match ($element) {
            'cmi.core.lesson_location' => mb_strlen($value) <= 255,
            'cmi.core.lesson_status' => in_array($value, ['passed', 'completed', 'failed', 'incomplete', 'browsed', 'not attempted'], true),
            'cmi.core.score.raw', 'cmi.core.score.max', 'cmi.core.score.min' => $this->isValidScore($value),
            'cmi.core.exit' => in_array($value, ['time-out', 'suspend', 'logout', ''], true),
            'cmi.core.session_time' => $this->isValidScormTime($value),
            'cmi.suspend_data' => mb_strlen($value) <= 4096,
            'cmi.comments' => mb_strlen($value) <= 4096,
            'cmi.student_preference.audio', 'cmi.student_preference.text' => in_array($value, ['-1', '0', '1'], true),
            'cmi.student_preference.speed' => is_numeric($value) && (float) $value >= -100 && (float) $value <= 100,
            'cmi.student_preference.language' => mb_strlen($value) <= 255,
            default => $this->isSupportedDynamicElement($element),
        };

        if (!$valid) {
            return $this->fail('405');
        }

        $this->data[$element] = $value;
        $this->lastError = '0';

        return 'true';
    }

    public function commit(string $parameter = ''): string
    {
        if ($parameter !== '' || !$this->initialized || $this->finished) {
            return $this->fail('301');
        }
        $this->lastError = '0';

        return 'true';
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function getErrorString(string $code): string
    {
        return self::ERROR_STRINGS[$code] ?? 'Unknown error';
    }

    public function getDiagnostic(string $code): string
    {
        return $this->getErrorString($code);
    }

    public function exportData(): array
    {
        return $this->data;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    private function isValidScore(string $value): bool
    {
        return $value !== '' && is_numeric($value) && (float) $value >= 0 && (float) $value <= 100;
    }

    private function isValidScormTime(string $value): bool
    {
        return preg_match('/^\d{4}:[0-5]\d:[0-5]\d(?:\.\d{1,2})?$/', $value) === 1;
    }

    private function isSupportedDynamicElement(string $element): bool
    {
        return preg_match('/^cmi\.interactions\.\d+\.(?:id|type|time|weighting|student_response|result|latency|objectives\.\d+\.id|correct_responses\.\d+\.pattern)$/', $element) === 1
            && mb_strlen($element) <= 255;
    }

    private function fail(string $code): string
    {
        $this->lastError = $code;

        return 'false';
    }

    private function failValue(string $code): string
    {
        $this->lastError = $code;

        return '';
    }
}
