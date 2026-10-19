<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Invoice\InvoiceRow;
use Casamiza\Domain\Search\SearchGroup;
use Casamiza\Domain\Search\SearchResults;
use DateTimeImmutable;

/**
 * Résultat de la recherche globale : par type, `{ items, total }`. Seul l'utile à l'affichage est renvoyé (ni coordonnées
 * de contact, ni montants détaillés, ni clé Stripe) ; le SIRET et la raison sociale ne sortent que pour un administrateur.
 */
final class SearchResource
{
    /** @return array<string, mixed> */
    public static function toArray(SearchResults $results, bool $admin, DateTimeImmutable $now): array
    {
        $data = [
            'owners' => self::group($results->owners, static fn(array $hit): array => [
                'id' => $hit['owner']->id,
                'name' => $hit['owner']->name,
                'email' => $hit['owner']->email,
                'type' => $hit['owner']->type->value,
                'companyName' => $admin ? $hit['owner']->companyName : null,
                'propertyCount' => $hit['propertyCount'],
                'active' => !$hit['owner']->isArchived(),
            ]),
            'properties' => self::group($results->properties, static fn(array $hit): array => [
                'id' => $hit['property']->id,
                'name' => $hit['property']->name,
                'address' => $hit['property']->address,
                'ownerId' => $hit['property']->ownerId,
                'ownerName' => $hit['ownerName'],
                'active' => !$hit['property']->isArchived(),
            ]),
            'reservations' => self::group($results->reservations, static function ($row): array {
                $r = $row->reservation;

                return [
                    'id' => $r->id,
                    'guest' => $r->guest,
                    'platform' => $r->platform->value,
                    'platformRef' => $r->platformRef === '' ? null : $r->platformRef,
                    'checkIn' => $r->checkIn->format('c'),
                    'checkOut' => $r->checkOut->format('c'),
                    'property' => $row->propertyName,
                    'ownerName' => $row->ownerName,
                ];
            }),
        ];
        if ($results->invoices !== null) {
            $data['invoices'] = self::group($results->invoices, static fn(InvoiceRow $row): array => [
                'id' => $row->invoice->id,
                'displayNumber' => $row->invoice->displayNumber(),
                'ownerId' => $row->invoice->ownerId,
                'ownerName' => $row->ownerName,
                'month' => $row->invoice->month,
                'amountTtc' => $row->invoice->amountTtc,
                'status' => InvoiceResource::toArray($row->invoice, $now)['status'],
            ]);
        }

        return $data;
    }

    /**
     * @template T
     * @param SearchGroup<T> $group
     * @param callable(T): array<string, mixed> $map
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private static function group(SearchGroup $group, callable $map): array
    {
        return ['items' => array_map($map, $group->items), 'total' => $group->total];
    }
}
