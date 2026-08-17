<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Domain\Admin\AdminUser;
use App\Domain\Shared\Clock;
use App\Domain\Shared\Identifier;
use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Records who changed what in the admin panel.
 *
 * In a system that moves money, "the gateway credentials changed at some point"
 * is not an acceptable answer. Every mutating action writes one row here.
 */
final readonly class AuditLogger
{
    public function __construct(
        private PDO $pdo,
        private Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function record(
        ?AdminUser $actor,
        string $action,
        ?string $subject = null,
        array $context = [],
        ?string $ipAddress = null,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs (id, actor_id, actor_email, action, subject, context, ip_address, created_at)
             VALUES (:id, :actor_id, :actor_email, :action, :subject, :context, :ip_address, :created_at)',
        );

        $statement->execute([
            'id' => Uuid::uuid7()->toString(),
            'actor_id' => $actor?->id->value,
            'actor_email' => $actor?->email,
            'action' => $action,
            'subject' => $subject,
            'context' => json_encode($this->redact($context), JSON_THROW_ON_ERROR),
            'ip_address' => $ipAddress,
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT :limit',
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * The audit trail must never become the place secrets leak from.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function redact(array $context): array
    {
        $sensitive = ['password', 'secret', 'merchant_id', 'credentials', 'api_key', 'token'];

        foreach ($context as $key => $value) {
            foreach ($sensitive as $needle) {
                if (stripos($key, $needle) !== false) {
                    $context[$key] = '***redacted***';

                    continue 2;
                }
            }

            if ($value instanceof Identifier) {
                $context[$key] = $value->value;
            }
        }

        return $context;
    }
}
