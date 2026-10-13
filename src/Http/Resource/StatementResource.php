<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Statement\StatementRow;

final class StatementResource
{
    /** @return array{ownerId: string, month: string, status: string} */
    public static function toArray(StatementRow $row): array
    {
        return ['ownerId' => $row->ownerId, 'month' => $row->month, 'status' => $row->status->value];
    }
}
