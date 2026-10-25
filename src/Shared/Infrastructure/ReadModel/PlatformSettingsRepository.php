<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * I-16 / O-19 — one singleton row holds the platform parameters an operator may change without a
 * deploy: contact channels, the pending-order timeout and the payment method allow-list. The row is
 * seeded by migrations 20261022000004 and 20261022000006; the fallbacks keep reads total if the row
 * is ever removed out of band. Read-only per QRY-001: writes go through UpdatePlatformSettingsHandler.
 */
final class PlatformSettingsRepository
{
    /** Kept in sync with the migration default so an unseeded row behaves like a fresh install. */
    public const array DEFAULT_PAYMENT_METHODS = [
        'Carte bancaire',
        'Virement / Paiement à la livraison',
        'Espèces à la livraison',
    ];

    public function __construct(private Connection $connection)
    {
    }

    /** @return array{contactPhone: string, contactWhatsapp: string, autoCancelMinutes: int, paymentMethods: list<string>} */
    public function get(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT contact_phone, contact_whatsapp, auto_cancel_minutes, payment_methods FROM platform_setting WHERE id = 1',
        );
        if (false === $row) {
            return [
                'contactPhone' => '',
                'contactWhatsapp' => '',
                'autoCancelMinutes' => 30,
                'paymentMethods' => self::DEFAULT_PAYMENT_METHODS,
            ];
        }

        return [
            'contactPhone' => (string) $row['contact_phone'],
            'contactWhatsapp' => (string) $row['contact_whatsapp'],
            'autoCancelMinutes' => (int) $row['auto_cancel_minutes'],
            'paymentMethods' => self::decodeMethods($row['payment_methods']),
        ];
    }

    /** @return list<string> */
    public function paymentMethods(): array
    {
        return $this->get()['paymentMethods'];
    }

    /** @return list<string> */
    private static function decodeMethods(mixed $raw): array
    {
        if (!is_string($raw) || '' === $raw) {
            return self::DEFAULT_PAYMENT_METHODS;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::DEFAULT_PAYMENT_METHODS;
        }

        $methods = array_values(array_filter($decoded, 'is_string'));

        return [] === $methods ? self::DEFAULT_PAYMENT_METHODS : $methods;
    }
}
