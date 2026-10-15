<?php

declare(strict_types=1);

namespace Casamiza\Tests\Infrastructure;

use Casamiza\Domain\Company\CompanySettings;
use Casamiza\Domain\Company\CompanySettingsPatch;
use Casamiza\Domain\Extra\Extra;
use Casamiza\Domain\Extra\ExtraType;
use Casamiza\Domain\Extra\VatRates;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Reservation\FinancialField;
use Casamiza\Domain\Reservation\FlagReason;
use Casamiza\Domain\Reservation\Platform;
use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Domain\Reservation\ReservationFlag;
use Casamiza\Domain\Statement\Statement;
use Casamiza\Infrastructure\Persistence\Database;
use Casamiza\Infrastructure\Persistence\PdoCompanySettingsRepository;
use Casamiza\Infrastructure\Persistence\PdoExtraRepository;
use Casamiza\Infrastructure\Persistence\PdoOwnerRepository;
use Casamiza\Infrastructure\Persistence\PdoPropertyRepository;
use Casamiza\Infrastructure\Persistence\PdoReservationRepository;
use Casamiza\Infrastructure\Persistence\PdoStatementRepository;
use Casamiza\Shared\TenantContext;
use Casamiza\Tests\AppTestCase;
use DateTimeImmutable;
use PDO;

