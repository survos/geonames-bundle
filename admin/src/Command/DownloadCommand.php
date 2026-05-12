<?php

declare(strict_types=1);

namespace Survos\GeonamesAdmin\Command;

use Survos\GeonamesAdmin\AdminConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;

use function Survos\GeonamesAdmin\allCountryCodes;
use function Survos\GeonamesAdmin\allCountryCodesFromEndpoint;
use function Survos\GeonamesAdmin\arrayValuesUniqueUpper;
use function Survos\GeonamesAdmin\downloadToFile;

#[AsCommand('download', 'Download GeoNames source files and country archives.')]
final class DownloadCommand
{
    private const DEFAULT_FILES = [
        'countryInfo.txt',
        'admin1CodesASCII.txt',
        'admin2Codes.txt',
        'timeZones.txt',
        'hierarchy.zip',
    ];

    public function __construct(
        private readonly AdminConfig $config,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('GeoNames base download URL')] ?string $endpoint = null,
        #[Option('Directory where downloaded files should be stored')] ?string $downloadDir = null,
        #[Option('Download again even when the local file already exists')] bool $force = false,
        #[Option('Specific GeoNames files to fetch; repeat for multiple')] array $file = [],
        #[Option('Country code archives to fetch; repeat or use ALL for every country')] array $country = ['ALL'],
    ): int {
        $endpoint    = rtrim($endpoint    ?? $this->config->endpoint,   '/') . '/';
        $downloadDir = rtrim($downloadDir ?? $this->config->sourceDir,  '/');
        $countries   = arrayValuesUniqueUpper($country);

        $filesystem = new Filesystem();
        $httpClient = HttpClient::create();
        $filesystem->mkdir($downloadDir, 0700);

        $selectedFiles = $file !== [] ? array_values(array_unique($file)) : self::DEFAULT_FILES;

        if (in_array('ALL', $countries, true)) {
            $countryInfoPath = $downloadDir . '/countryInfo.txt';
            $countries = is_file($countryInfoPath)
                ? allCountryCodes($countryInfoPath)
                : allCountryCodesFromEndpoint($httpClient, $endpoint . 'countryInfo.txt');
            $io->text(sprintf('Resolved ALL to %d country archives.', count($countries)));
        }

        foreach ($countries as $countryCode) {
            $selectedFiles[] = sprintf('%s.zip', $countryCode);
        }
        $selectedFiles = array_values(array_unique($selectedFiles));

        $io->title('GeoNames download');
        $io->text(sprintf('Endpoint: %s', $endpoint));
        $io->text(sprintf('Target directory: %s', $downloadDir));

        foreach ($selectedFiles as $filename) {
            $targetPath = $downloadDir . '/' . basename($filename);
            if (!$force && is_file($targetPath)) {
                $io->writeln(sprintf('Skipping %s, already present.', basename($targetPath)));
                continue;
            }

            downloadToFile(
                httpClient: $httpClient,
                io: $io,
                url: $endpoint . ltrim($filename, '/'),
                targetPath: $targetPath,
                label: sprintf('Downloading %s', basename($targetPath)),
            );
        }

        $io->success('GeoNames files are available locally.');

        return Command::SUCCESS;
    }
}
