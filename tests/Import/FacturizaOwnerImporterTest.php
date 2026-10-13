<?php

declare(strict_types=1);

namespace Casamiza\Tests\Import;

use Casamiza\Domain\Import\FacturizaOwnerImporter;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Infrastructure\Security\Encryptor;
use Casamiza\Shared\TenantContext;
use Casamiza\Tests\Support\ImmediateTransaction;
use Casamiza\Tests\Support\InMemoryOwners;
use PHPUnit\Framework\TestCase;

final class FacturizaOwnerImporterTest extends TestCase
{
    private TenantContext $context;
    private InMemoryOwners $owners;
    private FacturizaOwnerImporter $importer;

    protected function setUp(): void
    {
        $this->context = new TenantContext();
        $this->context->set('tenant-a');
        $this->owners = new InMemoryOwners($this->context);
        $this->importer = new FacturizaOwnerImporter($this->owners, new ImmediateTransaction(), new Encryptor(str_repeat('k', 32)));

        $this->add('o1', 'G1', 'Alice Martin');
        $this->add('o2', 'G2', 'Bob Durand');
        $this->add('o3', 'G3', 'Carol Poli', company: 'SCI Carol', siret: '11111111111111');
        $this->add('o4', 'G4', 'Dan Sorba');
        // Même identifiant Guesty chez un autre tenant : ne doit jamais être touché.
        $this->context->reset();
        $this->context->set('tenant-b');
        $this->add('ob', 'G1', 'Autre Tenant');
        $this->context->reset();
        $this->context->set('tenant-a');
    }

    /** @param non-empty-string $id */
    private function add(string $id, string $guestyId, string $name, string $company = '', string $siret = ''): void
    {
        $this->owners->add(new Owner($id, $guestyId, $name, '', '', '', '', '', OwnerType::Company, $company, $siret, 20.0, null, null));
    }

    /** @return array<string, string|int|float|null> */
    private static function legacy(string $guestyId, ?string $siret, ?string $name): array
    {
        return ['user_id' => 2, 'guesty_owner_id' => $guestyId, 'first_name' => 'P - X', 'last_name' => 'Y', 'legal_siret' => $siret, 'legal_name' => $name];
    }

    public function testSimulationWritesNothing(): void
    {
        $report = $this->importer->import([self::legacy('G1', '123 456 789 01234', ' SARL  Alice ')], false);

        self::assertFalse($report->applied);
        self::assertSame([['Alice Martin', 'SIRET = 12345678901234, raison sociale = SARL Alice']], $report->updated);
        self::assertSame('', $this->owners->rows['tenant-a|o1']->siret);
        self::assertSame('', $this->owners->rows['tenant-a|o1']->companyName);
    }

    public function testMissingValuesAreWrittenNormalizedAndOnlyForTheCurrentTenant(): void
    {
        $report = $this->importer->import([self::legacy('G1', '123 456 789 01234', ' SARL  Alice ')], true);

        self::assertTrue($report->applied);
        self::assertSame('12345678901234', $this->owners->rows['tenant-a|o1']->siret);
        self::assertSame('SARL Alice', $this->owners->rows['tenant-a|o1']->companyName);
        self::assertSame('', $this->owners->rows['tenant-b|ob']->siret);
        // Les champs Guesty ne bougent pas.
        self::assertSame('Alice Martin', $this->owners->rows['tenant-a|o1']->name);
        self::assertSame(20.0, $this->owners->rows['tenant-a|o1']->commission);
    }

    public function testRunningTwiceChangesNothingMore(): void
    {
        $rows = [self::legacy('G1', '12345678901234', 'SARL Alice'), self::legacy('G2', null, 'Bob SAS')];
        $this->importer->import($rows, true);
        $before = $this->owners->rows;
        $second = $this->importer->import($rows, true);

        self::assertSame([], $second->updated);
        self::assertCount(3, $second->unchanged);
        self::assertEquals($before, $this->owners->rows);
    }

    public function testALocalValueIsNeverOverwritten(): void
    {
        $report = $this->importer->import([self::legacy('G3', '22222222222222', 'SCI CAROL ANCIENNE')], true);

        self::assertSame([], $report->updated);
        self::assertCount(2, $report->conflicts);
        self::assertSame('11111111111111', $this->owners->rows['tenant-a|o3']->siret);
        self::assertSame('SCI Carol', $this->owners->rows['tenant-a|o3']->companyName);
    }

    public function testASirenOfNineDigitsIsTakenAsIs(): void
    {
        $report = $this->importer->import([self::legacy('G4', '978 161 115', 'U PISALI')], true);

        self::assertSame([], $report->invalid);
        self::assertSame('978161115', $this->owners->rows['tenant-a|o4']->siret);
        self::assertSame('U PISALI', $this->owners->rows['tenant-a|o4']->companyName);
    }

    public function testAnUnusableSiretIsReportedAndOnlyTheCompanyNameIsTaken(): void
    {
        $report = $this->importer->import([
            self::legacy('G1', '95350873', 'SARL Alice'),         // 8 chiffres
            self::legacy('G2', '12345678901234567', null),        // valeur de test (17 chiffres)
        ], true);

        self::assertSame([['Alice Martin', 'SIRET « 95350873 » (8 chiffres)'], ['Bob Durand', 'SIRET « 12345678901234567 » (17 chiffres)']], $report->invalid);
        self::assertSame('', $this->owners->rows['tenant-a|o1']->siret);
        self::assertSame('SARL Alice', $this->owners->rows['tenant-a|o1']->companyName);
        self::assertSame('', $this->owners->rows['tenant-a|o2']->siret);
        self::assertSame('', $this->owners->rows['tenant-a|o2']->companyName);
    }

