<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Audit\AuditAction;
use Casamiza\Domain\Audit\AuditFamily;
use Casamiza\Domain\Audit\AuditFilter;
use Casamiza\Domain\Audit\AuditService;
use Casamiza\Domain\Statement\BillingMonth;
use Casamiza\Http\Request\Input;
use Casamiza\Http\Resource\AuditEntryResource;
use Casamiza\Http\Response\JsonResponder;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AuditLogController
{
    public function __construct(
        private AuditService $audit,
        private JsonResponder $json,
    ) {}

    /**
     * Journal du tenant, du plus récent au plus ancien. Paramètres (tous facultatifs) : `action` et `family` (valeurs séparées
     * par des virgules), `actorId`, `targetType`, `from` et `to` (jours `AAAA-MM-JJ` à Paris, `to` inclus), `page`, `perPage`.
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromBody($request->getQueryParams());
        $input->allowOnly('action', 'family', 'actorId', 'targetType', 'from', 'to', 'page', 'perPage');

        $actions = [];
        foreach ($input->stringList('action', 30, 50) as $value) {
            $action = AuditAction::tryFrom($value);
            if ($action === null) {
                $input->fail('action', 'invalid_value');
                break;
            }
            $actions[$action->value] = $action;
        }
        // Une famille ajoute toutes ses actions ; sans action ni famille, aucun filtre.
        foreach ($input->stringList('family', 5, 20) as $value) {
            $family = AuditFamily::tryFrom($value);
            if ($family === null) {
                $input->fail('family', 'invalid_value');
                break;
            }
            foreach (AuditAction::cases() as $case) {
                if ($case->family() === $family) {
                    $actions[$case->value] = $case;
                }
            }
        }

        $from = $this->parisDay($input->optionalDate('from'));
        $to = $this->parisDay($input->optionalDate('to'))?->modify('+1 day');
        $page = $input->integer('page', 1, 100_000, 1);
        $perPage = $input->integer('perPage', 1, 200, 50);
        $filter = new AuditFilter(
            array_values($actions),
            $input->optionalString('actorId', 36),
            $input->optionalString('targetType', 30),
            $from,
            $to,
            $page,
            $perPage,
        );
        $input->assertValid();

        return $this->json->data($response, AuditEntryResource::page($this->audit->search($filter), $page, $perPage));
    }

    /** Minuit à Paris du jour donné (`optionalDate` renvoie minuit UTC du même jour), exprimé en UTC. */
    private function parisDay(?DateTimeImmutable $day): ?DateTimeImmutable
    {
        if ($day === null) {
            return null;
        }

        return (new DateTimeImmutable($day->format('Y-m-d') . ' 00:00:00', new DateTimeZone(BillingMonth::TIMEZONE)))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
