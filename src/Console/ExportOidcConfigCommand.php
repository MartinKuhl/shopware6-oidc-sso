<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Console;

use MartinKuhl\Sw6Oidc\Service\Config\OidcConfigTransfer;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'sw6oidc:config:export',
    description: 'Export OIDC provider configuration (incl. attribute and role mappings) as JSON',
)]
class ExportOidcConfigCommand extends Command
{
    public function __construct(private readonly OidcConfigTransfer $transfer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('provider-id', null, InputOption::VALUE_REQUIRED, 'Export only this provider')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to this file instead of stdout')
            ->addOption('keep-encrypted', null, InputOption::VALUE_NONE, 'Include the client secret as its encrypted envelope (importable only where APP_SECRET is identical)')
            ->addOption('plaintext', null, InputOption::VALUE_NONE, 'Include the client secret in PLAINTEXT (insecure — treat the file as a credential)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        if ($input->getOption('keep-encrypted') && $input->getOption('plaintext')) {
            $errorOutput->writeln('<error>--keep-encrypted and --plaintext are mutually exclusive.</error>');

            return self::INVALID;
        }

        $providerId = $input->getOption('provider-id');

        if ($providerId !== null && (!\is_string($providerId) || !Uuid::isValid($providerId))) {
            $errorOutput->writeln('<error>--provider-id must be a valid id.</error>');

            return self::INVALID;
        }

        $secretMode = match (true) {
            (bool) $input->getOption('plaintext') => OidcConfigTransfer::SECRET_PLAINTEXT,
            (bool) $input->getOption('keep-encrypted') => OidcConfigTransfer::SECRET_ENCRYPTED,
            default => OidcConfigTransfer::SECRET_OMIT,
        };

        if ($secretMode === OidcConfigTransfer::SECRET_PLAINTEXT) {
            $errorOutput->writeln('<comment>Warning: the export contains client secrets in plaintext. Treat it as a credential and delete it after use.</comment>');
        }

        $export = $this->transfer->export($providerId, $secretMode, Context::createCLIContext());
        $json = json_encode($export, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";

        $file = $input->getOption('output');

        if (\is_string($file) && $file !== '') {
            if (file_put_contents($file, $json) === false) {
                $errorOutput->writeln(sprintf('<error>Could not write "%s".</error>', $file));

                return self::FAILURE;
            }

            $errorOutput->writeln(sprintf('Exported %d provider(s) to %s.', \count($export['providers']), $file));

            return self::SUCCESS;
        }

        $output->write($json, false, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
