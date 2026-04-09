#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';

$defaultFiles = [
    'countryInfo.txt',
    'admin1CodesASCII.txt',
    'admin2Codes.txt',
    'timeZones.txt',
    'hierarchy.zip',
    'allCountries.zip',
];

(new SingleCommandApplication())
    ->setName('download-geonames')
    ->setVersion('0.2.0')
    ->setCode(
        function (
            SymfonyStyle $io,
            #[Option('GeoNames base download URL.')]
            string $endpoint = 'https://download.geonames.org/export/dump/',
            #[Option('Directory where downloaded files should be stored.')]
            ?string $downloadDir = null,
            #[Option('Download again even when the local file already exists.')]
            bool $force = false,
            #[Option('Specific GeoNames files to fetch. Repeat the option to limit the download set.')]
            array $file = [],
        ) use ($defaultFiles): int {
            $filesystem = new Filesystem();
            $httpClient = HttpClient::create();

            $downloadDir ??= dirname(__DIR__) . '/var/geonames';
            $filesystem->mkdir($downloadDir, 0700);

            $files = $file !== [] ? array_values(array_unique($file)) : $defaultFiles;
            $endpoint = rtrim($endpoint, '/') . '/';

            $io->title('GeoNames download');
            $io->text(sprintf('Endpoint: %s', $endpoint));
            $io->text(sprintf('Target directory: %s', $downloadDir));

            foreach ($files as $filename) {
                $targetPath = $downloadDir . '/' . basename($filename);
                if (!$force && is_file($targetPath)) {
                    $io->writeln(sprintf('Skipping %s, already present.', basename($targetPath)));
                    continue;
                }

                $progress = new ProgressBar($io, 100);
                $progress->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% Mem: %memory:6s% %message%');
                $progress->setMessage(sprintf('Downloading %s', basename($targetPath)));
                $progress->start();

                $response = $httpClient->request('GET', $endpoint . ltrim($filename, '/'), [
                    'on_progress' => static function (int $downloadedSize, int $totalSize) use ($progress): void {
                        if ($totalSize > 0) {
                            $progress->setProgress((int) min(100, round(($downloadedSize / $totalSize) * 100)));
                        }
                    },
                ]);

                $handle = fopen($targetPath, 'wb');
                if (false === $handle) {
                    throw new RuntimeException(sprintf('Unable to open "%s" for writing.', $targetPath));
                }

                foreach ($httpClient->stream($response) as $chunk) {
                    if ($chunk->isTimeout()) {
                        continue;
                    }

                    fwrite($handle, $chunk->getContent());
                }

                fclose($handle);
                $progress->setProgress(100);
                $progress->finish();
                $io->newLine(2);
            }

            $io->success('GeoNames files are available locally.');

            return Command::SUCCESS;
        }
    )
    ->run();
