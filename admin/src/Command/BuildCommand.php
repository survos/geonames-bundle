<?php

declare(strict_types=1);

namespace Survos\GeonamesAdmin\Command;

use Survos\GeonamesAdmin\AdminConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

use function Survos\GeonamesAdmin\allCountryCodes;
use function Survos\GeonamesAdmin\arrayValuesUniqueUpper;
use function Survos\GeonamesAdmin\buildCityDatabase;
use function Survos\GeonamesAdmin\buildGeoDatabase;
use function Survos\GeonamesAdmin\buildOutputSummary;
use function Survos\GeonamesAdmin\generatedSqliteFiles;
use function Survos\GeonamesAdmin\refreshMetadata;
use function Survos\GeonamesAdmin\requireBuildSources;

#[AsCommand('build', 'Rebuild GeoNames SQLite files when --force is passed; otherwise refresh metadata only.')]
final class BuildCommand
{
    public function __construct(
        private readonly AdminConfig $config,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Directory containing downloaded GeoNames source files')] ?string $sourceDir = null,
        #[Option('Directory where SQLite files should be written')] ?string $outputDir = null,
        #[Option('Hugging Face account or org name for generated dataset card examples')] ?string $hfAccount = null,
        #[Option('Rebuild SQLite databases before refreshing metadata')] bool $force = false,
        #[Option('Country code to rebuild; repeat or use ALL for every country database')] array $country = ['ALL'],
    ): int {
        $sourceDir ??= $this->config->sourceDir;
        $outputDir ??= $this->config->outputDir;
        $hfAccount ??= $this->config->hfAccount;

        $sourceDir = rtrim($sourceDir, '/');
        $outputDir = rtrim($outputDir, '/');
        $countries = arrayValuesUniqueUpper($country);

        (new Filesystem())->mkdir($outputDir, 0700);

        $io->title('Build GeoNames SQLite databases');
        $io->text(sprintf('Source directory: %s', $sourceDir));
        $io->text(sprintf('Output directory: %s', $outputDir));
        $io->text(sprintf('Mode: %s', $force ? 'rebuild sqlite + refresh metadata' : 'refresh metadata only'));

        if ($force) {
            requireBuildSources($sourceDir);

            buildGeoDatabase(
                $outputDir . '/geo.sqlite',
                $sourceDir . '/countryInfo.txt',
                $sourceDir . '/admin1CodesASCII.txt',
                $sourceDir . '/admin2Codes.txt',
                $io,
            );

            if (in_array('ALL', $countries, true)) {
                $countries = allCountryCodes($sourceDir . '/countryInfo.txt');
                $io->text(sprintf('Resolved ALL to %d country databases.', count($countries)));
            }

            foreach ($countries as $countryCode) {
                buildCityDatabase(
                    $outputDir . '/' . strtolower($countryCode) . '.sqlite',
                    $sourceDir . '/' . strtoupper($countryCode) . '.zip',
                    $countryCode,
                    $io,
                );
            }
        }

        refreshMetadata($outputDir, $hfAccount);

        $allFiles = generatedSqliteFiles($outputDir);
        $io->success($force ? 'SQLite authority databases rebuilt and metadata refreshed.' : 'Dataset metadata refreshed.');
        $io->listing(buildOutputSummary($outputDir, array_merge(['README.md', 'datasets.jsonl'], $allFiles)));

        return Command::SUCCESS;
    }
}
