<?php

declare(strict_types=1);

namespace Casamiza\Tests\Extra;

use Casamiza\Domain\Company\CompanySettings;
use Casamiza\Domain\Company\CompanySettingsPatch;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Tests\Auth\AuthTestCase;
use Psr\Http\Message\ResponseInterface;

/** Prestations supplémentaires : `/owners/{id}/extras`. */
final class ExtraTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->context->set('tenant-a');
        $this->owners->add(new Owner('owner-1', 'GO1', 'Alice Martin', 'Alice', 'Martin', '', '', '', OwnerType::Individual, '', '', 20.0, null, null));
        $this->owners->add(new Owner('owner-2', 'GO2', 'Bob Durand', 'Bob', 'Durand', '', '', '', OwnerType::Individual, '', '', 20.0, null, null));
        $this->context->reset();
        $this->context->set('tenant-b');
        $this->owners->add(new Owner('owner-b', 'GOB', 'Autre', 'Autre', 'Propriétaire', '', '', '', OwnerType::Individual, '', '', 20.0, null, null));
        $this->context->reset();
    }

    /** @param array<string, mixed>|null $body */
    private function call(string $method, string $path, ?array $body = null, string $email = 'alice@a.test'): ResponseInterface
    {
        return $this->request($this->app, $method, '/api/v1' . $path, $this->bearer($this->accessToken($email)), $body);
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response, int $status = 200): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        return $data;
    }

    /** @return array<string, mixed> */
    private function add(string $ownerId = 'owner-1', string $month = '2026-09', string $email = 'alice@a.test'): array
    {
        return $this->data($this->call('POST', "/owners/{$ownerId}/extras", [
            'month' => $month,
            'type' => 'linen',
            'description' => 'Lavage de draps',
            'priceTtc' => 120,
        ], $email), 201);
    }

    public function testNeedsAnAuthenticatedUser(): void
    {
        self::assertSame(401, $this->request($this->app, 'GET', '/api/v1/owners/owner-1/extras?month=2026-09')->getStatusCode());
    }

    public function testAnExtraIsCreatedWithTheTtcPriceAndTheDefaultVatOfItsType(): void
    {
        $extra = $this->add();

        self::assertSame('owner-1', $extra['ownerId']);
        self::assertSame('2026-09', $extra['month']);
        self::assertSame('linen', $extra['type']);
        self::assertEquals(120.0, $extra['priceTtc']);
        self::assertEquals(20.0, $extra['vatRate']);
        self::assertEqualsWithDelta(100.0, $extra['priceHt'], 0.0000001);
    }

    public function testTheDefaultVatComesFromTheCompanySettingsOfTheType(): void
    {
        $this->context->set('tenant-a');
        $this->company->save(CompanySettings::blank()->patched(new CompanySettingsPatch(vatRateCleaning: 10.0)));
        $this->context->reset();

        $cleaning = $this->data($this->call('POST', '/owners/owner-1/extras', ['month' => '2026-09', 'type' => 'cleaning', 'description' => 'Ménage', 'priceTtc' => 110]), 201);
        $linen = $this->add();

        self::assertEquals(10.0, $cleaning['vatRate']);
        self::assertEquals(20.0, $linen['vatRate']);
    }

    public function testAnExplicitVatRateWinsAndTheHtPriceIsNotRounded(): void
    {
        $extra = $this->data($this->call('POST', '/owners/owner-1/extras', ['month' => '2026-09', 'type' => 'concierge', 'description' => 'Conciergerie', 'priceTtc' => 50, 'vatRate' => 0]), 201);
        $other = $this->data($this->call('POST', '/owners/owner-1/extras', ['month' => '2026-09', 'type' => 'cleaning', 'description' => 'Ménage', 'priceTtc' => 46, 'vatRate' => 20]), 201);

        self::assertEquals(0.0, $extra['vatRate']);
        self::assertEquals(50.0, $extra['priceHt']);
        // 46 / 1,2 = 38,3333… : le HT est conservé tel quel, l'arrondi se fait à l'affichage.
        self::assertEqualsWithDelta(38.333333333, $other['priceHt'], 0.000001);
    }

    public function testACreditNoteWithANegativePriceIsAccepted(): void
    {
        $extra = $this->data($this->call('POST', '/owners/owner-1/extras', ['month' => '2026-09', 'type' => 'cleaning', 'description' => 'Avoir', 'priceTtc' => '-66,60']), 201);

        self::assertEquals(-66.6, $extra['priceTtc']);
    }

    public function testTheListHoldsTheMonthOfTheOwnerInEntryOrderWithTheProposedVatRates(): void
    {
        $first = $this->add();
        $this->add('owner-1', '2026-08');
        $this->add('owner-2');
        usleep(2000);
        $second = $this->data($this->call('POST', '/owners/owner-1/extras', ['month' => '2026-09', 'type' => 'cleaning', 'description' => 'Ménage', 'priceTtc' => 60]), 201);

        $list = $this->data($this->call('GET', '/owners/owner-1/extras?month=2026-09'));

        self::assertSame([$first['id'], $second['id']], array_column(is_array($list['items']) ? $list['items'] : [], 'id'));
        self::assertEquals(['concierge' => 20.0, 'linen' => 20.0, 'cleaning' => 20.0], $list['defaultVatRates']);
    }

    public function testManagersCanManageExtras(): void
    {
        $extra = $this->add('owner-1', '2026-09', 'marc@a.test');
        $this->data($this->call('PATCH', '/owners/owner-1/extras/' . $extra['id'], ['priceTtc' => 130], 'marc@a.test'));

        self::assertSame(204, $this->call('DELETE', '/owners/owner-1/extras/' . $extra['id'], null, 'marc@a.test')->getStatusCode());
    }

    public function testAnExtraIsPartiallyUpdated(): void
    {
        $extra = $this->add();

        $updated = $this->data($this->call('PATCH', '/owners/owner-1/extras/' . $extra['id'], ['description' => 'Draps et serviettes', 'vatRate' => 10]));

        self::assertSame('Draps et serviettes', $updated['description']);
        self::assertEquals(10.0, $updated['vatRate']);
        self::assertEquals(120.0, $updated['priceTtc']);
        self::assertSame('linen', $updated['type']);
        self::assertSame('2026-09', $updated['month']);
    }

    public function testADeletedExtraDisappearsFromTheList(): void
    {
        $extra = $this->add();

        self::assertSame(204, $this->call('DELETE', '/owners/owner-1/extras/' . $extra['id'])->getStatusCode());

        self::assertSame([], $this->data($this->call('GET', '/owners/owner-1/extras?month=2026-09'))['items']);
        self::assertSame(404, $this->call('PATCH', '/owners/owner-1/extras/' . $extra['id'], ['priceTtc' => 1])->getStatusCode());
    }

    public function testAnInvoicedMonthIsLocked(): void
    {
        $extra = $this->add();
        $this->invoiced->invoice('owner-1', '2026-09');

        foreach ([
            $this->call('POST', '/owners/owner-1/extras', ['month' => '2026-09', 'type' => 'linen', 'description' => 'Draps', 'priceTtc' => 10]),
            $this->call('PATCH', '/owners/owner-1/extras/' . $extra['id'], ['priceTtc' => 10]),
            $this->call('DELETE', '/owners/owner-1/extras/' . $extra['id']),
        ] as $response) {
            self::assertSame(409, $response->getStatusCode());
            self::assertSame('month_already_invoiced', $this->errorCode($response));
        }
        // Un autre mois du même propriétaire reste libre, et la lecture aussi.
        $this->add('owner-1', '2026-08');
        self::assertCount(1, $this->data($this->call('GET', '/owners/owner-1/extras?month=2026-09'))['items'] ?? []);
    }

    public function testAnExtraOfAnotherOwnerIsNotFound(): void
    {
        $extra = $this->add('owner-2');

        $response = $this->call('PATCH', '/owners/owner-1/extras/' . $extra['id'], ['priceTtc' => 1]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('extra_not_found', $this->errorCode($response));
    }

    public function testAnUnknownOwnerIsNotFound(): void
    {
        $response = $this->call('GET', '/owners/nobody/extras?month=2026-09');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('owner_not_found', $this->errorCode($response));
    }

    public function testTenantsAreIsolated(): void
    {
        $extra = $this->add();

        // Le tenant B ne voit ni le propriétaire de A, ni ses prestations.
        $other = 'bob@b.test';
        self::assertSame(404, $this->call('GET', '/owners/owner-1/extras?month=2026-09', null, $other)->getStatusCode());
        self::assertSame(404, $this->call('POST', '/owners/owner-1/extras', ['month' => '2026-09', 'type' => 'linen', 'description' => 'x', 'priceTtc' => 1], $other)->getStatusCode());
        self::assertSame(404, $this->call('PATCH', '/owners/owner-b/extras/' . $extra['id'], ['priceTtc' => 1], $other)->getStatusCode());
        self::assertSame(404, $this->call('DELETE', '/owners/owner-b/extras/' . $extra['id'], null, $other)->getStatusCode());
        self::assertSame([], $this->data($this->call('GET', '/owners/owner-b/extras?month=2026-09', null, $other))['items']);
        self::assertCount(1, $this->extras->rows);
    }

    public function testInvalidInputIsRejectedWithFieldCodes(): void
    {
        $response = $this->call('POST', '/owners/owner-1/extras', ['month' => '2024-12', 'type' => 'repair', 'description' => '', 'priceTtc' => 'abc', 'vatRate' => 150, 'owner' => 'x']);

        self::assertSame(422, $response->getStatusCode());
        $fields = $this->json($response)['error']['fields'] ?? [];
        self::assertIsArray($fields);
        self::assertEqualsCanonicalizing(['month', 'type', 'description', 'priceTtc', 'vatRate', 'owner'], array_keys($fields));
        self::assertSame(['invalid_value'], $fields['month']);
        self::assertSame(['unknown_field'], $fields['owner']);

        self::assertSame(422, $this->call('POST', '/owners/owner-1/extras', ['month' => '2026-09', 'type' => 'linen', 'description' => 'x', 'priceTtc' => 1.234])->getStatusCode());
        self::assertSame(422, $this->call('GET', '/owners/owner-1/extras')->getStatusCode());
        self::assertSame(422, $this->call('PATCH', '/owners/owner-1/extras/x', [])->getStatusCode());
    }

    public function testTheVatRatesAreSetInTheCompanySettingsByAdminsOnly(): void
    {
        $settings = $this->data($this->call('PATCH', '/settings/company', ['vatRateLinen' => 10, 'vatRateCleaning' => '5,5']));

        self::assertEquals(20.0, $settings['vatRateConcierge']);
        self::assertEquals(10.0, $settings['vatRateLinen']);
        self::assertEquals(5.5, $settings['vatRateCleaning']);
        self::assertSame(403, $this->call('PATCH', '/settings/company', ['vatRateLinen' => 10], 'marc@a.test')->getStatusCode());
        self::assertSame(422, $this->call('PATCH', '/settings/company', ['vatRateLinen' => 101])->getStatusCode());

        // Les taux proposés sont lisibles par un gestionnaire, via la liste des prestations.
        $list = $this->data($this->call('GET', '/owners/owner-1/extras?month=2026-09', null, 'marc@a.test'));
        self::assertEquals(['concierge' => 20.0, 'linen' => 10.0, 'cleaning' => 5.5], $list['defaultVatRates']);
    }
}