/** Les vrais dépôts SQL (sur SQLite) : aller-retour des champs et isolation entre tenants. */
final class PdoSyncRepositoriesTest extends AppTestCase
{
    private Database $database;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec("CREATE TABLE owners (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, guesty_id TEXT NOT NULL, name TEXT NOT NULL, first_name TEXT NOT NULL DEFAULT '', last_name TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '', address TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT 'individual', company_name TEXT NOT NULL DEFAULT '', siret TEXT NOT NULL DEFAULT '', commission NUMERIC NULL, guesty_active INTEGER NOT NULL DEFAULT 1, stripe_secret_encrypted TEXT NULL, archived_at TEXT NULL, UNIQUE (tenant_id, guesty_id))");
        $pdo->exec("CREATE TABLE properties (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, guesty_id TEXT NOT NULL, owner_id TEXT NULL, name TEXT NOT NULL, address TEXT NOT NULL DEFAULT '', cleaning_fee NUMERIC NOT NULL DEFAULT 0, linen_fee NUMERIC NOT NULL DEFAULT 0, archived_at TEXT NULL, UNIQUE (tenant_id, guesty_id))");
        $this->database = new Database($this->settings(), $pdo);
    }

    private function context(string $tenantId): TenantContext
    {
        $context = new TenantContext();
        $context->set($tenantId);

        return $context;
    }

    /** @param non-empty-string $id */
    private function owner(string $id, string $guestyId = 'g-1', ?DateTimeImmutable $archivedAt = null): Owner
    {
        return new Owner($id, $guestyId, 'Alice Martin', 'Alice', 'Martin', 'alice@example.test', '+33600000000', '1 rue du Port', OwnerType::Company, 'SCI Alice', '12345678901234', 18.5, 'encrypted-key', $archivedAt);
    }

    public function testOwnersRoundTripAndAreIsolatedByTenant(): void
    {
        $a = new PdoOwnerRepository($this->database, $this->context('tenant-a'));
        $b = new PdoOwnerRepository($this->database, $this->context('tenant-b'));
        $archived = new DateTimeImmutable('2026-10-12 03:00:00 UTC');

        $a->add($this->owner('owner-a'));
        // Même identifiant Guesty chez un autre tenant : autorisé, lignes distinctes.
        $b->add($this->owner('owner-b'));

        self::assertEquals($this->owner('owner-a'), $a->all()[0]);
        self::assertCount(1, $b->all());

        $a->update($this->owner('owner-a', archivedAt: $archived));
        $b->update($this->owner('owner-a'));   // l'id d'un autre tenant : rien ne bouge chez A

        self::assertEquals($archived, $a->all()[0]->archivedAt);
        self::assertSame('owner-b', $b->all()[0]->id);
    }

    public function testASyncUpdateNeverTouchesLocalFieldsAndALocalUpdateNeverTouchesGuestyFields(): void
    {
        $a = new PdoOwnerRepository($this->database, $this->context('tenant-a'));
        $b = new PdoOwnerRepository($this->database, $this->context('tenant-b'));
        $a->add($this->owner('owner-a'));
        $stale = $a->find('owner-a');
        self::assertNotNull($stale);

        // Saisie locale pendant qu'une synchronisation a déjà lu l'ancien état.
        $a->updateLocal($stale->withLocalFields('SCI Neuve', '98765432109876')->withStripeSecret(null));
        $a->update($stale->archived(new DateTimeImmutable('2026-10-12 03:00:00 UTC')));

        $found = $a->find('owner-a');
        self::assertNotNull($found);
        self::assertSame('SCI Neuve', $found->companyName);
        self::assertSame('98765432109876', $found->siret);
        self::assertFalse($found->hasStripeKey());
        self::assertTrue($found->isArchived());

        // L'id d'un propriétaire d'un autre tenant : introuvable et intouchable.
        self::assertNull($b->find('owner-a'));
        $b->updateLocal($stale->withLocalFields('Pirate', ''));
        self::assertSame('SCI Neuve', $a->find('owner-a')?->companyName);

        $a->updateLocal($found->withStripeSecret('another-encrypted-key'));
        $reloaded = $a->find('owner-a');
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->hasStripeKey());
        self::assertSame('Alice', $reloaded->firstName);
    }

    public function testCompanySettingsAreCreatedThenReplacedPerTenant(): void
    {
        $this->database->pdo()->exec("CREATE TABLE company_settings (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL UNIQUE, legal_name TEXT NOT NULL DEFAULT '', legal_form TEXT NOT NULL DEFAULT '', capital TEXT NOT NULL DEFAULT '', legal_address TEXT NOT NULL DEFAULT '', legal_email TEXT NOT NULL DEFAULT '', legal_phone TEXT NOT NULL DEFAULT '', siret TEXT NOT NULL DEFAULT '', vat_number TEXT NOT NULL DEFAULT '', iban TEXT NOT NULL DEFAULT '', signatory_first_name TEXT NOT NULL DEFAULT '', signatory_last_name TEXT NOT NULL DEFAULT '', default_commission NUMERIC NULL, contact_name TEXT NOT NULL DEFAULT '', contact_email TEXT NOT NULL DEFAULT '', email_recap_template TEXT NULL, email_invoice_template TEXT NULL, logo_path TEXT NULL, vat_rate_concierge NUMERIC NOT NULL DEFAULT 20, vat_rate_linen NUMERIC NOT NULL DEFAULT 20, vat_rate_cleaning NUMERIC NOT NULL DEFAULT 20, software_cost NUMERIC NOT NULL DEFAULT 0)");
        $a = new PdoCompanySettingsRepository($this->database, $this->context('tenant-a'));
        $b = new PdoCompanySettingsRepository($this->database, $this->context('tenant-b'));

        self::assertNull($a->find());

        $settings = CompanySettings::blank()->patched(new CompanySettingsPatch(legalName: 'SARL A', capital: '1500', setDefaultCommission: true, defaultCommission: 20.0, emailRecapTemplate: "Bonjour\n{first_name}"));
        $a->save($settings);
        $a->save($settings->patched(new CompanySettingsPatch(legalName: 'SARL A2')));
        $a->save($a->find() ?? $settings);

        self::assertNull($b->find());
        $found = $a->find();
        self::assertNotNull($found);
        self::assertSame('SARL A2', $found->legalName);
        self::assertSame('1500', $found->capital);
        self::assertSame(20.0, $found->defaultCommission);
        self::assertSame("Bonjour\n{first_name}", $found->emailRecapTemplate);
        self::assertEquals(new VatRates(), $found->vatRates);
        $a->save($found->patched(new CompanySettingsPatch(vatRateLinen: 10.0, vatRateCleaning: 5.5)));
        self::assertEquals(new VatRates(20.0, 10.0, 5.5), $a->find()?->vatRates);
        self::assertFalse($found->hasLogo());
        $rows = $this->database->pdo()->query('SELECT id FROM company_settings');
        self::assertNotFalse($rows);
        self::assertCount(1, $rows->fetchAll());

        $b->save(CompanySettings::blank()->withLogoPath('logos/tenant-b-1.png'));
        self::assertTrue($b->find()?->hasLogo());
        self::assertFalse($a->find()?->hasLogo());
    }

    public function testPropertiesRoundTripAndAreIsolatedByTenant(): void
    {
        $a = new PdoPropertyRepository($this->database, $this->context('tenant-a'));
        $b = new PdoPropertyRepository($this->database, $this->context('tenant-b'));

        $a->add(new Property('prop-a', 'l-1', 'owner-a', 'Villa', '2 quai', 83.0, 25.5, null));
        $a->add(new Property('prop-a2', 'l-2', null, 'Orphelin', '', 0.0, 0.0, null));

        self::assertSame([], $b->all());
        $rows = $a->all();
        self::assertCount(2, $rows);
        self::assertSame('owner-a', $rows[1]->ownerId); // ORDER BY name : « Orphelin » puis « Villa »
        self::assertNull($rows[0]->ownerId);
        self::assertSame(25.5, $rows[1]->linenFee);

        $b->update(new Property('prop-a', 'l-1', null, 'Pirate', '', 0.0, 0.0, null));
        self::assertSame('Villa', $a->all()[1]->name);
    }

    /** @param non-empty-string $id */
    private function reservation(string $id, string $checkOut, string $guestyId = 'r-1', float $conciergeFee = 12.5): Reservation
    {
        return new Reservation(
            id: $id,
            guestyId: $guestyId,
            ownerId: 'owner-a',
            propertyId: 'prop-a',
            platform: Platform::Airbnb,
            source: 'airbnb2',
            integrationKeys: ['airbnb2', 'bookingCom'],
            platformRef: 'HMABC',
            guest: 'Jean Dupont',
            checkIn: new DateTimeImmutable('2026-09-10 14:00:00 UTC'),
            checkOut: new DateTimeImmutable($checkOut . ' UTC'),
            nights: 4,
            guests: 2,
            hostPayout: 500.0,
            hostServiceFee: 90.5,
            totalPaymentFees: 0.0,
            totalTaxes: 0.0,
            fareCleaning: 80.0,
            totalFees: 80.0,
            transientOccupancyTax: 12.0,
            fareAccommodationAdjusted: 600.0,
            payments: [['amount' => 602.5, 'status' => 'SUCCEEDED']],
            confirmationTaxes: [['type' => 'CITY_TAX', 'amount' => 2]],
            totalPayout: 602.5,
            channelCommission: 90.5,
            paymentFee: 0.0,
            touristTax: 12.0,
            cleaningFee: 80.0,
            netPayout: 500.0,
            conciergeFee: $conciergeFee,
            netOwnerRevenue: 407.5,
            flags: [new ReservationFlag(FinancialField::TouristTax, FlagReason::EmptyOrZeroFromGuesty)],
            stripeFee: 3.4,
            stripeError: 'resource_missing',
        );
    }

    public function testReservationsRoundTripWithFlagsAndAreIsolatedByTenant(): void
    {
        $this->database->pdo()->exec("CREATE TABLE reservations (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, guesty_id TEXT NOT NULL, owner_id TEXT NULL, property_id TEXT NOT NULL, platform TEXT NOT NULL, source TEXT NOT NULL DEFAULT '', integration_keys TEXT NOT NULL, platform_ref TEXT NOT NULL DEFAULT '', guest TEXT NOT NULL DEFAULT '', check_in TEXT NOT NULL, check_out TEXT NOT NULL, nights INTEGER NOT NULL DEFAULT 0, guests INTEGER NOT NULL DEFAULT 0, host_payout NUMERIC NOT NULL DEFAULT 0, host_service_fee NUMERIC NOT NULL DEFAULT 0, total_payment_fees NUMERIC NOT NULL DEFAULT 0, total_taxes NUMERIC NOT NULL DEFAULT 0, fare_cleaning NUMERIC NOT NULL DEFAULT 0, total_fees NUMERIC NOT NULL DEFAULT 0, transient_occupancy_tax NUMERIC NOT NULL DEFAULT 0, fare_accommodation_adjusted NUMERIC NOT NULL DEFAULT 0, payments TEXT NOT NULL, confirmation_taxes TEXT NOT NULL, total_payout NUMERIC NOT NULL DEFAULT 0, channel_commission NUMERIC NOT NULL DEFAULT 0, payment_fee NUMERIC NOT NULL DEFAULT 0, tourist_tax NUMERIC NOT NULL DEFAULT 0, cleaning_fee NUMERIC NOT NULL DEFAULT 0, net_payout NUMERIC NOT NULL DEFAULT 0, concierge_fee NUMERIC NOT NULL DEFAULT 0, net_owner_revenue NUMERIC NOT NULL DEFAULT 0, archived_at TEXT NULL, stripe_fee NUMERIC NULL, stripe_error TEXT NULL, UNIQUE (tenant_id, guesty_id))");
        $this->database->pdo()->exec('CREATE TABLE reservation_flags (tenant_id TEXT NOT NULL, reservation_id TEXT NOT NULL, field TEXT NOT NULL, reason TEXT NOT NULL, PRIMARY KEY (reservation_id, field))');
        $a = new PdoReservationRepository($this->database, $this->context('tenant-a'));
        $b = new PdoReservationRepository($this->database, $this->context('tenant-b'));
        $from = new DateTimeImmutable('2026-09-01 00:00:00 UTC');
        $to = new DateTimeImmutable('2026-10-01 00:00:00 UTC');

        $a->add($this->reservation('res-1', '2026-09-14 10:00:00'));
        $a->add($this->reservation('res-2', '2026-09-02 10:00:00', 'r-2'));
        $a->add($this->reservation('res-3', '2026-10-01 00:00:00', 'r-3'));   // borne haute exclue

        $month = $a->checkingOutBetween($from, $to);
        self::assertSame(['res-2', 'res-1'], array_map(static fn(Reservation $r): string => $r->id, $month));
        self::assertEquals($this->reservation('res-1', '2026-09-14 10:00:00'), $month[1]);
        self::assertEquals($this->reservation('res-2', '2026-09-02 10:00:00', 'r-2'), $a->findByGuestyId('r-2'));

        // Rien de visible, ni modifiable, depuis un autre tenant.
        self::assertSame([], $b->checkingOutBetween($from, $to));
        self::assertNull($b->findByGuestyId('r-1'));
        $b->update($this->reservation('res-1', '2026-09-14 10:00:00', 'r-1', 999.0));
        $b->add($this->reservation('res-b', '2026-09-14 10:00:00'));   // même guesty_id chez un autre tenant : autorisé
        $untouched = $a->findByGuestyId('r-1');
        self::assertNotNull($untouched);
        self::assertSame(12.5, $untouched->conciergeFee);
        self::assertCount(1, $untouched->flags);

        // La mise à jour remplace les signalements, sans toucher à ceux des autres réservations.
        $a->update(new Reservation(...[...get_object_vars($untouched), 'flags' => []]));
        self::assertSame([], $a->findByGuestyId('r-1')?->flags);
        $other = $a->findByGuestyId('r-2');
        self::assertNotNull($other);
        self::assertCount(1, $other->flags);

        // Archivée : absente des mois affichés, mais présente pour la synchronisation (qui peut la rétablir).
        $a->update($other->archived(new DateTimeImmutable('2026-10-14 03:00:00 UTC')));
        self::assertSame(['res-1'], array_map(static fn(Reservation $r): string => $r->id, $a->checkingOutBetween($from, $to)));
        $since = $a->allCheckingOutSince($from);
        self::assertCount(3, $since);
        self::assertTrue($a->findByGuestyId('r-2')?->isArchived());
    }

    public function testExtrasRoundTripAreSoftDeletedAndIsolatedByTenant(): void
    {
        $this->database->pdo()->exec('CREATE TABLE extras (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, owner_id TEXT NOT NULL, month TEXT NOT NULL, type TEXT NOT NULL, description TEXT NOT NULL, price_ttc NUMERIC NOT NULL, price_ht NUMERIC NOT NULL DEFAULT 0, vat_rate NUMERIC NOT NULL DEFAULT 20, deleted_at TEXT NULL)');
        $a = new PdoExtraRepository($this->database, $this->context('tenant-a'));
        $b = new PdoExtraRepository($this->database, $this->context('tenant-b'));

        $a->add(Extra::priced('extra-2', 'owner-1', '2026-09', ExtraType::Cleaning, 'Ménage', -66.6, 0.0));
        $a->add(Extra::priced('extra-1', 'owner-1', '2026-09', ExtraType::Linen, 'Draps', 120.0, 20.0));
        $a->add(Extra::priced('extra-3', 'owner-1', '2026-08', ExtraType::Linen, 'Août', 10.0, 20.0));

        self::assertEquals(
            [Extra::priced('extra-1', 'owner-1', '2026-09', ExtraType::Linen, 'Draps', 120.0, 20.0), Extra::priced('extra-2', 'owner-1', '2026-09', ExtraType::Cleaning, 'Ménage', -66.6, 0.0)],
            $a->forOwnerMonth('owner-1', '2026-09'),
        );
        self::assertSame([], $b->forOwnerMonth('owner-1', '2026-09'));
        self::assertNull($b->find('extra-1'));

        // Le HT est stocké exact (6 décimales), pas arrondi au centime : 199,99 € TTC à 20 % → 166,658333.
        $a->add(Extra::priced('extra-4', 'owner-1', '2026-07', ExtraType::Concierge, 'Aspirateur', 199.99, 20.0));
        self::assertSame(166.658333, $a->find('extra-4')?->priceHt);
        self::assertSame(-66.6, $a->find('extra-2')?->priceHt);   // TVA 0 % : HT = TTC

        $a->update(Extra::priced('extra-1', 'owner-1', '2026-09', ExtraType::Concierge, 'Conciergerie', 50.0, 10.0));
        $b->update(Extra::priced('extra-1', 'owner-1', '2026-09', ExtraType::Linen, 'Pirate', 1.0, 20.0));
        $b->remove('extra-1');
        self::assertEquals(Extra::priced('extra-1', 'owner-1', '2026-09', ExtraType::Concierge, 'Conciergerie', 50.0, 10.0), $a->find('extra-1'));

        $a->remove('extra-1');
        self::assertNull($a->find('extra-1'));
        self::assertSame(['extra-2'], array_map(static fn(Extra $e): string => $e->id, $a->forOwnerMonth('owner-1', '2026-09')));
        // Suppression logique : la ligne est toujours en base.
        $deleted = $this->database->pdo()->prepare('SELECT COUNT(*) FROM extras WHERE id = :id AND deleted_at IS NOT NULL');
        $deleted->execute(['id' => 'extra-1']);
        self::assertSame(1, (int) $deleted->fetchColumn());
    }

    public function testStatementsAreUpsertedPerOwnerAndMonthAndIsolatedByTenant(): void
    {
        $this->database->pdo()->exec('CREATE TABLE monthly_statements (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, owner_id TEXT NOT NULL, month TEXT NOT NULL, nothing_to_invoice INTEGER NOT NULL DEFAULT 0, UNIQUE (tenant_id, owner_id, month))');
        $a = new PdoStatementRepository($this->database, $this->context('tenant-a'));
        $b = new PdoStatementRepository($this->database, $this->context('tenant-b'));

        self::assertNull($a->find('owner-1', '2026-09'));
        $a->save(new Statement('st-1', 'owner-1', '2026-09', true));
        $a->save(new Statement('st-other', 'owner-1', '2026-09', false));   // même (propriétaire, mois) : réécrit la ligne existante
        $a->save(new Statement('st-2', 'owner-2', '2026-09', true));
        $b->save(new Statement('st-b', 'owner-1', '2026-09', true));

        self::assertEquals(new Statement('st-1', 'owner-1', '2026-09', false), $a->find('owner-1', '2026-09'));
        self::assertCount(2, $a->where(null, '2026-09'));
        self::assertCount(1, $a->where('owner-2', null));
        self::assertCount(0, $a->where('owner-1', '2026-08'));
        self::assertEquals([new Statement('st-b', 'owner-1', '2026-09', true)], $b->where(null, null));
    }
}
