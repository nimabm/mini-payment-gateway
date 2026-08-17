<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Gateway\GatewayId;
use App\Domain\Merchant\MerchantId;
use App\Domain\Payment\AttemptStatus;
use App\Domain\Payment\Payer;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentAttempt;
use App\Domain\Payment\PaymentAttemptId;
use App\Domain\Payment\PaymentId;
use App\Domain\Payment\PaymentRepository;
use App\Domain\Payment\PaymentStatus;
use App\Domain\Shared\Currency;
use App\Domain\Shared\Money;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Stores the Payment aggregate and its attempts.
 *
 * The aggregate is saved as a unit inside one transaction: a payment that is
 * Paid while its winning attempt is missing would be unauditable, and worse,
 * unexplainable to whoever is asking where their money went.
 */
final readonly class SqlitePaymentRepository implements PaymentRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function save(Payment $payment): void
    {
        $ownsTransaction = !$this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->upsertPayment($payment);

            foreach ($payment->attempts() as $attempt) {
                $this->upsertAttempt($attempt);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function find(PaymentId $id): ?Payment
    {
        return $this->findOneWhere('p.id = :value', ['value' => $id->value]);
    }

    public function findByOrderId(MerchantId $merchantId, string $orderId): ?Payment
    {
        return $this->findOneWhere(
            'p.merchant_id = :merchant_id AND p.order_id = :order_id',
            ['merchant_id' => $merchantId->value, 'order_id' => $orderId],
        );
    }

    public function findByIdempotencyKey(MerchantId $merchantId, string $key): ?Payment
    {
        return $this->findOneWhere(
            'p.merchant_id = :merchant_id AND p.idempotency_key = :key',
            ['merchant_id' => $merchantId->value, 'key' => $key],
        );
    }

    public function findAwaitingVerification(DateTimeImmutable $olderThan, int $limit = 100): array
    {
        $statement = Rows::execute(
            $this->pdo,
            'SELECT id FROM payments
             WHERE status = :status AND updated_at <= :cutoff
             ORDER BY updated_at
             LIMIT :limit',
            [
                'status' => PaymentStatus::AwaitingVerification->value,
                'cutoff' => $olderThan->format('Y-m-d H:i:s'),
                'limit' => $limit,
            ],
        );

        return $this->loadMany(Rows::fromStatement($statement));
    }

    public function findExpired(DateTimeImmutable $now, int $limit = 100): array
    {
        // AwaitingVerification is deliberately excluded: the payer may already
        // have been charged, so those belong to reconciliation, not expiry.
        $statement = Rows::execute(
            $this->pdo,
            'SELECT id FROM payments
             WHERE status IN (:created, :pending) AND expires_at < :now
             ORDER BY expires_at
             LIMIT :limit',
            [
                'created' => PaymentStatus::Created->value,
                'pending' => PaymentStatus::Pending->value,
                'now' => $now->format('Y-m-d H:i:s'),
                'limit' => $limit,
            ],
        );

        return $this->loadMany(Rows::fromStatement($statement));
    }

    private function upsertPayment(Payment $payment): void
    {
        Rows::execute(
            $this->pdo,
            'INSERT INTO payments (
                id, merchant_id, order_id, amount, currency, refunded_amount, status,
                description, callback_url, payer_name, payer_email, payer_mobile,
                idempotency_key, preferred_gateway_id, failure_reason,
                created_at, updated_at, expires_at, paid_at
             ) VALUES (
                :id, :merchant_id, :order_id, :amount, :currency, :refunded_amount, :status,
                :description, :callback_url, :payer_name, :payer_email, :payer_mobile,
                :idempotency_key, :preferred_gateway_id, :failure_reason,
                :created_at, :updated_at, :expires_at, :paid_at
             )
             ON CONFLICT (id) DO UPDATE SET
                status = excluded.status,
                refunded_amount = excluded.refunded_amount,
                failure_reason = excluded.failure_reason,
                updated_at = excluded.updated_at,
                paid_at = excluded.paid_at',
            [
                'id' => $payment->id->value,
                'merchant_id' => $payment->merchantId->value,
                'order_id' => $payment->orderId,
                'amount' => $payment->amount->amount,
                'currency' => $payment->amount->currency->value,
                'refunded_amount' => $payment->refundedAmount()->amount,
                'status' => $payment->status()->value,
                'description' => $payment->description,
                'callback_url' => $payment->callbackUrl,
                'payer_name' => $payment->payer->name,
                'payer_email' => $payment->payer->email,
                'payer_mobile' => $payment->payer->mobile,
                'idempotency_key' => $payment->idempotencyKey,
                'preferred_gateway_id' => $payment->preferredGatewayId()?->value,
                'failure_reason' => $payment->failureReason(),
                'created_at' => $payment->createdAt->format('Y-m-d H:i:s'),
                'updated_at' => $payment->updatedAt()?->format('Y-m-d H:i:s'),
                'expires_at' => $payment->expiresAt->format('Y-m-d H:i:s'),
                'paid_at' => $payment->paidAt()?->format('Y-m-d H:i:s'),
            ],
        );
    }

    private function upsertAttempt(PaymentAttempt $attempt): void
    {
        Rows::execute(
            $this->pdo,
            'INSERT INTO payment_attempts (
                id, payment_id, gateway_id, sequence, status, reference, transaction_id,
                card_pan, fee, failure_code, failure_message, request_payload,
                response_payload, created_at, completed_at
             ) VALUES (
                :id, :payment_id, :gateway_id, :sequence, :status, :reference, :transaction_id,
                :card_pan, :fee, :failure_code, :failure_message, :request_payload,
                :response_payload, :created_at, :completed_at
             )
             ON CONFLICT (id) DO UPDATE SET
                status = excluded.status,
                transaction_id = excluded.transaction_id,
                card_pan = excluded.card_pan,
                fee = excluded.fee,
                failure_code = excluded.failure_code,
                failure_message = excluded.failure_message,
                response_payload = excluded.response_payload,
                completed_at = excluded.completed_at',
            [
                'id' => $attempt->id->value,
                'payment_id' => $attempt->paymentId->value,
                'gateway_id' => $attempt->gatewayId->value,
                'sequence' => $attempt->sequence,
                'status' => $attempt->status()->value,
                'reference' => $attempt->reference(),
                'transaction_id' => $attempt->transactionId(),
                'card_pan' => $attempt->cardPan(),
                'fee' => $attempt->fee(),
                'failure_code' => $attempt->failureCode(),
                'failure_message' => $attempt->failureMessage(),
                'request_payload' => json_encode($attempt->requestPayload(), JSON_THROW_ON_ERROR),
                'response_payload' => json_encode($attempt->responsePayload(), JSON_THROW_ON_ERROR),
                'created_at' => $attempt->createdAt->format('Y-m-d H:i:s'),
                'completed_at' => $attempt->completedAt()?->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function findOneWhere(string $where, array $parameters): ?Payment
    {
        $row = Rows::one(
            $this->pdo,
            sprintf('SELECT p.* FROM payments p WHERE %s LIMIT 1', $where),
            $parameters,
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @param list<Row> $rows
     * @return list<Payment>
     */
    private function loadMany(array $rows): array
    {
        $payments = [];

        foreach ($rows as $row) {
            $payment = $this->find(PaymentId::fromString($row->string('id')));

            if ($payment !== null) {
                $payments[] = $payment;
            }
        }

        return $payments;
    }

    private function hydrate(Row $row): Payment
    {
        $currency = Currency::from($row->string('currency'));
        $paymentId = PaymentId::fromString($row->string('id'));
        $preferredGateway = $row->nullableString('preferred_gateway_id');

        return new Payment(
            id: $paymentId,
            merchantId: MerchantId::fromString($row->string('merchant_id')),
            orderId: $row->string('order_id'),
            amount: Money::of($row->int('amount'), $currency),
            description: $row->nullableString('description'),
            callbackUrl: $row->string('callback_url'),
            payer: new Payer(
                $row->nullableString('payer_name'),
                $row->nullableString('payer_email'),
                $row->nullableString('payer_mobile'),
            ),
            idempotencyKey: $row->nullableString('idempotency_key'),
            status: PaymentStatus::from($row->string('status')),
            refundedAmount: Money::of($row->int('refunded_amount'), $currency),
            preferredGatewayId: $preferredGateway === null ? null : GatewayId::fromString($preferredGateway),
            failureReason: $row->nullableString('failure_reason'),
            createdAt: $row->date('created_at'),
            expiresAt: $row->date('expires_at'),
            paidAt: $row->nullableDate('paid_at'),
            updatedAt: $row->nullableDate('updated_at'),
            attempts: $this->loadAttempts($paymentId),
        );
    }

    /**
     * @return list<PaymentAttempt>
     */
    private function loadAttempts(PaymentId $paymentId): array
    {
        return array_map($this->hydrateAttempt(...), Rows::all(
            $this->pdo,
            'SELECT * FROM payment_attempts WHERE payment_id = :payment_id ORDER BY sequence',
            ['payment_id' => $paymentId->value],
        ));
    }

    private function hydrateAttempt(Row $row): PaymentAttempt
    {
        /** @var array<string, mixed> $request */
        $request = $row->json('request_payload');
        /** @var array<string, mixed> $response */
        $response = $row->json('response_payload');

        return new PaymentAttempt(
            PaymentAttemptId::fromString($row->string('id')),
            PaymentId::fromString($row->string('payment_id')),
            GatewayId::fromString($row->string('gateway_id')),
            $row->int('sequence'),
            AttemptStatus::from($row->string('status')),
            $row->nullableString('reference'),
            $row->nullableString('transaction_id'),
            $row->nullableString('card_pan'),
            $row->nullableInt('fee'),
            $row->nullableString('failure_code'),
            $row->nullableString('failure_message'),
            $request,
            $response,
            $row->date('created_at'),
            $row->nullableDate('completed_at'),
        );
    }
}
