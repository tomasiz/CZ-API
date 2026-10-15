<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice\Document;

use DateTimeImmutable;

/** Tout ce qu'imprime le récapitulatif propriétaire : une carte par réservation, regroupées par logement. */
final readonly class RecapDocument
{
    /** @param list<RecapCard> $cards */
    public function __construct(
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public DocumentRecipient $recipient,
        public array $cards,
    ) {}
}
