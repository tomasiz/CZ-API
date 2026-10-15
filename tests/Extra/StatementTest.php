<?php

declare(strict_types=1);

namespace Casamiza\Tests\Extra;

use Casamiza\Domain\Invoice\Invoice;
use Casamiza\Domain\Invoice\PaymentMethod;
use Casamiza\Domain\Invoice\PaymentStatus;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Statement\BillingMonth;
use Casamiza\Domain\Statement\Statement;
use Casamiza\Tests\Auth\AuthTestCase;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;

/** Statut de facturation (`GET /statements`) et « pas de facture ce mois-ci ». */
final class StatementTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Horloge figée au 7 octobre 2026 : le dernier mois facturable est septembre 2026.
        $this->context->set('tenant-a');
        $this->owners->add(new Owner('owner-1', 'GO1', 'Alice Martin', 'Alice', 'Martin', '', '', '', OwnerType::Individual, '', '', 20.0, null, null));
        $this->owners->add(new Owner('owner-2', 'GO2', 'Bob Durand', 'Bob', 'Durand', '', '', '', OwnerType::Individual, '', '', 20.0, null, new DateTimeImmutable('2026-01-01 UTC')));
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

    /** @return list<array<string, mixed>> */
    private function rows(string $query = '', string $email = 'alice@a.test'): array
    {
        $response = $this->call('GET', '/statements' . $query, null, $email);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        return array_values(array_filter($data, 'is_array'));
    }

    public function testBillingMonthsRunFromJanuary2025ToTheLastFinishedMonth(): void
    {
        $months = BillingMonth::until(new DateTimeImmutable('2026-10-07 UTC'));

        self::assertSame('2025-01', $months[0]);
        self::assertSame('2026-09', $months[array_key_last($months)]);
        self::assertCount(21, $months);
        self::assertTrue(BillingMonth::isValid('2025-01'));
        self::assertFalse(BillingMonth::isValid('2024-12'));
        self::assertFalse(BillingMonth::isValid('2026-13'));
        self::assertFalse(BillingMonth::isValid('2026-9'));
    }

    public function testNeedsAnAuthenticatedUser(): void
    {
        self::assertSame(401, $this->request($this->app, 'GET', '/api/v1/statements')->getStatusCode());
    }

    public function testEveryOwnerIsToInvoiceForAMonthByDefault(): void
    {
        self::assertSame([
            ['ownerId' => 'owner-1', 'month' => '2026-09', 'status' => 'to-invoice'],
            ['ownerId' => 'owner-2', 'month' => '2026-09', 'status' => 'to-invoice'],
        ], $this->rows('?month=2026-09'));
    }

    public function testAnOwnerListsAllBillingMonths(): void
    {
        $rows = $this->rows('?ownerId=owner-1');

        self::assertCount(21, $rows);
        self::assertSame('2025-01', $rows[0]['month']);
        self::assertSame('2026-09', $rows[20]['month']);
    }

    public function testNothingToInvoiceCanBeSetAndCleared(): void
    {
        $set = $this->call('PUT', '/owners/owner-1/statements/2026-09/nothing-to-invoice', ['value' => true]);

        self::assertSame(200, $set->getStatusCode(), (string) $set->getBody());
        self::assertSame(['ownerId' => 'owner-1', 'month' => '2026-09', 'status' => 'nothing-to-invoice'], $this->json($set)['data']);
        $statuses = array_column($this->rows('?month=2026-09'), 'status', 'ownerId');
        self::assertSame(['owner-1' => 'nothing-to-invoice', 'owner-2' => 'to-invoice'], $statuses);
        // Les autres mois ne sont pas touchés.
        self::assertSame('to-invoice', $this->rows('?ownerId=owner-1')[19]['status']);

        $cleared = $this->call('PUT', '/owners/owner-1/statements/2026-09/nothing-to-invoice', ['value' => false]);
        self::assertSame('to-invoice', $this->json($cleared)['data']['status'] ?? null);
        self::assertCount(1, $this->statements->rows);
    }

    public function testManagersCanSetNothingToInvoice(): void
    {
        self::assertSame(200, $this->call('PUT', '/owners/owner-1/statements/2026-09/nothing-to-invoice', ['value' => true], 'marc@a.test')->getStatusCode());
        self::assertSame('nothing-to-invoice', $this->rows('?ownerId=owner-1&month=2026-09', 'marc@a.test')[0]['status']);
    }

    public function testAnInvoicedMonthCannotBeToggled(): void
    {
        $this->invoiced->invoice('tenant-a', 'owner-1', '2026-09');

        $response = $this->call('PUT', '/owners/owner-1/statements/2026-09/nothing-to-invoice', ['value' => true]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('month_already_invoiced', $this->errorCode($response));
    }

    public function testInvoicesDecideTheStatusOfTheirMonth(): void
    {
        // Horloge au 7 octobre 2026.
        $this->context->reset();
        $this->context->set('tenant-a');
        $invoice = fn(string $month, string $due, PaymentStatus $payment = PaymentStatus::Unpaid, ?DateTimeImmutable $cancelled = null) => $this->invoices->add(
            new Invoice('inv-' . $month, $this->invoices->nextNumber(), 'owner-1', $month, new DateTimeImmutable($month . '-01 UTC'), new DateTimeImmutable($month . '-28 UTC'), new DateTimeImmutable($month . '-28 UTC'), new DateTimeImmutable($due . ' UTC'), 100.0, 20.0, 120.0, $payment, $payment === PaymentStatus::Paid ? PaymentMethod::Wire : null, null, $cancelled),
            [],
            [],
        );
        $invoice('2026-09', '2026-10-28');                                              // échéance à venir : facturée
        $invoice('2026-08', '2026-10-07');                                              // échéance aujourd'hui : pas encore en retard
        $invoice('2026-07', '2026-09-01');                                              // échéance dépassée : en retard
        $invoice('2026-06', '2026-07-01', PaymentStatus::Paid);                         // payée, même après l'échéance
        $invoice('2026-05', '2026-06-01', cancelled: new DateTimeImmutable('2026-06-02 UTC')); // annulée : redevient à facturer
        $invoice('2026-04', '2026-05-01');                                              // en retard, et marquée « rien à facturer » : la facture l'emporte
        $this->statements->save(new Statement('s-1', 'owner-1', '2026-04', true));
        $this->statements->save(new Statement('s-2', 'owner-1', '2026-03', true));
        $this->context->reset();

        $statuses = array_column($this->rows('?ownerId=owner-1'), 'status', 'month');

        self::assertEquals(
            ['2026-09' => 'invoiced', '2026-08' => 'invoiced', '2026-07' => 'overdue', '2026-06' => 'paid', '2026-05' => 'to-invoice', '2026-04' => 'overdue', '2026-03' => 'nothing-to-invoice', '2026-02' => 'to-invoice'],
            array_intersect_key($statuses, array_flip(['2026-09', '2026-08', '2026-07', '2026-06', '2026-05', '2026-04', '2026-03', '2026-02'])),
        );
        // Les factures d'un propriétaire ne déteignent pas sur un autre, ni sur un autre tenant.
        self::assertSame('to-invoice', array_column($this->rows('?ownerId=owner-2&month=2026-09'), 'status', 'month')['2026-09']);
        self::assertSame('to-invoice', $this->rows('?month=2026-09', 'bob@b.test')[0]['status']);
    }

    public function testInvalidRequestsAreRejected(): void
    {
        self::assertSame(422, $this->call('PUT', '/owners/owner-1/statements/2026-09/nothing-to-invoice', [])->getStatusCode());
        self::assertSame(422, $this->call('PUT', '/owners/owner-1/statements/2026-09/nothing-to-invoice', ['value' => 'yes'])->getStatusCode());
        self::assertSame(422, $this->call('PUT', '/owners/owner-1/statements/2024-01/nothing-to-invoice', ['value' => true])->getStatusCode());
        self::assertSame(422, $this->call('GET', '/statements?month=2026-9')->getStatusCode());
        self::assertSame(422, $this->call('GET', '/statements?foo=1')->getStatusCode());
        self::assertSame(404, $this->call('GET', '/statements?ownerId=nobody')->getStatusCode());
        self::assertSame(404, $this->call('PUT', '/owners/nobody/statements/2026-09/nothing-to-invoice', ['value' => true])->getStatusCode());
    }

    public function testTenantsAreIsolated(): void
    {
        $this->call('PUT', '/owners/owner-1/statements/2026-09/nothing-to-invoice', ['value' => true]);

        // B ne voit que ses propriétaires, avec un statut vierge, et ne peut pas toucher aux propriétaires de A.
        self::assertSame([['ownerId' => 'owner-b', 'month' => '2026-09', 'status' => 'to-invoice']], $this->rows('?month=2026-09', 'bob@b.test'));
        self::assertSame(404, $this->call('PUT', '/owners/owner-1/statements/2026-09/nothing-to-invoice', ['value' => false], 'bob@b.test')->getStatusCode());
        self::assertSame(404, $this->call('GET', '/statements?ownerId=owner-1', null, 'bob@b.test')->getStatusCode());
        self::assertCount(1, $this->statements->rows);
    }
}
