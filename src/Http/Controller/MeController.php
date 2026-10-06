<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Auth\AccessClaims;
use Casamiza\Domain\User\UserService;
use Casamiza\Http\Request\Input;
use Casamiza\Http\Resource\UserResource;
use Casamiza\Http\Response\JsonResponder;
use Casamiza\Shared\Exception\UnauthorizedException;
use Casamiza\Shared\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class MeController
{
    public function __construct(private UserService $users, private JsonResponder $json) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$user, $tenant] = $this->users->profile($this->claims($request)->userId);

        return $this->json->data($response, UserResource::toArray($user, $tenant));
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $firstName = $input->optionalString('firstName', 100);
        $lastName = $input->optionalString('lastName', 100);
        $input->assertValid();
        if ($firstName === null && $lastName === null) {
            throw new ValidationException(['firstName' => ['required']]);
        }

        [$user, $tenant] = $this->users->updateProfile($this->claims($request)->userId, $firstName, $lastName);

        return $this->json->data($response, UserResource::toArray($user, $tenant));
    }

    private function claims(ServerRequestInterface $request): AccessClaims
    {
        $claims = $request->getAttribute(AccessClaims::class);

        return $claims instanceof AccessClaims ? $claims : throw new UnauthorizedException('unauthenticated');
    }
}
