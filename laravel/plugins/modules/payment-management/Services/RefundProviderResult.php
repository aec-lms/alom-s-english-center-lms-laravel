<?php

declare(strict_types=1);

namespace Plugins\Modules\PaymentManagement\Services;

final class RefundProviderResult
{
    public const SUCCEEDED = 'succeeded';
    public const PENDING = 'pending';
    public const FAILED = 'failed';
    public const REQUIRES_REVIEW = 'requires_review';

    public function __construct(
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $providerStatus = null,
        public readonly array $metadata = [],
    ) {
        if (!in_array($status, [
            self::SUCCEEDED,
            self::PENDING,
            self::FAILED,
            self::REQUIRES_REVIEW,
        ], true)) {
            throw new \InvalidArgumentException('Unsupported normalized refund status.');
        }
    }
}
