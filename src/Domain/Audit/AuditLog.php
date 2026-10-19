<?php

declare(strict_types=1);

namespace Casamiza\Domain\Audit;

use Casamiza\Domain\User\User;
use Casamiza\Domain\User\UserRepositoryInterface;
use Casamiza\Shared\RequestContext;
use Casamiza\Shared\TenantContext;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Écrit le journal d'audit. Appelé **dans la transaction** de l'action quand elle écrit en base (si le journal échoue,
 * l'action est annulée : pas d'action sensible sans trace), après l'action sinon (e-mail envoyé, connexion refusée).
 */
final readonly class AuditLog implements AuditLogInterface
{
    public function __construct(
        private AuditLogRepositoryInterface $entries,
        private UserRepositoryInterface $users,
        private TenantContext $tenant,
        private RequestContext $request,
        private ClockInterface $clock,
    ) {}

    public function record(AuditAction $action, ?string $targetType = null, ?string $targetId = null, ?string $targetLabel = null, array $context = []): void
    {
        $actorId = $this->request->actorId();
        $actor = $actorId === null ? null : $this->users->find($actorId);

        $this->write(
            $this->tenant->id(),
            $actorId,
            $actor === null ? null : self::nameOf($actor),
            $action,
            $targetType,
            $targetId,
            $targetLabel,
            $context,
        );
    }

    public function recordAs(User $actor, AuditAction $action, ?string $targetType = null, ?string $targetId = null, ?string $targetLabel = null, array $context = []): void
    {
        $this->write($actor->tenantId, $actor->id, self::nameOf($actor), $action, $targetType, $targetId, $targetLabel, $context);
    }

    public function recordSystem(string $tenantId, ?string $label, AuditAction $action, ?string $targetType = null, ?string $targetId = null, ?string $targetLabel = null, array $context = []): void
    {
        $this->write($tenantId, null, $label, $action, $targetType, $targetId, $targetLabel, $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $tenantId, ?string $actorId, ?string $actorLabel, AuditAction $action, ?string $targetType, ?string $targetId, ?string $targetLabel, array $context): void
    {
        $this->entries->add(new AuditEntry(
            Uuid::uuid7()->toString(),
            $tenantId,
            $this->clock->now(),
            $action,
            $actorId,
            $actorLabel === null ? null : mb_substr($actorLabel, 0, 200),
            $targetType,
            $targetId,
            $targetLabel === null ? null : mb_substr($targetLabel, 0, 200),
            $this->request->ip(),
            $this->request->requestId(),
            $context,
        ));
    }

    private static function nameOf(User $user): string
    {
        return trim($user->firstName . ' ' . $user->lastName);
    }
}
