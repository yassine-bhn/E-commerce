<?php

declare(strict_types=1);

namespace App\Payment\Application\CommandHandler;

use App\Payment\Application\Command\ConfirmOfflinePayment;
use App\Payment\Application\Port\PaymentStateReader;
use App\Payment\Domain\Event\PaymentAuthorized;
use App\Payment\Domain\Model\PaymentLedger;
use App\Payment\Domain\Model\PaymentNotFoundException;
use App\Payment\Domain\Model\PaymentStatus;
use App\Payment\Domain\Model\PaymentTransition;
use App\Payment\Domain\Model\PaymentVersionConflictException;
use App\Shared\Application\Port\OrderSettlementPort;
use App\Shared\Domain\Outbox\OutboxPort;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final class ConfirmOfflinePaymentHandler
{
    public function __construct(
        private PaymentStateReader $payments,
        private PaymentLedger $ledger,
        private OrderSettlementPort $orders,
        private OutboxPort $outbox,
    ) {
    }

    public function __invoke(ConfirmOfflinePayment $command): void
    {
        $payment = $this->payments->find($command->paymentId);

        if (null === $payment) {
            throw PaymentNotFoundException::forId($command->paymentId);
        }

        // AGG-001 — the payment validates its own transition before anything is written.
        if ($payment->version !== $command->expectedVersion) {
            throw PaymentVersionConflictException::versionMismatch($command->paymentId, $command->expectedVersion, $payment->version);
        }

        $payment->status->ensureCanTransitionTo(PaymentStatus::AUTHORIZED);

        $orderId = $payment->orderId;

        $this->orders->markPaid($orderId, $command->actorId, 'Offline payment confirmed');

        $this->ledger->record(new PaymentTransition(
            $command->paymentId,
            $payment->version,
            PaymentStatus::PENDING,
            PaymentStatus::AUTHORIZED,
            $payment->amountMinor,
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
                $payment->amountMinor,
                $payment->currency,
                new \DateTimeImmutable(),
            ),
        ]);
    }
}
