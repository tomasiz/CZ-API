<?php

declare(strict_types=1);

namespace Casamiza\Domain\Company;

use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\Exception\ValidationException;
use Casamiza\Shared\TenantContext;

/** Identité de la conciergerie du tenant courant : mentions légales, contact, modèles d'e-mail et logo. */
final readonly class CompanyService
{
    /** Taille maximale d'un logo (le logo actuel de Casamea pèse ~150 Ko). */
    public const LOGO_MAX_BYTES = 1_048_576;

    /** Côté maximal d'un logo, en pixels : au-delà, le PDF serait inutilement lourd. */
    public const LOGO_MAX_SIDE = 4000;

    public function __construct(
        private CompanySettingsRepositoryInterface $settings,
        private LogoStorageInterface $logos,
        private TenantContext $tenant,
    ) {}

    /** Les réglages du tenant, ou des réglages vides s'il n'a encore rien saisi. */
    public function get(): CompanySettings
    {
        return $this->settings->find() ?? CompanySettings::blank();
    }

    public function update(CompanySettingsPatch $patch): CompanySettings
    {
        $settings = $this->get()->patched($patch);
        $this->settings->save($settings);

        return $settings;
    }

    /**
     * Remplace le logo. Seul le PNG est accepté (c'est le format des PDF), vérifié sur le contenu et non sur le nom.
     *
     * @throws ValidationException `logo` : `too_large`, `invalid_image`
     */
    public function replaceLogo(string $png): CompanySettings
    {
        if (strlen($png) > self::LOGO_MAX_BYTES) {
            throw new ValidationException(['logo' => ['too_large']]);
        }
        $info = $png === '' ? false : @getimagesizefromstring($png);
        if ($info === false || $info[2] !== IMAGETYPE_PNG) {
            throw new ValidationException(['logo' => ['invalid_image']]);
        }
        if ($info[0] > self::LOGO_MAX_SIDE || $info[1] > self::LOGO_MAX_SIDE) {
            throw new ValidationException(['logo' => ['too_large']]);
        }

        $current = $this->get();
        $path = $this->logos->put($this->tenant->id(), $png);
        $updated = $current->withLogoPath($path);
        $this->settings->save($updated);
        if ($current->logoPath !== null && $current->logoPath !== $path) {
            $this->logos->delete($current->logoPath);
        }

        return $updated;
    }

    /** @throws NotFoundException `logo_not_found` */
    public function logo(): string
    {
        $path = $this->get()->logoPath;

        return ($path === null || $path === '' ? null : $this->logos->read($path)) ?? throw new NotFoundException('logo_not_found');
    }

    public function removeLogo(): CompanySettings
    {
        $current = $this->get();
        if ($current->logoPath === null || $current->logoPath === '') {
            return $current;
        }
        $updated = $current->withLogoPath(null);
        $this->settings->save($updated);
        $this->logos->delete($current->logoPath);

        return $updated;
    }
}
