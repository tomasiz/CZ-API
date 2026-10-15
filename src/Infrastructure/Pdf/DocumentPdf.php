<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Pdf;

use Casamiza\Domain\Invoice\Document\DocumentCompany;
use Casamiza\Domain\Invoice\Document\DocumentRecipient;
use Casamiza\Domain\Invoice\Document\InvoiceHeadingRow;
use Casamiza\Domain\Invoice\Document\InvoiceItemRow;
use Casamiza\Domain\Invoice\Document\RecapCard;
use Casamiza\Domain\Invoice\Document\VatBreakdown;
use DateTimeImmutable;
use RuntimeException;
use tFPDF;

/**
 * Mise en page des documents de Facturiza (`pdf_perso.php`), portée **à l'identique** : mêmes polices, mêmes cotes, mêmes
 * couleurs, même gestion des sauts de page (y compris le défaut connu de la facture qui déborde sur une page 2, FACTURIZA.md
 * §6.5). Cette classe ne fait que dessiner ce qu'on lui donne ; l'écriture des nombres est celle de `PdfNumber`.
 */
final class DocumentPdf extends tFPDF
{
    private const MARGIN = 12.7;
    /** Distance du bas de page à laquelle le saut de page se déclenche : marge + 7 mm (le pied de page). */
    private const BREAK_MARGIN = 19.7;
    private const LINE = 5;
    private const LOGO_WIDTH = 40;
    private const BLUE = [15, 75, 100];
    private const COLUMN_SIZES = [0.45, 0.06, 0.12, 0.1, 0.12, 0.15];
    private const COLUMN_TITLES = ['Libéllé', 'Qté', 'P.U. HT', 'TVA', 'P.U. TTC', 'Montant TTC'];
    private const COLUMN_UNITS = ['', '', ' €', ' %', ' €', ' €'];
    private const TERMS = "Pas d'escompte pour règlement anticipé. En cas de retard de paiement, une pénalité égale à 3 fois le taux intérêt légal sera exigible (Article L 441-10, alinéa 12 du Code de Commerce). Pour tout professionnel, en sus des indemnités de retard, toute somme, y compris l'acompte, non payée à sa date d'exigibilité produira de plein droit le paiement d'une indemnité forfaitaire de 40 euros due au titre des frais de recouvrement (Art. 441-6, I al. 12 du code de commerce et D. 441-5 ibidem).";

    private bool $invoice = false;
    /** Alternance des lignes grises de la facture (continue d'un bloc à l'autre). */
    private bool $grayInvoiceLine = false;
    /** Alternance des lignes grises du récapitulatif (continue d'une carte à l'autre). */
    private bool $grayRecapLine = false;
    private float $vatBlockHeight = 0;
    private string $number = '';
    private ?DocumentCompany $company = null;
    private ?self $measurer = null;
    private ?string $logoFile = null;

