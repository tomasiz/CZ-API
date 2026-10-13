<?php

declare(strict_types=1);

namespace Casamiza\Domain\Extra;

/**
 * Prestation supplémentaire du mois d'un propriétaire (ménage, linge, conciergerie), ajoutée à sa facture.
 * Le prix est saisi TTC ; le HT en découle (`TTC / (1 + TVA / 100)`, gardé exact à 6 décimales, jamais arrondi à 2 avant
 * le total de la facture) et est **stocké** avec le TTC et le taux : un montant facturé ne dépend pas d'une formule du code.
 */
final readonly class Extra
{
    /** Décimales conservées pour le HT. */
    public const HT_SCALE = 6;

    /** @param non-empty-string $id */
    public function __construct(
        public string $id,
        public string $ownerId,
        public string $month,
        public ExtraType $type,
        public string $description,
        public float $priceTtc,
        public float $vatRate,
        public float $priceHt,
    ) {}

    /**
     * Nouvelle prestation : le HT est calculé ici, à partir du TTC saisi et du taux.
     *
     * @param non-empty-string $id
     */
    public static function priced(string $id, string $ownerId, string $month, ExtraType $type, string $description, float $priceTtc, float $vatRate): self
    {
        return new self($id, $ownerId, $month, $type, $description, $priceTtc, $vatRate, self::htFor($priceTtc, $vatRate));
    }

    /** Prix HT exact : `TTC / (1 + TVA / 100)`, à 6 décimales. */
    public static function htFor(float $priceTtc, float $vatRate): float
    {
        return round($priceTtc / (1 + $vatRate / 100), self::HT_SCALE);
    }

    /** Les valeurs fournies remplacent les actuelles (le HT est recalculé) ; `null` laisse la valeur inchangée. */
    public function with(?ExtraType $type, ?string $description, ?float $priceTtc, ?float $vatRate): self
    {
        return self::priced(
            $this->id,
            $this->ownerId,
            $this->month,
            $type ?? $this->type,
            $description ?? $this->description,
            $priceTtc ?? $this->priceTtc,
            $vatRate ?? $this->vatRate,
        );
    }
}
