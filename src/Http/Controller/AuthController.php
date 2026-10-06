<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Auth\AuthService;
use Casamiza\Domain\Auth\AuthSession;
use Casamiza\Domain\Team\InvitationService;
use Casamiza\Http\Cookie\RefreshCookie;
use Casamiza\Http\Request\Input;
use Casamiza\Http\Resource\UserResource;
use Casamiza\Http\Response\JsonResponder;
use Casamiza\Shared\Exception\UnauthorizedException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AuthController
{
    public function __construct(
        private AuthService $auth,
        private JsonResponder $json,
        private RefreshCookie $cookie,
        private InvitationService $invitations,
    ) {}

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $email = $input->email('email');
        $password = $input->string('password', 1024, trim: false);
        $input->assertValid();

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';

        return $this->respond($response, $this->auth->login($email, $password, is_string($ip) ? $ip : 'unknown'));
    }

    public function refresh(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = $this->cookie->read($request) ?? throw new UnauthorizedException('invalid_refresh_token');

        return $this->respond($response, $this->auth->refresh($token));
    }

    /** Activation d'un compte invité : le membre choisit son mot de passe avec le jeton reçu par e-mail. */
    public function activate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $token = $input->string('token', 200);
        $password = $input->string('password', 1024, trim: false);
        $input->assertValid();

        $this->invitations->activate($token, $password);

        return $this->json->noContent($response);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = $this->cookie->read($request);
        if ($token !== null) {
            $this->auth->logout($token);
        }

        return $this->cookie->clear($this->json->noContent($response));
    }

    private function respond(ResponseInterface $response, AuthSession $session): ResponseInterface
    {
        $response = $this->json->data($response, [
            'accessToken' => $session->accessToken,
            'expiresIn' => $session->accessExpiresIn,
            'user' => UserResource::toArray($session->user, $session->tenant),
        ]);

        return $this->cookie->issue($response, $session->refreshToken, $session->refreshExpiresAt);
    }
}
