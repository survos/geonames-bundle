#!/usr/bin/env php
<?php

declare(strict_types=1);

use Survos\GeonamesAdmin\AdminConfig;
use Survos\GeonamesAdmin\Command\BuildCommand;
use Survos\GeonamesAdmin\Command\DownloadCommand;
use Survos\GeonamesAdmin\Command\MetadataCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$adminDir = __DIR__;
$envPath = $adminDir . '/.env';

if (!class_exists(Dotenv::class)) {
    throw new RuntimeException('symfony/dotenv is required for the admin console.');
}
if (!is_file($envPath)) {
    throw new RuntimeException(sprintf('Missing required env file: %s', $envPath));
}

$dotenv = new Dotenv();
$dotenv->load($envPath);

$localEnvPath = $adminDir . '/.env.local';
if (is_file($localEnvPath)) {
    $dotenv->load($localEnvPath);
}

$config = AdminConfig::fromAdminDir($adminDir);
$application = new Application('geonames-admin', '0.3.0');
$application->addCommands([
    new DownloadCommand($config),
    new BuildCommand($config),
    new MetadataCommand($config),
]);
$application->run();
