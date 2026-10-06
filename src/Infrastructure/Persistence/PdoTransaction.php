<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Shared\Transaction;
use Throwable;

final readonly class PdoTransaction implements Transaction
{
    public function __construct(private Database $database) {}

    public function run(callable $work): mixed
    {
        $pdo = $this->database->pdo();
        if ($pdo->inTransaction()) {
            return $work();
        }

        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
