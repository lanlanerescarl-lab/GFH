<?php

declare(strict_types=1);

namespace Gfh\Core\Payments;

use Gfh\Core\Http\HttpException;

/** Record-only payments: cash, GCash, Maya, card, bank transfer and cash on delivery. No money moves through the system. */
final class ManualPaymentProvider implements PaymentProvider
{
    private const METHODS = ['cash', 'gcash', 'maya', 'card', 'bank_transfer', 'cod'];
    /** These need a reference number so the payment can be traced later. */
    private const NEEDS_REFERENCE = ['gcash', 'maya', 'card', 'bank_transfer'];

    public function methods(): array
    {
        return self::METHODS;
    }

    public function validate(array $details): array
    {
        $method = (string) ($details['method'] ?? '');
        if (!in_array($method, self::METHODS, true)) {
            throw HttpException::unprocessable(['method' => 'Choose cash, GCash, Maya, card, bank transfer or cash on delivery.']);
        }

        $reference = isset($details['reference']) ? trim((string) $details['reference']) : '';
        if ($reference !== '' && (mb_strlen($reference) > 80 || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1)) {
            throw HttpException::unprocessable(['reference' => 'Use a reference of up to 80 characters.']);
        }
        if ($reference === '' && in_array($method, self::NEEDS_REFERENCE, true)) {
            throw HttpException::unprocessable(['reference' => 'Enter the reference number for this payment.']);
        }

        return ['method' => $method, 'reference' => $reference === '' ? null : $reference];
    }
}
