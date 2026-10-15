<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Mode de paiement « chèque » (`check`) : Facturiza ne connaissait que virement, espèces, carte et « autre ». */
final class AddCheckPaymentMethodToInvoices extends AbstractMigration
{
    public function up(): void
    {
        $this->table('invoices')
            ->changeColumn('payment_method', 'enum', ['values' => ['wire', 'check', 'cash', 'credit_card', 'other'], 'null' => true])
            ->update();
    }

    public function down(): void
    {
        // Les factures payées par chèque repassent en « autre » avant de retirer la valeur.
        $this->execute("UPDATE invoices SET payment_method = 'other' WHERE payment_method = 'check'");
        $this->table('invoices')
            ->changeColumn('payment_method', 'enum', ['values' => ['wire', 'cash', 'credit_card', 'other'], 'null' => true])
            ->update();
    }
}
