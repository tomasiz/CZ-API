<?php

declare(strict_types=1);

namespace Casamiza\Http\Controller;

use Casamiza\Domain\Integration\IntegrationService;
use Casamiza\Domain\Integration\ProviderRegistry;
use Casamiza\Http\Request\Input;
use Casamiza\Http\Resource\IntegrationResource;
use Casamiza\Http\Response\JsonResponder;
use Casamiza\Shared\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class IntegrationController
{
    public function __construct(
        private IntegrationService $integrations,
        private ProviderRegistry $providers,
        private JsonResponder $json,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json->data($response, array_map(IntegrationResource::toArray(...), $this->integrations->list()));
    }

    /**
     * Contrôle en direct (le résultat, même en erreur, est un 200 : c'est l'état de la connexion).
     *
     * @param array<string, string> $args
     */
    public function check(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->json->data($response, IntegrationResource::toArray($this->integrations->check($args['provider'])));
    }

    /** @param array<string, string> $args */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $fields = $this->providers->get($args['provider'])->fields();
        $body = $request->getParsedBody();

        // Champs inconnus rejetés (anti mass-assignment).
        $errors = [];
        foreach (is_array($body) ? array_keys($body) : [] as $key) {
            if (!in_array($key, array_map(static fn($f): string => $f->key, $fields), true)) {
                $errors[(string) $key] = ['unknown_field'];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // Vide ou absent = inchangé pour un secret (vérifié par le service).
        $input = Input::fromBody($body);
        $values = [];
        foreach ($fields as $field) {
            $values[$field->key] = $input->optionalString($field->key, $field->maxLength) ?? '';
        }
        $input->assertValid();

        return $this->json->data($response, IntegrationResource::toArray($this->integrations->save($args['provider'], $values)));
    }

    /** @param array<string, string> $args */
    public function remove(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->integrations->remove($args['provider']);

        return $this->json->noContent($response);
    }
}
