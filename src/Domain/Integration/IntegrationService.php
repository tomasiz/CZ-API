<?php

declare(strict_types=1);

namespace Casamiza\Domain\Integration;

use Casamiza\Domain\Audit\AuditAction;
use Casamiza\Domain\Audit\AuditLogInterface;
use Casamiza\Infrastructure\Security\Encryptor;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\Exception\ValidationException;
use Casamiza\Shared\Transaction;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Configuration et contrôle des services tiers du tenant courant. Indépendant du service concerné :
 * tout ce qui lui est propre (champs à saisir, appel de vérification) vit dans son IntegrationProviderInterface.
 */
final readonly class IntegrationService
{
    public function __construct(
        private ProviderRegistry $providers,
        private IntegrationRepositoryInterface $integrations,
        private Encryptor $encryptor,
        private Transaction $transaction,
        private ClockInterface $clock,
        private AuditLogInterface $audit,
    ) {}

    /** @return list<IntegrationEntry> tous les services pris en charge, configurés ou non (aucun appel au tiers) */
    public function list(): array
    {
        $byProvider = [];
        foreach ($this->integrations->all() as $integration) {
            $byProvider[$integration->provider->value] = $integration;
        }

        return array_map(
            static fn(IntegrationProviderInterface $provider): IntegrationEntry => new IntegrationEntry($provider, $byProvider[$provider->provider()->value] ?? null),
            $this->providers->all(),
        );
    }

    /**
     * Teste puis enregistre : si le tiers refuse l'accès, rien n'est écrit. Un secret laissé vide reste inchangé.
     *
     * @param array<string, string> $input valeurs saisies (clés = champs du service)
     * @throws ValidationException champ manquant
     * @throws \Casamiza\Shared\Exception\ApiException l'accès au tiers a échoué (voir CheckFailure)
     */
    public function save(string $name, array $input): IntegrationEntry
    {
        $provider = $this->providers->get($name);

        return $this->transaction->run(function () use ($provider, $input): IntegrationEntry {
            $existing = $this->integrations->find($provider->provider(), true);
            $previousSecrets = $existing === null ? [] : $this->secretsOf($existing);

            $secrets = [];
            $settings = [];
            $errors = [];
            foreach ($provider->fields() as $field) {
                $value = $input[$field->key] ?? '';
                if ($value === '' && $field->secret) {
                    $value = $previousSecrets[$field->key] ?? '';
                }
                if ($value === '') {
                    $errors[$field->key] = ['required'];
                } elseif ($field->secret) {
                    $secrets[$field->key] = $value;
                } else {
                    $settings[$field->key] = $value;
                }
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }

            // Le jeton en cache appartient à ces identifiants : changés, il ne vaut plus rien.
            $cached = $existing !== null && $secrets === $previousSecrets && $settings === $existing->settings
                ? $this->cachedTokenOf($existing)
                : null;
            $result = $provider->check($secrets + $settings, $cached);
            if ($result->failure !== null) {
                throw $result->failure->toException();
            }

            $now = $this->clock->now();
            $integration = $this->build(
                id: $existing->id ?? Uuid::uuid7()->toString(),
                provider: $provider->provider(),
                secrets: $secrets,
                settings: $settings,
                result: $result,
                now: $now,
                configuredAt: $existing->configuredAt ?? $now,
            );
            $this->integrations->save($integration);
            // Seulement « les secrets ont-ils changé », jamais leur valeur.
            $this->audit->record(AuditAction::IntegrationSaved, 'integration', $provider->provider()->value, null, [
                'provider' => $provider->provider()->value,
                'created' => $existing === null,
                'secretsChanged' => $secrets !== $previousSecrets,
            ]);

            return new IntegrationEntry($provider, $integration);
        });
    }

    /**
     * Contrôle en direct de l'accès au tiers ; le résultat (connecté ou en erreur) est enregistré et renvoyé.
     * Le verrou sur la ligne évite que deux contrôles simultanés demandent chacun un nouveau jeton.
     *
     * @throws NotFoundException `integration_not_configured`
     */
    public function check(string $name): IntegrationEntry
    {
        return $this->verify($name, renew: false)[0];
    }

    /**
     * Jeton d'accès valable du service pour appeler son API (jeton en cache, ou nouveau s'il est périmé).
     * `$renew` écarte le jeton en cache : à utiliser quand le tiers vient de le refuser (401).
     *
     * @throws NotFoundException `integration_not_configured`
     * @throws \Casamiza\Shared\Exception\ApiException l'accès au tiers a échoué (voir CheckFailure)
     */
    public function accessToken(string $name, bool $renew = false): string
    {
        $result = $this->verify($name, $renew)[1];
        if ($result->failure !== null) {
            throw $result->failure->toException();
        }

        return $result->token->value ?? throw CheckFailure::Unreachable->toException();
    }

    /**
     * Identifiants et réglages enregistrés du service (secrets déchiffrés), pour appeler son API. Ne contacte pas le tiers
     * et ne modifie rien : à réserver aux clients d'API, jamais à une réponse HTTP.
     *
     * @return array<string, string>
     * @throws NotFoundException `integration_not_configured`
     */
    public function values(string $name): array
    {
        $provider = $this->providers->get($name);
        $existing = $this->integrations->find($provider->provider()) ?? throw new NotFoundException('integration_not_configured');

        return $this->secretsOf($existing) + $existing->settings;
    }

    /** @return array{IntegrationEntry, CheckResult} */
    private function verify(string $name, bool $renew): array
    {
        $provider = $this->providers->get($name);

        return $this->transaction->run(function () use ($provider, $renew): array {
            $existing = $this->integrations->find($provider->provider(), true) ?? throw new NotFoundException('integration_not_configured');
            $secrets = $this->secretsOf($existing);

            $result = $provider->check($secrets + $existing->settings, $renew ? null : $this->cachedTokenOf($existing));

            $integration = $this->build(
                id: $existing->id,
                provider: $existing->provider,
                secrets: $secrets,
                settings: $existing->settings,
                result: $result,
                now: $this->clock->now(),
                configuredAt: $existing->configuredAt,
            );
            $this->integrations->save($integration);

            return [new IntegrationEntry($provider, $integration), $result];
        });
    }

    public function remove(string $name): void
    {
        $provider = $this->providers->get($name)->provider();
        $this->transaction->run(function () use ($provider): void {
            $this->integrations->delete($provider);
            $this->audit->record(AuditAction::IntegrationRemoved, 'integration', $provider->value, null, ['provider' => $provider->value]);
        });
    }

    /**
     * @param array<string, string> $secrets
     * @param array<string, string> $settings
     */
    private function build(
        string $id,
        Provider $provider,
        array $secrets,
        array $settings,
        CheckResult $result,
        DateTimeImmutable $now,
        DateTimeImmutable $configuredAt,
    ): Integration {
        return new Integration(
            id: $id,
            provider: $provider,
            credentialsEncrypted: $this->encryptor->encrypt(json_encode($secrets, JSON_THROW_ON_ERROR)),
            settings: $settings,
            tokenEncrypted: $result->token === null ? null : $this->encryptor->encrypt($result->token->value),
            tokenExpiresAt: $result->token?->expiresAt,
            status: $result->isConnected() ? IntegrationStatus::Connected : IntegrationStatus::Error,
            lastErrorCode: $result->failure?->value,
            lastCheckedAt: $now,
            configuredAt: $configuredAt,
        );
    }

    /** @return array<string, string> */
    private function secretsOf(Integration $integration): array
    {
        $decoded = json_decode($this->encryptor->decrypt($integration->credentialsEncrypted), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }

        return array_map(strval(...), $decoded);
    }

    private function cachedTokenOf(Integration $integration): ?AccessToken
    {
        if ($integration->tokenEncrypted === null || $integration->tokenExpiresAt === null) {
            return null;
        }

        return new AccessToken($this->encryptor->decrypt($integration->tokenEncrypted), $integration->tokenExpiresAt);
    }
}
