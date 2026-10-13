<?php

declare(strict_types=1);

namespace Casamiza\Tests\Import;

use Casamiza\Domain\Import\FacturizaDirectoryImporter;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Property\Property;
use Casamiza\Infrastructure\Security\Encryptor;
use Casamiza\Shared\TenantContext;
use Casamiza\Tests\Support\FixedClock;
use Casamiza\Tests\Support\ImmediateTransaction;
use Casamiza\Tests\Support\InMemoryOwners;
use Casamiza\Tests\Support\InMemoryProperties;
use PHPUnit\Framework\TestCase;

final class FacturizaDirectoryImporterTest extends TestCase
{
    private const KEY = 'sk_live_51ABCdefGHIjklMNOpqr';

    private TenantContext $context;
    private InMemoryOwners $owners;
    private InMemoryProperties $properties;
    private FacturizaDirectoryImporter $importer;

    protected function setUp(): void
    {
        $this->context = new TenantContext();
        $this->context->set('tenant-a');
        $this->owners = new InMemoryOwners($this->context);
        $this->properties = new InMemoryProperties($this->context);
        $this->importer = new FacturizaDirectoryImporter($this->owners, $this->properties, new ImmediateTransaction(), new Encryptor(str_repeat('k', 32)), new FixedClock());

        // Déjà connus de Casamiza (synchro Guesty) : ne doivent jamais être touchés.
        $this->owners->add(new Owner('o-known', 'G-KNOWN', 'Alice Martin', 'Alice', 'Martin', 'alice@example.test', '', '', OwnerType::Individual, '', '', 20.0, null, null));
        $this->properties->add(new Property('p-known', 'L-KNOWN', 'o-known', 'Villa', '1 quai', 80.0, 20.0, null));
        // Même identifiant Guesty chez un autre tenant : n'empêche pas la création ici.
        $this->context->reset();
        $this->context->set('tenant-b');
        $this->owners->add(new Owner('o-b', 'G-NEW', 'Autre', '', 'Autre', '', '', '', OwnerType::Individual, '', '', null, null, null));
        $this->context->reset();
        $this->context->set('tenant-a');
    }

    /**
     * @param array<string, string|int|float|null> $more
     * @return array<string, string|int|float|null>
     */
    private static function owner(string $guestyId, string $first, string $last, array $more = []): array
    {
        return $more + ['user_id' => 2, 'guesty_owner_id' => $guestyId, 'first_name' => $first, 'last_name' => $last, 'email' => 'x@example.test', 'address' => '2 rue Fesch, Ajaccio', 'legal_siret' => null, 'legal_name' => null, 'legal_iban' => '', 'conciergerie_commission' => 20.0, 'stripe_api_key' => '', 'active' => 0];
    }

    /** @return array<string, string|int|float|null> */
    private static function listing(string $guestyId, string $nickname, string $ownerId, float $clean = 60.0, float $linen = 17.0): array
    {
        return ['user_id' => 2, 'guesty_listing_id' => $guestyId, 'active' => 0, 'guesty_owner_id' => $ownerId, 'nickname' => $nickname, 'address' => '13 Rue Fesch', 'cout_menage' => $clean, 'cout_linge' => $linen];
    }

    private function created(string $guestyId): ?Owner
    {
        return $this->owners->byGuestyId('tenant-a', $guestyId);
    }

    public function testMissingOwnersAreCreatedArchivedWithAllTheirData(): void
    {
        $report = $this->importer->import([
            self::owner('G-NEW', 'P - Jeanne ', 'Depot', ['stripe_api_key' => self::KEY, 'conciergerie_commission' => 15.5, 'legal_name' => 'SARL  Depot', 'legal_siret' => '123 456 789 01234']),
            self::owner('G-KNOWN', 'P - Alice', 'Martin'),
            self::owner('G-NOKEY', 'Edouard', 'Desbois', ['conciergerie_commission' => -1.0, 'address' => '']),
        ], [], true);

        self::assertSame(1, $report->ownersPresent);
        self::assertCount(2, $report->owners);

        $new = $this->created('G-NEW');
        self::assertNotNull($new);
        self::assertSame(['Jeanne Depot', 'Jeanne', 'Depot'], [$new->name, $new->firstName, $new->lastName]);
        self::assertSame('x@example.test', $new->email);
        self::assertSame('2 rue Fesch, Ajaccio', $new->address);
        self::assertSame(15.5, $new->commission);
        self::assertSame(['SARL Depot', '12345678901234', OwnerType::Company], [$new->companyName, $new->siret, $new->type]);
        self::assertTrue($new->isArchived());
        self::assertNotNull($new->stripeSecretEncrypted);
        self::assertStringNotContainsString(self::KEY, $new->stripeSecretEncrypted);
        self::assertSame(self::KEY, (new Encryptor(str_repeat('k', 32)))->decrypt($new->stripeSecretEncrypted));

        $other = $this->created('G-NOKEY');
        self::assertNotNull($other);
        self::assertNull($other->commission, '-1 = non renseignée');
        self::assertSame(OwnerType::Individual, $other->type);
        self::assertFalse($other->hasStripeKey());
        self::assertSame('', $other->address);
        self::assertStringNotContainsString('ABCdef', print_r($report, true), 'aucune clé dans le bilan');
    }

