<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Le HT d'une prestation est stocké (exact, 6 décimales) à côté du TTC saisi et du taux de TVA : un montant facturé ne doit
 * pas dépendre d'une formule du code, et le total d'une facture additionne ces HT exacts (comme Facturiza).
 * Les lignes existantes sont complétées avec `price_ttc / (1 + vat_rate / 100)`.
 */
final class AddPriceHtToExtras extends AbstractMigration
{
    public function up(): void
    {
        $this->table('extras')
            ->addColumn('price_ht', 'decimal', ['precision' => 14, 'scale' => 6, 'default' => 0, 'after' => 'price_ttc'])
            ->update();
        $this->execute('UPDATE extras SET price_ht = ROUND(price_ttc / (1 + vat_rate / 100), 6)');
    }

    public function down(): void
    {
        $this->table('extras')->removeColumn('price_ht')->update();
    }
}
