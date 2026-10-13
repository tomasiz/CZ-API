<?php

declare(strict_types=1);

namespace Casamiza\Tests\Import;

use Casamiza\Infrastructure\Import\FacturizaDump;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FacturizaDumpTest extends TestCase
{
    private const DUMP = <<<'SQL'
        -- Export phpMyAdmin
        CREATE TABLE `owners` (`user_id` int NOT NULL);
        INSERT INTO `api_tokens` (`id`, `token`) VALUES
        (1, 'ne-pas-lire');

        INSERT INTO `owners` (`user_id`, `guesty_owner_id`, `first_name`, `legal_siret`, `legal_name`, `rate`, `active`) VALUES
        (2, 'A1', 'P - Carole', '953508736', 'SARL L\'EXEMPLE', 12.5, 1),
        (2, 'A2', 'Jean, Paul (x)', NULL, 'Ligne 1\nLigne 2 \\ fin', 0, 0),
        (2, 'A3', 'Bob''s', '', 'Société « Été »', -1, 1);

        INSERT INTO `owners` (`user_id`, `guesty_owner_id`, `first_name`, `legal_siret`, `legal_name`, `rate`, `active`) VALUES
        (3, 'B1', 'Autre', '1', 'x', 5, 1);
        SQL;

    public function testRowsOfOneTableAreReadWithEscapesAndNulls(): void
    {
        $rows = FacturizaDump::rows(self::DUMP, 'owners');

        self::assertCount(4, $rows, 'deux INSERT sur la même table sont lus');
        self::assertSame([
            'user_id' => 2,
            'guesty_owner_id' => 'A1',
            'first_name' => 'P - Carole',
            'legal_siret' => '953508736',
            'legal_name' => "SARL L'EXEMPLE",
            'rate' => 12.5,
            'active' => 1,
        ], $rows[0]);
        // Virgules et parenthèses dans une chaîne, NULL, retour à la ligne échappé, antislash.
        self::assertSame('Jean, Paul (x)', $rows[1]['first_name']);
        self::assertNull($rows[1]['legal_siret']);
        self::assertSame("Ligne 1\nLigne 2 \\ fin", $rows[1]['legal_name']);
        // Apostrophe doublée, nombre négatif, accents.
        self::assertSame("Bob's", $rows[2]['first_name']);
        self::assertSame(-1, $rows[2]['rate']);
        self::assertSame('Société « Été »', $rows[2]['legal_name']);
        self::assertSame('B1', $rows[3]['guesty_owner_id']);
    }

    public function testOtherTablesAreIgnoredAndAnUnknownTableIsEmpty(): void
    {
        self::assertSame([], FacturizaDump::rows(self::DUMP, 'invoices'));
        self::assertSame([['id' => 1, 'token' => 'ne-pas-lire']], FacturizaDump::rows(self::DUMP, 'api_tokens'));
    }

    public function testATruncatedDumpIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FacturizaDump::rows("INSERT INTO `owners` (`a`, `b`) VALUES\n(1, 'abc", 'owners');
    }
}
