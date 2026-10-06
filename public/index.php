<?php

declare(strict_types=1);

use Casamiza\Kernel;
use Casamiza\Shared\Settings;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();

header_remove('X-Powered-By');

$settings = Settings::fromEnv($_ENV + $_SERVER, $root);
ini_set('display_errors', $settings->isProd() ? '0' : '1');

Kernel::createApp($settings)->run();
