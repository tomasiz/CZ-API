<?php

declare(strict_types=1);

namespace Casamiza\Domain\Audit;

use Casamiza\Domain\User\User;

/**
 * Ce que les services appellent pour tracer une action sensible. Le `context` ne reçoit que des identifiants et des codes :
 * jamais de secret, de jeton, de mot de passe ni de donnée voyageur.
 */
interface AuditLogInterface
{
    /**
     * Action de l'utilisateur authentifié de la requête (tenant, acteur, IP et identifiant de requête viennent du contexte).
     *
     * @param array<string, mixed> $context
     */
    public function record(AuditAction $action, ?string $targetType = null, ?string $targetId = null, ?string $targetLabel = null, array $context = []): void;

    /**
     * Action d'un utilisateur connu mais pas (encore) authentifié par jeton : connexion, déconnexion, réinitialisation.
     *
     * @param array<string, mixed> $context
     */
    public function recordAs(User $actor, AuditAction $action, ?string $targetType = null, ?string $targetId = null, ?string $targetLabel = null, array $context = []): void;

    /**
     * Action sans utilisateur identifié : commande en ligne de commande (`$label` = « cli »), tentative anonyme (`null`).
     *
     * @param array<string, mixed> $context
     */
    public function recordSystem(string $tenantId, ?string $label, AuditAction $action, ?string $targetType = null, ?string $targetId = null, ?string $targetLabel = null, array $context = []): void;
}
