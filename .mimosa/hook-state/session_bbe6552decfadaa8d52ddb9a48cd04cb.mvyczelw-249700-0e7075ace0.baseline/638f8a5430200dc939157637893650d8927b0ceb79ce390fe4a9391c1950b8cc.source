<?php

declare(strict_types=1);

namespace App\Payment\Application\CommandHandler;

use App\Payment\Application\Command\RefundPayment;
use App\Payment\Domain\Event\PaymentRefunded;
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
final class RefundPaymentHandler
{
    public function __construct(
        private Connection $connection,
        private PaymentLedger $ledger,
        private OrderSettlementPort $orders,
        private OutboxPort $outbox,
    ) {
    }

    public function __invoke(RefundPayment $command): void
    {
        $payment = $this->connection->fetchAssociative(
            'SELECT order_id, status, version, amount_minor, currency FROM payment WHERE id = :id',
            ['id' => $command->paymentId],
        );

        if (false === $payment) {
            throw PaymentNotFoundException::forId($command->paymentId);
        }

        $currentVersion = (int) $payment['version'];
        if ($currentVersion !== $command->expectedVersion) {
            throw PaymentVersionConflictException::versionMismatch($command->paymentId, $command->expectedVersion, $currentVersion);
        }

        // AGG-001 — only an authorized payment can be refunded, and only once.
        $currentStatus = PaymentStatus::from((string) $payment['status']);
        $currentStatus->ensureCanTransitionTo(PaymentStatus::REFUNDED);

        $orderId = (string) $payment['order_id'];

        $this->orders->markRefunded($orderId, $command->actorId, $command->reason);

        $this->ledger->record(new PaymentTransition(
            $command->paymentId,
            $currentVersion,
            PaymentStatus::AUTHORIZED,
            PaymentStatus::REFUNDED,
            (int) $payment['amount_minor'],
            $command->actorId,
            $command->reason,
        ));

        $this->outbox->append('payment', $command->paymentId, [
            new PaymentRefunded(
                Uuid::v7()->toRfc4122(),
                $command->paymentId,
                $orderId,
                (int) $payment['amount_minor'],
                (string) $payment['currency'],
                $command->reason,
                new \DateTimeImmutable(),
            ),
        ]);
    }
}
