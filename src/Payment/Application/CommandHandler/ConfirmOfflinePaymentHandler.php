<?php

declare(strict_types=1);

namespace App\Payment\Application\CommandHandler;

use App\Payment\Application\Command\ConfirmOfflinePayment;
use App\Payment\Domain\Event\PaymentAuthorized;
use App\Payment\Domain\Model\PaymentLedger;
use App\Payment\Domain\Model\PaymentNotFoundException;
use App\Payment\Domain\Model\PaymentStatus;
use App\Payment\Domain\Model\PaymentTransition;
use App\Payment\Domain\Model\PaymentVersionConflictException;
use App\Shared\Application\Port\OrderSettlementPort;
use App\Shared\Domain\Outbox\OutboxPort;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final class ConfirmOfflinePaymentHandler
{
    public function __construct(
        private Connection $connection,
        private PaymentLedger $ledger,
        private OrderSettlementPort $orders,
        private OutboxPort $outbox,
    ) {
    }

    public function __invoke(ConfirmOfflinePayment $command): void
    {
        $payment = $this->connection->fetchAssociative(
            'SELECT order_id, status, version, amount_minor, currency FROM payment WHERE id = :id',
            ['id' => $command->paymentId],
        );

        if (false === $payment) {
            throw PaymentNotFoundException::forId($command->paymentId);
        }

        // AGG-001 — the payment validates its own transition before anything is written.
        $currentVersion = (int) $payment['version'];
        if ($currentVersion !== $command->expectedVersion) {
            throw PaymentVersionConflictException::versionMismatch($command->paymentId, $command->expectedVersion, $currentVersion);
        }

        $currentStatus = PaymentStatus::from((string) $payment['status']);
        $currentStatus->ensureCanTransitionTo(PaymentStatus::AUTHORIZED);

        $orderId = (string) $payment['order_id'];

        $this->orders->markPaid($orderId, $command->actorId, 'Offline payment confirmed');

        $this->ledger->record(new PaymentTransition(
            $command->paymentId,
            $currentVersion,
            PaymentStatus::PENDING,
            PaymentStatus::AUTHORIZED,
            (int) $payment['amount_minor'],
            $command->actorId,
            'Offline receipt confirmed',
            $command->reference,
        ));

        // ADR-0001 — PaymentAuthorized is the Payment → Ordering signal; the outbox keeps the
        // dispatch at-least-once and ordered with the state it describes (EVT-002).
        $this->outbox->append('payment', $command->paymentId, [
            new PaymentAuthorized(
                Uuid::v7()->toRfc4122(),
                $command->paymentId,
                $orderId,
                (int) $payment['amount_minor'],
                (string) $payment['currency'],
                new \DateTimeImmutable(),
            ),
        ]);
    }
}
