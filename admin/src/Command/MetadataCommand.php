<?php

declare(strict_types=1);

namespace Survos\GeonamesAdmin\Command;

use Survos\GeonamesAdmin\AdminConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Survos\GeonamesAdmin\buildOutputSummary;
use function Survos\GeonamesAdmin\generatedSqliteFiles;
use function Survos\GeonamesAdmin\refreshMetadata;

final class MetadataCommand extends Command
{
    public function __construct(
        private readonly AdminConfig $config,
    ) {
        parent::__construct('metadata');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Refresh README.md and datasets.jsonl from the existing SQLite files.')
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Directory where SQLite files have been written.', $this->config->outputDir)
            ->addOption('hf-account', null, InputOption::VALUE_REQUIRED, 'Hugging Face account or org name to use in generated dataset card examples.', $this->config->hfAccount);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $outputDir = rtrim((string) $input->getOption('output-dir'), '/');
        $hfAccount = (string) $input->getOption('hf-account');

        $io->title('Refresh GeoNames metadata');
        $io->text(sprintf('Output directory: %s', $outputDir));

        refreshMetadata($outputDir, $hfAccount);
        $allFiles = generatedSqliteFiles($outputDir);

        $io->success('Dataset metadata refreshed.');
        $io->listing(buildOutputSummary($outputDir, array_merge(['README.md', 'datasets.jsonl'], $allFiles)));

        return Command::SUCCESS;
    }
}
