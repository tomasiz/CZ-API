<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Auth\AuthService;
use Casamiza\Domain\Auth\AuthSession;
use Casamiza\Domain\Auth\PasswordResetService;
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
        private PasswordResetService $passwordReset,
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

    /** Identité du membre invité pour la page d'activation (le jeton, secret de 256 bits, est dans le corps, pas dans l'URL). */
    public function inspectActivation(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $token = $input->string('token', 200);
        $input->assertValid();

        $user = $this->invitations->inspect($token);

        return $this->json->data($response, [
            'firstName' => $user->firstName,
            'lastName' => $user->lastName,
            'email' => $user->email,
        ]);
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

    /** Demande de lien de réinitialisation : réponse identique (204) que l'adresse existe ou non. */
    public function forgotPassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $input->allowOnly('email');
        $email = $input->email('email');
        $input->assertValid();

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $this->passwordReset->request($email, is_string($ip) ? $ip : 'unknown');

        return $this->json->noContent($response);
    }

    /** Nouveau mot de passe choisi avec le jeton reçu par e-mail ; toutes les sessions du compte prennent fin. */
    public function resetPassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromBody($request->getParsedBody());
        $input->allowOnly('token', 'password');
        $token = $input->string('token', 200);
        $password = $input->string('password', 1024, trim: false);
        $input->assertValid();

        $this->passwordReset->reset($token, $password);

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
