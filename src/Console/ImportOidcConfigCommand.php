<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Console;

use MartinKuhl\Sw6Oidc\Service\Config\OidcConfigTransfer;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sw6oidc:config:import',
    description: 'Import OIDC provider configuration exported by sw6oidc:config:export',
)]
class ImportOidcConfigCommand extends Command
{
    public function __construct(private readonly OidcConfigTransfer $transfer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Export file to import ("-" for stdin)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate everything (including SSRF/lockout checks) and roll back')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Replace providers that already exist (same id), including their mappings')
            ->addOption('skip-unresolved', null, InputOption::VALUE_NONE, 'Drop ACL role / customer group references that do not exist here instead of failing the provider');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $input->getOption('input');

        if (!\is_string($file) || $file === '') {
            $io->error('--input is required.');

            return self::INVALID;
        }

        $json = $file === '-' ? stream_get_contents(\STDIN) : @file_get_contents($file);

        if (!\is_string($json)) {
            $io->error(sprintf('Could not read "%s".', $file));

            return self::FAILURE;
        }

        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

            if (!\is_array($data)) {
                throw new \InvalidArgumentException('The file does not contain a JSON object.');
            }

            $dryRun = (bool) $input->getOption('dry-run');
            $result = $this->transfer->import(
                $data,
                (bool) $input->getOption('overwrite'),
                (bool) $input->getOption('skip-unresolved'),
                $dryRun,
                Context::createCLIContext(),
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result->warnings as $label => $warnings) {
            foreach ($warnings as $warning) {
                $io->warning(sprintf('%s: %s', $label, $warning));
            }
        }

        foreach ($result->failed as $label => $error) {
            $io->error(sprintf('%s: %s', $label, $error));
        }

        $io->definitionList(
            ['Created' => implode(', ', $result->created) ?: '-'],
            ['Updated' => implode(', ', $result->updated) ?: '-'],
            ['Skipped (exists, no --overwrite)' => implode(', ', $result->skipped) ?: '-'],
            ['Failed' => implode(', ', array_keys($result->failed)) ?: '-'],
        );

        if ($dryRun) {
            $io->note('Dry run: all changes were rolled back.');
        }

        return $result->failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
