<?php

declare(strict_types=1);

namespace Casamiza\Http;

use Casamiza\Http\Response\JsonResponder;
use Casamiza\Shared\Exception\ApiException;
use Casamiza\Shared\Settings;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Interfaces\ErrorHandlerInterface;
use Throwable;

/**
 * Convertit toute exception en réponse JSON `{error: {code, fields?}}` (aucun texte rédigé).
 * Les erreurs inattendues ne révèlent rien au client (sauf en dev) : le détail va dans les logs.
 */
final readonly class ErrorHandler implements ErrorHandlerInterface
{
    /** Codes des erreurs levées par Slim lui-même (routage, méthode, corps de requête). */
    private const SLIM_CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'endpoint_not_found',
        405 => 'method_not_allowed',
        415 => 'unsupported_media_type',
    ];

    public function __construct(
        private ResponseFactoryInterface $responses,
        private JsonResponder $json,
        private LoggerInterface $logger,
        private Settings $settings,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $headers = [];
        $errors = [];

        if ($exception instanceof ApiException) {
            $status = $exception->status();
            $code = $exception->errorCode();
            $errors = $exception->errors();
        } elseif ($exception instanceof HttpException) {
            $status = $exception->getCode();
            $code = self::SLIM_CODES[$status] ?? 'http_error';
            if ($exception instanceof HttpMethodNotAllowedException) {
                $headers['Allow'] = implode(', ', $exception->getAllowedMethods());
            }
        } else {
            $status = 500;
            $code = 'internal_error';
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
        }

        // Aucun texte rédigé : le code fait foi, le front le traduit.
        // Le statut HTTP est la seule source du succès / échec : il n'est pas répété dans le corps.
        $error = ['code' => $code];
        if ($errors !== []) {
            $error['fields'] = $errors;
        }
        $body = ['error' => $error];
        if (!$this->settings->isProd() && $status === 500) {
            $body['debug'] = ['exception' => $exception::class, 'message' => $exception->getMessage()];
        }

        $response = $this->json->write(
            $this->responses->createResponse(),
            $body,
            $status,
        );
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
