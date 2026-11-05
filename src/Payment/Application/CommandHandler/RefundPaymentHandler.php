<?php

declare(strict_types=1);

namespace App\Payment\Application\CommandHandler;

use App\Payment\Application\Command\RefundPayment;
use App\Payment\Application\Port\PaymentStateReader;
use App\Payment\Domain\Event\PaymentRefunded;
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
final class RefundPaymentHandler
{
    public function __construct(
        private PaymentStateReader $payments,
        private PaymentLedger $ledger,
        private OrderSettlementPort $orders,
        private OutboxPort $outbox,
    ) {
    }

    public function __invoke(RefundPayment $command): void
    {
        $payment = $this->payments->find($command->paymentId);

        if (null === $payment) {
            throw PaymentNotFoundException::forId($command->paymentId);
        }

        if ($payment->version !== $command->expectedVersion) {
            throw PaymentVersionConflictException::versionMismatch($command->paymentId, $command->expectedVersion, $payment->version);
        }

        // AGG-001 — only an authorized payment can be refunded, and only once.
        $payment->status->ensureCanTransitionTo(PaymentStatus::REFUNDED);

        $orderId = $payment->orderId;

        $this->orders->markRefunded($orderId, $command->actorId, $command->reason);

        $this->ledger->record(new PaymentTransition(
            $command->paymentId,
            $payment->version,
            PaymentStatus::AUTHORIZED,
            PaymentStatus::REFUNDED,
            $payment->amountMinor,
            $command->actorId,
            $command->reason,
        ));

        $this->outbox->append('payment', $command->paymentId, [
            new PaymentRefunded(
                Uuid::v7()->toRfc4122(),
                $command->paymentId,
                $orderId,
                $payment->amountMinor,
                $payment->currency,
                $command->reason,
                new \DateTimeImmutable(),
            ),
        ]);
    }
}
