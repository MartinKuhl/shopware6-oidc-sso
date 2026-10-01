<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Masks credentials before they reach the log file (H9):
 *
 * - context values under credential-like keys, in any spelling
 *   (client_secret, idToken, logout_token, webhookUrl, …), recursively;
 * - credential query parameters inside any string, message included — URLs
 *   carrying `state`, `nonce`, `sw6oidc_nonce`, `code`, `id_token_hint` or
 *   tokens are logged with those values replaced.
 */
class SensitiveDataProcessor implements ProcessorInterface
{
    private const MASK = '***MASKED***';

    /** Keys (normalized: lowercase, without `_`/`-`) whose values are always masked. */
    private const SENSITIVE_KEYS = [
        'clientsecret',
        'secret',
        'password',
        'token',
        'accesstoken',
        'idtoken',
        'refreshtoken',
        'logouttoken',
        'idtokenhint',
        'codeverifier',
        'code',
        'nonce',
        'sw6oidcnonce',
        'state',
        'authorization',
        'webhookurl',
        'healthalertwebhookurl',
    ];

    private const SENSITIVE_QUERY = '/([?&#](?:state|nonce|sw6oidc_nonce|code|code_verifier|id_token_hint|id_token|access_token'
        . '|refresh_token|logout_token|token|client_secret)=)[^&#\s"\'<>]+/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->scrubString($record->message),
            context: $this->maskRecursively($record->context),
        );
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    private function maskRecursively(array $context): array
    {
        foreach ($context as $key => $value) {
            if (\is_string($key) && $this->isSensitiveKey($key) && (\is_string($value) && $value !== '' || \is_array($value))) {
                $context[$key] = self::MASK;

                continue;
            }

            if (\is_array($value)) {
                $context[$key] = $this->maskRecursively($value);

                continue;
            }

            if (\is_string($value)) {
                $context[$key] = $this->scrubString($value);
            }
        }

        return $context;
    }

    private function isSensitiveKey(string $key): bool
    {
        return \in_array(str_replace(['_', '-'], '', strtolower($key)), self::SENSITIVE_KEYS, true);
    }

    private function scrubString(string $value): string
    {
        return str_contains($value, '=') ? (string) preg_replace(self::SENSITIVE_QUERY, '$1' . self::MASK, $value) : $value;
    }
}
