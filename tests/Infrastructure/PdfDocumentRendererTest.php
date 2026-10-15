<?php

declare(strict_types=1);

namespace Casamiza\Tests\Infrastructure;

use Casamiza\Domain\Invoice\Document\DocumentCompany;
use Casamiza\Domain\Invoice\Document\DocumentRecipient;
use Casamiza\Domain\Invoice\Document\InvoiceDocument;
use Casamiza\Domain\Invoice\Document\InvoiceHeadingRow;
use Casamiza\Domain\Invoice\Document\InvoiceItemRow;
use Casamiza\Domain\Invoice\Document\InvoiceSection;
use Casamiza\Domain\Invoice\Document\RecapCard;
use Casamiza\Domain\Invoice\Document\RecapDocument;
use Casamiza\Domain\Invoice\Document\VatBreakdown;
use Casamiza\Infrastructure\Pdf\PdfDocumentRenderer;
use Casamiza\Infrastructure\Pdf\PdfFontDirectory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Le moteur produit de vrais PDF (fabrication complète, polices TrueType comprises). La fidélité de la mise en page à celle de
 * Facturiza se vérifie sur les PDF repris (texte et image), pas ici : ces tests gardent le moteur en état de marche.
 */
final class PdfDocumentRendererTest extends TestCase
{
    private static function renderer(): PdfDocumentRenderer
    {
        $root = dirname(__DIR__, 2);

        return new PdfDocumentRenderer(new PdfFontDirectory($root . '/resources/pdf/fonts', sys_get_temp_dir() . '/casamiza-tests-' . get_current_user()));
    }

    private static function company(?string $logo = null): DocumentCompany
    {
        return new DocumentCompany('SARL Conciergerie CASAMEA', 'SARL', '1500', 'Résidence Kennedy, 20090 Ajaccio', '91459085600029', 'FR2591459085', 'FR3930002028510000072906H02', 'Jérémy Léandri', 'contact@example.test', '06 00 00 00 00', $logo);
    }

    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    private static function pageCount(string $pdf): int
    {
        return preg_match('~/Type /Pages\s*/Kids \[(.*?)\]\s*/Count (\d+)~s', $pdf, $matches) === 1 ? (int) $matches[2] : 0;
    }

    public function testAnInvoiceIsRenderedAsAPdfWithItsAccentedText(): void
    {
        $rows = [new InvoiceHeadingRow('Villa Santa'), new InvoiceItemRow('Du 25/07/2026 au 01/08/2026', 1, 104.92, 20.0, 125.9, 125.9)];
        $document = new InvoiceDocument(
            'FZ0000650',
            self::day('2026-09-08'),
            self::day('2026-10-08'),
            self::day('2026-08-01'),
            self::day('2026-08-31'),
            'Merci pour votre confiance',
            new DocumentRecipient('Julien Djadjoua', 'SCI Les Pins', '12345678900012', 'Porticcio'),
            [new InvoiceSection('Frais de conciergerie', $rows), new InvoiceSection('Frais de linge', []), new InvoiceSection('Frais de ménage', [])],
            [new VatBreakdown('Normale', 104.92, 20.0, 20.98)],
            104.92,
            20.98,
            125.9,
        );

        $pdf = self::renderer()->invoice(self::company(), $document);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('%%EOF', $pdf);
        self::assertGreaterThanOrEqual(1, self::pageCount($pdf));
    }

    public function testALongInvoiceAndALongRecapSpanSeveralPages(): void
    {
        $rows = [];
        for ($i = 1; $i <= 60; ++$i) {
            $rows[] = new InvoiceItemRow(sprintf('Du %02d/08/2026 au %02d/08/2026', $i % 28 + 1, $i % 28 + 2), 1, 10.0, 20.0, 12.0, 12.0);
        }
        $document = new InvoiceDocument('FZ0000001', self::day('2026-09-08'), self::day('2026-10-08'), self::day('2026-08-01'), self::day('2026-08-31'), '', new DocumentRecipient('Julien Djadjoua', '', '', 'Porticcio'), [new InvoiceSection('Frais de conciergerie', $rows)], [new VatBreakdown('Normale', 600.0, 20.0, 120.0)], 600.0, 120.0, 720.0);
        self::assertGreaterThanOrEqual(3, self::pageCount(self::renderer()->invoice(self::company(), $document)));

        $cards = [];
        for ($i = 1; $i <= 12; ++$i) {
            $cards[] = new RecapCard('Villa - Réservation du 01/08/2026 au 05/08/2026', 'Réf. ABC Booking.com - Voyageur - 2 voyageurs - 4 nuits', $i % 2 === 0, 500.0, 90.0, 5.0, 12.0, 393.0, 59.0, 70.0, 264.0);
        }
        $recap = new RecapDocument(self::day('2026-08-01'), self::day('2026-08-31'), new DocumentRecipient('Julien Djadjoua', '', '', 'Porticcio'), $cards);
        self::assertGreaterThanOrEqual(3, self::pageCount(self::renderer()->recap(self::company(), $recap)));
    }

    public function testARecapWithoutStayAndACompanyWithoutLogoStillRender(): void
    {
        $recap = new RecapDocument(self::day('2026-08-01'), self::day('2026-08-31'), new DocumentRecipient('Julien Djadjoua', '', '', ''), []);

        self::assertStringStartsWith('%PDF-', self::renderer()->recap(self::company(null), $recap));
    }
}
