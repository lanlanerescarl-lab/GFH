<?php

declare(strict_types=1);

namespace Gfh\Core\Payments;

/**
 * The seam for a future payment gateway. Today payments are only recorded: a person takes the money and
 * writes it down. A gateway provider would implement the same interface and add the charge step.
 */
interface PaymentProvider
{
    /** @return list<string> payment methods this provider accepts */
    public function methods(): array;

    /**
     * Checks a payment before it is recorded and returns the cleaned details.
     *
     * @param array<string, mixed> $details method, reference
     * @return array{method: string, reference: ?string}
     * @throws \Gfh\Core\Http\HttpException 422 with field errors
     */
    public function validate(array $details): array;
}