    public function testExistingOwnersAndPropertiesAreNeverModified(): void
    {
        $before = $this->owners->rows;
        $beforeProperties = $this->properties->rows;

        $report = $this->importer->import(
            [self::owner('G-KNOWN', 'P - Pirate', 'Changé', ['stripe_api_key' => self::KEY, 'conciergerie_commission' => 99.0])],
            [self::listing('L-KNOWN', 'Pirate', 'G-KNOWN', 999.0, 999.0)],
            true,
        );

        self::assertSame([], $report->owners);
        self::assertSame([], $report->properties);
        self::assertSame([1, 1], [$report->ownersPresent, $report->propertiesPresent]);
        self::assertEquals($before, $this->owners->rows);
        self::assertEquals($beforeProperties, $this->properties->rows);
    }

    public function testPropertiesAreCreatedArchivedAndLinkedToTheirOwner(): void
    {
        $report = $this->importer->import(
            [self::owner('G-NEW', 'P - Jeanne', 'Depot')],
            [
                self::listing('L-1', ' Fesch  Grand ', 'G-NEW', 48.0, 17.0),     // propriétaire créé dans le même import
                self::listing('L-2', 'Studio 1', 'G-KNOWN', 20.5, 0.0),           // propriétaire déjà dans Casamiza
                self::listing('L-3', 'Studio 4', 'G-INCONNU', 20.0, 0.0),         // propriétaire inconnu
                self::listing('L-4', 'Test', '', 0.0, 0.0),                       // aucun propriétaire
            ],
            true,
        );

        $new = $this->created('G-NEW');
        self::assertNotNull($new);
        $one = $this->properties->byGuestyId('tenant-a', 'L-1');
        self::assertNotNull($one);
        self::assertSame(['Fesch Grand', '13 Rue Fesch', 48.0, 17.0, $new->id], [$one->name, $one->address, $one->cleaningFee, $one->linenFee, $one->ownerId]);
        self::assertTrue($one->isArchived());
        $two = $this->properties->byGuestyId('tenant-a', 'L-2');
        self::assertNotNull($two);
        self::assertSame(['o-known', 20.5], [$two->ownerId, $two->cleaningFee]);
        self::assertNull($this->properties->byGuestyId('tenant-a', 'L-3')?->ownerId);
        self::assertNull($this->properties->byGuestyId('tenant-a', 'L-4')?->ownerId);
        self::assertCount(4, $report->properties);
        self::assertSame([['Studio 4', 'logement sans propriétaire connu dans Casamiza'], ['Test', 'logement sans propriétaire connu dans Casamiza']], $report->warnings);
    }

    public function testSimulationWritesNothingAndARerunCreatesNothingMore(): void
    {
        $owners = [self::owner('G-NEW', 'P - Jeanne', 'Depot', ['stripe_api_key' => self::KEY])];
        $listings = [self::listing('L-1', 'Fesch', 'G-NEW')];

        $simulated = $this->importer->import($owners, $listings, false);
        self::assertFalse($simulated->applied);
        self::assertCount(1, $simulated->owners);
        self::assertCount(1, $simulated->properties);
        self::assertNull($this->created('G-NEW'));
        self::assertNull($this->properties->byGuestyId('tenant-a', 'L-1'));

        $this->importer->import($owners, $listings, true);
        $rows = [$this->owners->rows, $this->properties->rows];

        $again = $this->importer->import($owners, $listings, true);
        self::assertSame([], $again->owners);
        self::assertSame([], $again->properties);
        self::assertEquals($rows, [$this->owners->rows, $this->properties->rows]);
    }

    public function testUnusableValuesAreReportedNotImported(): void
    {
        $report = $this->importer->import([
            self::owner('G-NEW', 'P - Jeanne', 'Depot', ['legal_siret' => '12345678901234567', 'stripe_api_key' => 'pas-une-cle']),
        ], [], true);

        $new = $this->created('G-NEW');
        self::assertNotNull($new);
        self::assertSame('', $new->siret);
        self::assertFalse($new->hasStripeKey());
        self::assertSame([['Jeanne Depot', 'SIRET « 12345678901234567 » non repris (ni 9 ni 14 chiffres)'], ['Jeanne Depot', 'clé Stripe de format inattendu non reprise']], $report->warnings);
    }
}
