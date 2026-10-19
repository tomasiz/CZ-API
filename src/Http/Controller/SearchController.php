<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Auth\AccessClaims;
use Casamiza\Domain\Search\SearchService;
use Casamiza\Domain\User\Role;
use Casamiza\Http\Request\Input;
use Casamiza\Http\Resource\SearchResource;
use Casamiza\Http\Response\JsonResponder;
use Casamiza\Shared\Exception\UnauthorizedException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class SearchController
{
    public function __construct(
        private SearchService $search,
        private ClockInterface $clock,
        private JsonResponder $json,
    ) {}

    /** `q` (2 à 100 caractères) et `limit` (1 à 20, 5 par défaut) : résultats par type d'élément. */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $claims = $request->getAttribute(AccessClaims::class);
        if (!$claims instanceof AccessClaims) {
            throw new UnauthorizedException('unauthenticated');
        }
        $input = Input::fromBody($request->getQueryParams());
        $input->allowOnly('q', 'limit');
        $q = $input->string('q', 100);
        if ($q !== '' && mb_strlen($q) < SearchService::MIN_LENGTH) {
            $input->fail('q', 'too_short');
        }
        $limit = $input->integer('limit', 1, 20, 5);
        $input->assertValid();

        $admin = $claims->role === Role::Admin;

        return $this->json->data($response, SearchResource::toArray($this->search->search($q, $limit, $admin), $admin, $this->clock->now()));
    }
}
