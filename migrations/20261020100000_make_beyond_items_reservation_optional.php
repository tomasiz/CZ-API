<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Une ligne de relevé Beyond dont la réservation n'existe ni dans Casamiza ni chez Guesty (logement disparu de Guesty, ancien outil)
 * est enregistrée quand même : son coût compte pour le logement et le mois du séjour (check-in + nuits) au tableau de bord.
 * `reservation_id` est alors nul ; `property_id` aussi si aucun logement de Casamiza ne porte le titre de l'annonce.
 */
final class MakeBeyondItemsReservationOptional extends AbstractMigration
{
    public function up(): void
    {
        $this->table('beyond_fee_items')
            ->changeColumn('reservation_id', 'char', ['limit' => 36, 'null' => true])
            ->changeColumn('property_id', 'char', ['limit' => 36, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        // Échoue s'il reste des lignes sans réservation ou sans logement : elles ne peuvent pas redevenir obligatoires.
        $this->table('beyond_fee_items')
            ->changeColumn('reservation_id', 'char', ['limit' => 36, 'null' => false])
            ->changeColumn('property_id', 'char', ['limit' => 36, 'null' => false])
            ->update();
    }
}
