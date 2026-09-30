<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Security;

use Psr\Log\LoggerInterface;

/**
 * Encrypts secrets at rest (currently sw6oidc_provider.client_secret) with
 * libsodium secretbox, key derived from APP_SECRET. Values are stored as
 * "sw6oidc_v1:" . base64(nonce . ciphertext).
 *
 * decrypt() never throws: an unprefixed value is legacy plaintext and is
 * returned as-is; a prefixed value that doesn't decrypt (APP_SECRET rotated,
 * corrupted row) is also returned as-is and logged, so entity hydration never
 * breaks — callers that are about to *use* a secret check isEncrypted() on the
 * result to detect that case.
 *
 * Constructible from a plain string (no container) so a Migration can use it.
 */
class Sw6OidcEncryptor
{
    public const PREFIX = 'sw6oidc_v1:';

    private readonly string $key;

    public function __construct(
        #[\SensitiveParameter]
        string $appSecret,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if ($appSecret === '') {
            throw new \InvalidArgumentException('APP_SECRET is empty; cannot derive the sw6oidc encryption key.');
        }

        $this->key = sodium_crypto_generichash($appSecret . "\0sw6oidc/client_secret/v1", '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    public function decrypt(string $value): string
    {
        if (!$this->isEncrypted($value)) {
            return $value;
        }

        $plaintext = $this->tryDecrypt($value);

        if ($plaintext === null) {
            $this->logger?->error('sw6oidc: an encrypted secret could not be decrypted (was APP_SECRET changed?). Re-enter the secret to fix this.');

            return $value;
        }

        return $plaintext;
    }

    /**
     * Like decrypt(), but a value that can't be decrypted (foreign or
     * corrupted envelope, rotated APP_SECRET) yields null instead of the
     * envelope — use it wherever the plaintext is about to be *used*
     * (sent to an IdP, exported, compared), so ciphertext never leaks out
     * as if it were the secret. Unencrypted legacy values pass through.
     */
    public function decryptOrNull(string $value): ?string
    {
        if (!$this->isEncrypted($value)) {
            return $value;
        }

        return $this->tryDecrypt($value);
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    public function canDecrypt(string $value): bool
    {
        return $this->isEncrypted($value) && $this->tryDecrypt($value) !== null;
    }

    private function tryDecrypt(string $value): ?string
    {
        $raw = base64_decode(substr($value, \strlen(self::PREFIX)), true);

        if ($raw === false || \strlen($raw) < \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        $plaintext = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );

        return $plaintext === false ? null : $plaintext;
    }
}
