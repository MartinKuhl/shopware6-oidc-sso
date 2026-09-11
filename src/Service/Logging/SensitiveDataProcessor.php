<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Masks secrets before they ever reach the log file — mirrors the Magento module's
 * OidcLogger field masking (client_secret, access_token, id_token, refresh_token,
 * password, token).
 */
class SensitiveDataProcessor implements ProcessorInterface
{
    private const SENSITIVE_KEYS = [
        'client_secret',
        'access_token',
        'id_token',
        'refresh_token',
        'password',
        'token',
        'code_verifier',
        'code',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: $this->maskRecursively($record->context));
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    private function maskRecursively(array $context): array
    {
        foreach ($context as $key => $value) {
            if (\is_array($value)) {
                $context[$key] = $this->maskRecursively($value);

                continue;
            }

            if (\is_string($key) && \in_array(strtolower($key), self::SENSITIVE_KEYS, true) && \is_string($value) && $value !== '') {
                $context[$key] = '***MASKED***';
            }
        }

        return $context;
    }
}