    public function __construct(private readonly string $fontDirectory)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->fontpath = $fontDirectory;
    }

    public function __destruct()
    {
        if ($this->logoFile !== null) {
            @unlink($this->logoFile);
        }
    }

    /** Ouvre le document : une facture (`$invoice`) ou un récapitulatif, émis par `$company`. */
    public function begin(DocumentCompany $company, bool $invoice, string $number = ''): void
    {
        $this->company = $company;
        $this->invoice = $invoice;
        $this->number = $number;

        $this->AliasNbPages();
        $this->AddPage();
        $this->SetAutoPageBreak(true, self::BREAK_MARGIN);
        $this->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);

        $this->AddFont('HelveticaBold', '', 'Helvetica-Bold.ttf', true);
        $this->AddFont('HelveticaReg', '', 'Helvetica.ttf', true);
        $this->AddFont('HelveticaItalic', '', 'Helvetica-Oblique.ttf', true);
    }

    public function Footer(): void
    {
        $company = $this->company();
        $this->SetY(-self::MARGIN - 6);
        $this->SetFont('Arial', 'I', 8);

        $legal = [$company->legalName];
        if ($company->siret !== '') {
            $legal[] = 'SIRET ' . $company->siret;
        }
        if ($company->capital !== '') {
            $legal[] = trim($company->legalForm . ' au capital de ' . $company->capital . ' euros');
        }
        if ($company->vatNumber !== '') {
            $legal[] = 'TVA ' . $company->vatNumber;
        }
        $this->Cell(0, 3, implode(' - ', $legal), 0, 0, 'L');
        $this->Cell(0, 3, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
        $this->Ln(4);
        $this->Cell(0, 3, 'IBAN : ' . $company->iban, 0, 0, 'L');
    }

    /**
     * En-tête de la première page : l'émetteur, le destinataire et l'objet du document.
     * `$date` n'est imprimée que sur une facture (encart bleu).
     */
    public function firstPageHeader(DocumentRecipient $recipient, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $date, string $note = ''): void
    {
        $company = $this->company();

        $this->SetY(self::MARGIN + 2);
        if ($company->logoPng !== null) {
            $this->Image($this->logo($company->logoPng), self::MARGIN, self::MARGIN + 3, self::LOGO_WIDTH, 0, 'PNG');
        }

        $this->SetFont('HelveticaReg', '', 12);
        $this->SetX(self::LOGO_WIDTH + self::MARGIN + 10);
        $this->SetFont('HelveticaBold', '', 14);
        $this->Cell(0, 0, $company->legalName, 0, 0, 'L');
        $this->Ln(self::LINE);

        $this->SetFont('HelveticaReg', '', 12);
        $this->SetX(self::LOGO_WIDTH + self::MARGIN + 10);
        $this->MultiCell(90, 6, $company->address . "\nSiret : " . $company->siret . "\n\n" . $company->contactName . "\nE-mail : " . $company->email . "\nTél. : " . $company->phone, 0, 'L');

        if ($this->invoice) {
            $this->invoiceBox($date);
        }

        $this->SetY(self::LOGO_WIDTH + self::MARGIN + 20);
        $this->SetFont('HelveticaBold', '', 12);
        $this->Cell(0, 0, $recipient->name, 0, 0, 'R');
        $this->Ln(self::LINE + 1);
        $this->SetFont('HelveticaReg', '', 12);
        if ($recipient->legalName !== '') {
            $this->Cell(0, 0, $recipient->legalName, 0, 0, 'R');
            $this->Ln(self::LINE + 1);
        }
        if ($recipient->siret !== '') {
            $this->Cell(0, 0, 'Siret : ' . $recipient->siret, 0, 0, 'R');
            $this->Ln(self::LINE + 1);
        }
        $this->Cell(0, 0, $recipient->address, 0, 0, 'R');
        $this->Ln(self::LINE * 3);

        $subject = ($this->invoice ? 'Facture portant sur les locations du ' : 'Récapitulatif des locations du ') . $start->format('d/m/Y') . ' au ' . $end->format('d/m/Y');
        $this->SetFont('HelveticaBold', '', 13);
        $this->Cell(0, 0, $subject, 0, 0, 'C');
        $this->Ln(self::LINE * 2);

        if ($note !== '') {
            $this->SetFont('HelveticaItalic', '', 12);
            $this->MultiCell(0, self::LINE + 1, 'Note : ' . $note, 0, 'L', false);
            $this->Ln(self::LINE * 2);
        }
    }

    /** Une carte du récapitulatif : bandeau bleu (logement, séjour, voyageur) puis le détail des montants. */
    public function recapCard(RecapCard $card): void
    {
        $this->SetFillColor(...self::BLUE);
        if ($this->GetY() > $this->GetPageHeight() - self::BREAK_MARGIN - 20) {
            $this->AddPage();
        }
        $this->Rect($this->GetX(), $this->GetY() - self::LINE / 2, $this->usableWidth(), self::LINE * 2.5, 'FD');

        $this->SetTextColor(255, 255, 255);
        $this->SetFont('HelveticaBold', '', 12);
        $this->Ln(self::LINE / 4);
        $this->Cell(0, 0, $card->title);
        $this->Ln(self::LINE);
        $this->SetFont('HelveticaItalic', '', 11);
        $this->Cell(0, 0, $card->subtitle);
        $this->Ln(self::LINE * 0.75);
        $this->SetTextColor(0, 0, 0);

        if (!$card->retrocommission) {
            $this->recapLine('Total payé voyageur', PdfNumber::text($card->totalPaid), false);
            $this->recapLine('Frais plateforme', PdfNumber::text($card->channelCommission + $card->paymentFee), true);
            if ($card->touristTax !== 0.0) {
                $this->recapLine('Taxe de séjour', PdfNumber::text($card->touristTax), true);
            }
            $this->recapLine('Total Net versé', PdfNumber::text($card->netPayout), false, 'R');
            $this->recapLine('Frais ménage', PdfNumber::text($card->cleaningFee), true);
            $this->recapLine('Frais conciergerie', PdfNumber::text($card->conciergeFee), true);
            $this->recapLine('Total Net Propriétaire', PdfNumber::text($card->netOwnerRevenue), false, 'R', true);
        } else {
            $this->recapLine('Total payé voyageur', PdfNumber::text($card->totalPaid), false);
            $this->recapLine('Frais de paiement', PdfNumber::text($card->paymentFee), true);
            $this->recapLine('Total Net versé', PdfNumber::text($card->netPayout), false, 'R');
            $this->recapLine('Frais plateforme', PdfNumber::text($card->channelCommission), true);
            if ($card->touristTax !== 0.0) {
                $this->recapLine('Taxe de séjour', PdfNumber::text($card->touristTax), true);
            }
            $this->recapLine('Frais ménage', PdfNumber::text($card->cleaningFee), true);
            $this->recapLine('Frais conciergerie', PdfNumber::text($card->conciergeFee), true);
            $this->recapLine('Total Net Propriétaire', PdfNumber::text($card->netOwnerRevenue), false, 'R', true);
        }

        $this->Ln(self::LINE * 3);
    }

    /** Pied du récapitulatif : total net propriétaire de la période (le seul montant écrit avec un point décimal). */
    public function recapTotal(DateTimeImmutable $start, DateTimeImmutable $end, float $total): void
    {
        $this->SetFont('HelveticaBold', '', 12);
        $this->SetDrawColor(...self::BLUE);
        $this->Cell(0, 8, 'Total Net Propriétaire période du ' . $start->format('d/m/Y') . ' au ' . $end->format('d/m/Y'), 1, 0, 'L');
        $this->Cell(0, 8, PdfNumber::text($total) . ' €', 0, 0, 'R');
    }

    /**
     * Une rubrique de la facture : bandeau bleu, en-têtes de colonnes, puis les lignes (titres de logement ou lignes chiffrées).
     *
     * @param list<InvoiceHeadingRow|InvoiceItemRow> $rows
     */
    public function invoiceSection(string $title, array $rows): void
    {
        $this->SetFillColor(...self::BLUE);
        if ($this->GetY() > $this->GetPageHeight() - self::BREAK_MARGIN - 40) {
            $this->AddPage();
        }
        $this->Rect($this->GetX(), $this->GetY(), $this->usableWidth(), self::LINE * 2, 'FD');

        $this->SetTextColor(255, 255, 255);
        $this->SetFont('HelveticaBold', '', 12);
        $this->SetX($this->GetX() + 1);
        $this->Cell(0, self::LINE * 2, $title, 0, 1, 'L', false);

        $this->SetFillColor(220, 220, 220);
        $this->Rect($this->GetX(), $this->GetY(), $this->usableWidth(), 7, 'F');

        $this->SetTextColor(0, 0, 0);
        $this->SetFont('HelveticaItalic', '', 10);
        $this->SetX($this->GetX() + 1);
        $last = count(self::COLUMN_TITLES) - 1;
        foreach (self::COLUMN_TITLES as $i => $columnTitle) {
            $this->Cell(self::COLUMN_SIZES[$i] * $this->usableWidth() - ($i === $last ? 1 : 0), 7, $columnTitle, 0, 0, $i === $last ? 'R' : 'L', false);
        }
        $this->Ln(7);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('HelveticaReg', '', 12);

        foreach ($rows as $row) {
            if ($row instanceof InvoiceItemRow) {
                $this->invoiceLine($row);
            } else {
                $this->SetFillColor(230, 230, 230);
                $this->Rect($this->GetX(), $this->GetY(), $this->usableWidth(), 7, 'F');
                $this->SetX($this->GetX() + 1);
                $this->Cell($this->usableWidth(), 7, $row->text, 0, 0, 'C', false);
                $this->Ln(7);
            }
        }

        $this->Ln(self::LINE * 1.5);
    }

    /** @param list<VatBreakdown> $items */
    public function vatBlock(array $items): void
    {
        $width = 0.55;
        $titles = ['Code', 'Base HT', 'Taux', 'Montant'];
        $units = ['', ' €', ' %', ' €'];

        $this->SetFillColor(...self::BLUE);
        if ($this->GetY() > $this->GetPageHeight() - self::BREAK_MARGIN - 40) {
            $this->AddPage();
        }

        $blockWidth = $this->usableWidth() * $width;
        $this->SetLineWidth(0.5);
        $this->Line($this->GetX() + 0.25, $this->GetY() + self::LINE * 2, $this->GetX() + $blockWidth - 0.25, $this->GetY() + self::LINE * 2);

        $this->SetTextColor(...self::BLUE);
        $this->SetFont('HelveticaBold', '', 12);
        $this->SetX($this->GetX() + 1);
        $this->Cell($blockWidth, self::LINE * 2, 'Détail de la TVA', 0, 1, 'L', false);

        $this->SetFillColor(220, 220, 220);
        $this->Rect($this->GetX(), $this->GetY(), $blockWidth, 7, 'F');

        $this->SetTextColor(0, 0, 0);
        $this->SetFont('HelveticaItalic', '', 10);
        $this->SetX($this->GetX() + 1);
        foreach ($titles as $i => $columnTitle) {
            $this->Cell(0.25 * $blockWidth - ($i === 3 ? 1 : 0), 7, $columnTitle, 0, 0, 'L', false);
        }
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('HelveticaReg', '', 12);
        $this->Ln(7);

        $lineHeight = 8;
        foreach ($items as $item) {
            $this->SetX($this->GetX() + 1);
            $this->SetFillColor(245, 245, 245);
            if ($this->grayInvoiceLine) {
                $this->grayInvoiceLine = false;
                $this->Rect(self::MARGIN, $this->GetY(), $blockWidth, $lineHeight, 'F');
            } else {
                $this->grayInvoiceLine = true;
            }

            $values = [$item->code, $item->baseHt, $item->rate, $item->vat];
            foreach ($values as $i => $value) {
                if ($i === 3) {
                    $this->SetFont('HelveticaBold', '', 12);
                }
                $this->SetFillColor(245, 245, 24);
                $this->Cell(0.25 * $blockWidth - ($i === 3 ? 1 : 0), $lineHeight, $this->display($value) . $units[$i], 0, 0, 'L', false);
                if ($i === 3) {
                    $this->SetFont('HelveticaReg', '', 12);
                }
            }
            $this->Ln();
        }

        $this->vatBlockHeight = self::LINE * 2 + 7 + count($items) * 8;
    }

    /** Bloc « Total » (HT, TVA, TTC), calé à droite à la hauteur du détail de la TVA. */
    public function totalBlock(float $totalHt, float $vat, float $totalTtc): void
    {
        $width = 0.4;
        $blockWidth = $this->usableWidth() * $width;
        $xStart = $this->GetPageWidth() - self::MARGIN - $blockWidth;

        $this->SetY($this->GetY() - $this->vatBlockHeight, false);
        $this->SetFillColor(...self::BLUE);
        if ($this->GetY() > $this->GetPageHeight() - self::BREAK_MARGIN - 40) {
            $this->AddPage();
        }

        $this->Rect($xStart, $this->GetY(), $blockWidth, self::LINE * 2, 'F');
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('HelveticaBold', '', 12);
        $this->SetX($xStart + 1);
        $this->Cell($blockWidth, self::LINE * 2, 'Total', 0, 1, 'L', false);

        $this->SetTextColor(0, 0, 0);
        $this->SetFont('HelveticaReg', '', 12);

        $items = [
            'Total HT' => $this->display($totalHt) . ' €',
            'TVA' => $this->display($vat) . ' €',
            'Total TTC' => $this->display($totalTtc) . ' €',
        ];
        foreach ($items as $key => $item) {
            $lineHeight = $key === 'TVA' ? 7 : 8;
            $this->SetX($xStart + 1);
            $this->SetFillColor(245, 245, 245);
            if ($this->grayInvoiceLine) {
                $this->grayInvoiceLine = false;
                $this->Rect($xStart, $this->GetY(), $blockWidth, $lineHeight, 'F');
            } else {
                $this->grayInvoiceLine = true;
            }
            if ($key === 'Total TTC') {
                $this->SetFont('HelveticaBold', '', 12);
            }
            $this->Cell(0.5 * $blockWidth, $lineHeight, $key, 0, 0, 'L', false);
            $this->Cell(0.5 * $blockWidth - 1, $lineHeight, $item, 0, 0, 'R', false);
            $this->Ln();
        }

        $this->SetFont('HelveticaReg', '', 12);
        $this->Ln(self::LINE);
    }

    public function dueDate(float $amount, DateTimeImmutable $date): void
    {
        $this->Ln(self::LINE);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('HelveticaReg', '', 12);
        $this->SetLineWidth(0.2);
        $this->Cell($this->usableWidth(), 8, 'Échéance : virement de ' . $this->display($amount) . ' € au ' . $date->format('d/m/Y'), 1, 1, 'C', false);
    }

    public function terms(): void
    {
        $this->Ln(self::LINE * 2);
        $this->SetFont('HelveticaItalic', '', 10);
        $this->SetTextColor(130, 130, 130);
        $this->MultiCell($this->usableWidth(), self::LINE * 0.8, self::TERMS, 0, 'L', false);
    }

    /** Les octets du PDF. */
    public function bytes(): string
    {
        return $this->Output('S');
    }

    private function usableWidth(): float
    {
        return $this->GetPageWidth() - self::MARGIN * 2;
    }

    private function company(): DocumentCompany
    {
        return $this->company ?? throw new RuntimeException('The document is not started.');
    }

    /** Fichier temporaire du logo (tFPDF lit une image sur le disque) ; supprimé avec le document. */
    private function logo(string $png): string
    {
        if ($this->logoFile === null) {
            $file = tempnam(sys_get_temp_dir(), 'logo');
            if ($file === false || file_put_contents($file, $png) === false) {
                throw new RuntimeException('Cannot write the logo.');
            }
            $this->logoFile = $file;
        }

        return $this->logoFile;
    }

    /** Encart bleu « FACTURE » en haut à droite : numéro et date. */
    private function invoiceBox(DateTimeImmutable $date): void
    {
        $height = 40;
        $width = 35;
        $this->SetDrawColor(...self::BLUE);
        $this->SetFillColor(...self::BLUE);
        $this->Rect($this->GetPageWidth() - self::MARGIN - $width, self::MARGIN, $width, $height, 'D');

        $this->SetX($this->GetPageWidth() - self::MARGIN - $width);
        $this->SetY(self::MARGIN, false);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('HelveticaBold', '', 14);
        $this->Cell($width, 12, 'FACTURE', 0, 2, 'C', true);

        $this->SetY(self::MARGIN + 10 + 3, false);
        $this->SetX($this->GetX() + 1);
        $this->SetFont('HelveticaReg', '', 12);
        $this->SetTextColor(100, 100, 100);
        $this->Cell($width, 5, 'Numéro', 0, 2, 'L');
        $this->SetTextColor(0, 0, 0);
        $this->Cell($width, 5, $this->number, 0, 2, 'L');
        $this->SetY($this->GetY() + 5, false);
        $this->SetTextColor(100, 100, 100);
        $this->Cell($width, 5, 'Date', 0, 2, 'L');
        $this->SetTextColor(0, 0, 0);
        $this->Cell($width, 5, $date->format('d/m/Y'), 0, 2, 'L');
        $this->SetTextColor(0, 0, 0);
    }

    /** Ligne de la carte du récapitulatif : libellé (85 %) et montant (15 %, à droite) ; une ligne sur deux est grise. */
    private function recapLine(string $title, string $amount, bool $negative, string $align = '', bool $bold = false): void
    {
        $this->SetFillColor(245, 245, 245);
        $height = 8;
        $first = $this->usableWidth() * 0.85;
        $second = $this->usableWidth() * (1 - 0.85);

        $this->SetFont($bold ? 'HelveticaBold' : 'HelveticaReg', '', 12);
        $this->Cell($first, $height, $title, 0, 0, $align, $this->grayRecapLine);
        $this->Cell($second, $height, ($negative ? '- ' : '') . str_replace('.', ',', $amount) . ' €', 0, 0, 'R', $this->grayRecapLine);
        $this->Ln($height);
        $this->grayRecapLine = !$this->grayRecapLine;
    }

    private function invoiceLine(InvoiceItemRow $row): void
    {
        $items = [$row->label, $row->quantity, $row->unitHt, $row->vatRate, $row->unitTtc, $row->totalTtc];
        $this->SetAutoPageBreak(false);
        $lineHeight = 8;

        $initialLeftMargin = $this->GetX();
        $this->SetLeftMargin($initialLeftMargin + 1);
        $this->SetFillColor(245, 245, 245);

        $textHeight = $this->multiCellHeight(self::COLUMN_SIZES[0] * $this->usableWidth(), 6, $this->display($items[0]) . self::COLUMN_UNITS[0]);
        if ($this->GetY() + $textHeight + self::BREAK_MARGIN >= $this->GetPageHeight()) {
            $this->AddPage();
        }
        if ($this->grayInvoiceLine) {
            $this->Rect(self::MARGIN, $this->GetY(), $this->usableWidth(), $textHeight + 2, 'F');
        }

        $rowHeight = $lineHeight;
        $last = count($items) - 1;
        foreach ($items as $i => $item) {
            $startY = $this->GetY();
            $startX = $this->GetX();
            $align = 'L';
            $widthOffset = 0;
            if ($i === $last) {
                $align = 'R';
                $this->SetFont('HelveticaBold', '', 12);
                $widthOffset = 1;
            }
            $cellWidth = self::COLUMN_SIZES[$i] * $this->usableWidth() - $widthOffset;

            $cellTextHeight = $rowHeight;
            if ($i === 0) {
                $cellTextHeight = 6;
                $this->SetY($this->GetY() + ($lineHeight - $cellTextHeight) / 2);
            }

            $this->MultiCell($cellWidth, $cellTextHeight, $this->display($item) . self::COLUMN_UNITS[$i], 0, $align, false);

            if ($i === 0) {
                $this->SetY($this->GetY() + ($lineHeight - $cellTextHeight) / 2);
            }
            $newY = $this->GetY();
            if ($newY - $startY > $rowHeight) {
                $rowHeight = $newY - $startY;
            }
            $this->SetXY($startX + $cellWidth, $startY);

            if ($i === $last) {
                $this->SetFont('HelveticaReg', '', 12);
            }
        }

        $this->grayInvoiceLine = !$this->grayInvoiceLine;
        $this->SetLeftMargin($initialLeftMargin);
        $this->SetAutoPageBreak(true, self::BREAK_MARGIN);
        $this->Ln($rowHeight);
    }

    /** Hauteur d'un texte à renvoyer à la ligne dans une colonne, mesurée sur un second document (comme Facturiza). */
    private function multiCellHeight(float $width, float $lineHeight, string $text): float
    {
        if ($this->measurer === null) {
            $measurer = new self($this->fontDirectory);
            $measurer->AddPage();
            $measurer->AddFont('HelveticaReg', '', 'Helvetica.ttf', true);
            $measurer->SetFont('HelveticaReg', '', 12);
            $this->measurer = $measurer;
        }
        $this->measurer->SetXY(0, 0);
        $this->measurer->MultiCell($width, $lineHeight, $text, 0, 'L', true);

        return $this->measurer->GetY();
    }

    /** `toDisplay` de Facturiza : un nombre s'arrondit au centime ; un libellé reste tel quel. */
    private function display(string|int|float $value): string
    {
        if (is_string($value)) {
            return is_numeric($value) ? PdfNumber::display((float) $value) : $value;
        }

        return PdfNumber::display($value);
    }
}
