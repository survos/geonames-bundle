<?php

declare(strict_types=1);

namespace Survos\GeonamesAdmin\Command;

use Survos\GeonamesAdmin\AdminConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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

final class BuildCommand extends Command
{
    public function __construct(
        private readonly AdminConfig $config,
    ) {
        parent::__construct('build');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Rebuild GeoNames SQLite files when --force is passed; otherwise refresh metadata only.')
            ->addOption('source-dir', null, InputOption::VALUE_REQUIRED, 'Directory containing downloaded GeoNames source files.', $this->config->sourceDir)
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Directory where SQLite files should be written.', $this->config->outputDir)
            ->addOption('hf-account', null, InputOption::VALUE_REQUIRED, 'Hugging Face account or org name to use in generated dataset card examples.', $this->config->hfAccount)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Rebuild SQLite databases before refreshing metadata.')
            ->addOption('country', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Country code to rebuild. Use ALL for every country database.', ['ALL']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filesystem = new Filesystem();

        $sourceDir = rtrim((string) $input->getOption('source-dir'), '/');
        $outputDir = rtrim((string) $input->getOption('output-dir'), '/');
        $hfAccount = (string) $input->getOption('hf-account');
        $force = (bool) $input->getOption('force');
        $countries = arrayValuesUniqueUpper(is_array($input->getOption('country')) ? $input->getOption('country') : []);

        $filesystem->mkdir($outputDir, 0700);

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
