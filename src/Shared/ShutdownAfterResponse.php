<?php

declare(strict_types=1);

namespace Casamiza\Shared;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Traitement différé à la fin du script : sous PHP-FPM, `fastcgi_finish_request()` libère d'abord le client, puis le
 * traitement s'exécute. Sans FPM (serveur de développement), il s'exécute simplement à la fin de la requête.
 */
final readonly class ShutdownAfterResponse implements AfterResponse
{
    public function __construct(private LoggerInterface $logger) {}

    public function run(callable $work): void
    {
        $logger = $this->logger;
        register_shutdown_function(static function () use ($work, $logger): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                $work();
            } catch (Throwable $error) {
                $logger->error('Deferred work failed.', ['exception' => $error]);
            }
        });
    }
}
