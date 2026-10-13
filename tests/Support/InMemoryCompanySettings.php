<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Company\CompanySettings;
use Casamiza\Domain\Company\CompanySettingsRepositoryInterface;
use Casamiza\Shared\TenantContext;

/** Réglages de l'entreprise en mémoire, bornés au tenant courant comme en base. */
final class InMemoryCompanySettings implements CompanySettingsRepositoryInterface
{
    /** @var array<string, CompanySettings> par tenant */
    public array $rows = [];

    public function __construct(private readonly TenantContext $context) {}

    public function find(): ?CompanySettings
    {
        return $this->rows[$this->context->id()] ?? null;
    }

    public function save(CompanySettings $settings): void
    {
        $this->rows[$this->context->id()] = $settings;
    }
}
