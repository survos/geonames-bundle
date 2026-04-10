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
use Symfony\Component\HttpClient\HttpClient;

use function Survos\GeonamesAdmin\allCountryCodes;
use function Survos\GeonamesAdmin\allCountryCodesFromEndpoint;
use function Survos\GeonamesAdmin\arrayValuesUniqueUpper;
use function Survos\GeonamesAdmin\downloadToFile;

final class DownloadCommand extends Command
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
        parent::__construct('download');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Download GeoNames source files and country archives.')
            ->addOption('endpoint', null, InputOption::VALUE_REQUIRED, 'GeoNames base download URL.', $this->config->endpoint)
            ->addOption('download-dir', null, InputOption::VALUE_REQUIRED, 'Directory where downloaded files should be stored.', $this->config->sourceDir)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Download again even when the local file already exists.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Specific GeoNames files to fetch.')
            ->addOption('country', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Country code archives to fetch. Use ALL for every country archive.', ['ALL']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filesystem = new Filesystem();
        $httpClient = HttpClient::create();

        $endpoint = rtrim((string) $input->getOption('endpoint'), '/') . '/';
        $downloadDir = rtrim((string) $input->getOption('download-dir'), '/');
        $force = (bool) $input->getOption('force');
        $files = $input->getOption('file');
        $countries = arrayValuesUniqueUpper(is_array($input->getOption('country')) ? $input->getOption('country') : []);

        $filesystem->mkdir($downloadDir, 0700);

        $selectedFiles = $files !== [] ? array_values(array_unique($files)) : self::DEFAULT_FILES;
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
