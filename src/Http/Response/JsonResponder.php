<?php

declare(strict_types=1);

namespace Casamiza\Http\Response;

use Psr\Http\Message\ResponseInterface;

/** Construit les réponses JSON : succès enveloppés dans `data`, erreurs en RFC 7807. */
final class JsonResponder
{
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function data(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        return $this->write($response, ['data' => $data], $status, 'application/json');
    }

    public function noContent(ResponseInterface $response): ResponseInterface
    {
        return $response->withStatus(204);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function write(
        ResponseInterface $response,
        array $body,
        int $status,
        string $contentType = 'application/json',
    ): ResponseInterface {
        $response->getBody()->write(json_encode($body, self::FLAGS));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', $contentType . '; charset=utf-8');
    }
}
