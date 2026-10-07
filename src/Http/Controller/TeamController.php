<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Auth\AccessClaims;
use Casamiza\Domain\Team\ActivationLink;
use Casamiza\Domain\Team\Invitation;
use Casamiza\Domain\Team\InvitationService;
use Casamiza\Domain\Team\TeamService;
use Casamiza\Domain\User\Role;
use Casamiza\Http\Request\Input;
use Casamiza\Http\Resource\TeamMemberResource;
use Casamiza\Http\Response\JsonResponder;
use Casamiza\Shared\Exception\UnauthorizedException;
use Casamiza\Shared\Exception\ValidationException;
use DateTimeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class TeamController
{
    public function __construct(
        private TeamService $team,
        private InvitationService $invitations,
        private ActivationLink $link,
        private JsonResponder $json,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json->data($response, array_map(TeamMemberResource::toArray(...), $this->team->list()));
    }

    public function invite(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $firstName = $input->string('firstName', 100);
        $lastName = $input->string('lastName', 100);
        $email = $input->email('email');
        $role = $input->enum('role', Role::class);
        $input->assertValid();

        $invitation = $this->invitations->invite($firstName, $lastName, $email, $role ?? throw new \LogicException('Rôle validé par Input.'));

        return $this->json->data($response, $this->withInvitation($invitation), 201);
    }

    /**
     * Nouveau lien d'activation pour un membre invité (les précédents cessent de fonctionner).
     *
     * @param array<string, string> $args
     */
    public function resendInvitation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->json->data($response, $this->withInvitation($this->invitations->resend($args['id'])));
    }

    /** @param array<string, string> $args */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $firstName = $input->optionalString('firstName', 100);
        $lastName = $input->optionalString('lastName', 100);
        $role = $input->optionalEnum('role', Role::class);
        $input->assertValid();
        if ($firstName === null && $lastName === null && $role === null) {
            throw new ValidationException(['firstName' => ['required']]);
        }

        $member = $this->team->update($args['id'], $firstName, $lastName, $role);

        return $this->json->data($response, TeamMemberResource::toArray($member));
    }

    /** @param array<string, string> $args */
    public function remove(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $claims = $request->getAttribute(AccessClaims::class);
        if (!$claims instanceof AccessClaims) {
            throw new UnauthorizedException('unauthenticated');
        }

        $this->team->remove($claims->userId, $args['id']);

        return $this->json->noContent($response);
    }

    /**
     * Le lien porte le jeton en clair : il n'est renvoyé qu'à l'administrateur qui vient de le générer,
     * jamais par `GET /team` (la base ne garde que son hash).
     *
     * @return array<string, mixed>
     */
    private function withInvitation(Invitation $invitation): array
    {
        return TeamMemberResource::toArray($invitation->user) + [
            'invitation' => [
                'url' => $this->link->for($invitation->token),
                'expiresAt' => $invitation->expiresAt->format(DateTimeInterface::ATOM),
            ],
        ];
    }
}