    /** @return array<string, string|int|float|null> */
    private static function withKey(string $guestyId, ?string $key): array
    {
        return self::legacy($guestyId, null, null) + ['stripe_api_key' => $key];
    }

    public function testStripeKeysAreIgnoredUnlessRequested(): void
    {
        $report = $this->importer->import([self::withKey('G1', 'sk_live_51ABCdefGHIjklMNOpqr')], true);

        self::assertSame(0, $report->legacyWithData);
        self::assertNull($this->owners->rows['tenant-a|o1']->stripeSecretEncrypted);
    }

    public function testStripeKeysAreEncryptedAndNeverShownInTheReport(): void
    {
        $secret = 'sk_live_51ABCdefGHIjklMNOpqr';
        $restricted = 'rk_live_51ZZZyyyXXXwwwVVVuuu';
        $rows = [self::withKey('G1', $secret), self::withKey('G2', $restricted)];

        $simulated = $this->importer->import($rows, false, true);
        self::assertNull($this->owners->rows['tenant-a|o1']->stripeSecretEncrypted, 'simulation : rien d\'écrit');
        self::assertSame([['Alice Martin', 'clé Stripe sk_live'], ['Bob Durand', 'clé Stripe rk_live']], $simulated->updated);

        $report = $this->importer->import($rows, true, true);

        $stored = $this->owners->rows['tenant-a|o1']->stripeSecretEncrypted;
        self::assertNotNull($stored);
        self::assertStringNotContainsString($secret, $stored);
        self::assertSame($secret, (new Encryptor(str_repeat('k', 32)))->decrypt($stored));
        self::assertSame($restricted, (new Encryptor(str_repeat('k', 32)))->decrypt((string) $this->owners->rows['tenant-a|o2']->stripeSecretEncrypted));
        // Ni clé en clair ni fragment de clé dans le bilan.
        self::assertStringNotContainsString('ABCdef', print_r($report, true));
        self::assertStringNotContainsString('ZZZyyy', print_r($report, true));
        // Un autre tenant n'est pas touché.
        self::assertNull($this->owners->rows['tenant-b|ob']->stripeSecretEncrypted);
    }

    public function testAnExistingKeyIsNeverOverwrittenAndRerunsAreSilent(): void
    {
        $rows = [self::withKey('G1', 'sk_live_51ABCdefGHIjklMNOpqr')];
        $this->importer->import($rows, true, true);
        $before = $this->owners->rows['tenant-a|o1']->stripeSecretEncrypted;

        $again = $this->importer->import($rows, true, true);
        self::assertSame([], $again->updated);
        self::assertSame([['Alice Martin', 'clé Stripe déjà à jour']], $again->unchanged);
        self::assertSame($before, $this->owners->rows['tenant-a|o1']->stripeSecretEncrypted);

        $other = $this->importer->import([self::withKey('G1', 'sk_live_51OTHERkeyOTHERkeyOTH')], true, true);
        self::assertSame([], $other->updated);
        self::assertSame([['Alice Martin', 'clé Stripe : celle de Casamiza est conservée, Facturiza en a une autre (sk_live)']], $other->conflicts);
        self::assertSame($before, $this->owners->rows['tenant-a|o1']->stripeSecretEncrypted);
    }

    public function testStripeOnlyLeavesIdentityFieldsAlone(): void
    {
        $row = self::legacy('G1', '12345678901234', 'SARL Alice') + ['stripe_api_key' => 'sk_live_51ABCdefGHIjklMNOpqr'];

        $report = $this->importer->import([$row], true, true, false);

        self::assertSame([['Alice Martin', 'clé Stripe sk_live']], $report->updated);
        self::assertSame('', $this->owners->rows['tenant-a|o1']->siret);
        self::assertSame('', $this->owners->rows['tenant-a|o1']->companyName);
        self::assertTrue($this->owners->rows['tenant-a|o1']->hasStripeKey());
    }

    public function testAMalformedKeyIsReportedWithoutItsContent(): void
    {
        $report = $this->importer->import([self::withKey('G1', 'pas-une-cle-stripe-du-tout')], true, true);

        self::assertSame([['Alice Martin', 'clé Stripe de format inattendu (26 caractères)']], $report->invalid);
        self::assertNull($this->owners->rows['tenant-a|o1']->stripeSecretEncrypted);
    }

    public function testEmptyRowsAndUnknownOwnersAreHandled(): void
    {
        $report = $this->importer->import([
            self::legacy('G4', '', ''),               // rien à reprendre : ignoré, non compté
            self::legacy('G4', null, null),
            self::legacy('INCONNU', '12345678901234', 'Fantôme'),
        ], true);

        self::assertSame(1, $report->legacyWithData);
        self::assertCount(1, $report->notFound);
        self::assertSame([], $report->updated);
        self::assertSame('', $this->owners->rows['tenant-a|o4']->siret);
    }
}
