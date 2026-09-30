<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

/**
 * The RP-Initiated Logout `state`: which login page the shared post-logout
 * landing (`/sw6oidc/postlogout`) should send the user to. HMAC-signed with
 * a key derived from APP_SECRET so a crafted link can't pick the target —
 * not that either target is sensitive, but the landing then never acts on
 * unauthenticated input.
 */
class PostLogoutState
{
    public const TARGET_CUSTOMER = 'customer';
    public const TARGET_ADMIN = 'admin';

    private readonly string $key;

    public function __construct(string $appSecret)
    {
        $this->key = hash('sha256', 'sw6oidc-post-logout-state|' . $appSecret, true);
    }

    public function create(string $target): string
    {
        $payload = $target . '.' . bin2hex(random_bytes(8));

        return $payload . '.' . $this->sign($payload);
    }

    /**
     * @return self::TARGET_*|null the target of a genuine state, else null
     */
    public function parse(?string $state): ?string
    {
        if ($state === null || substr_count($state, '.') !== 2) {
            return null;
        }

        [$target, $random, $signature] = explode('.', $state);

        if (!hash_equals($this->sign($target . '.' . $random), $signature)) {
            return null;
        }

        return match ($target) {
            self::TARGET_CUSTOMER => self::TARGET_CUSTOMER,
            self::TARGET_ADMIN => self::TARGET_ADMIN,
            default => null,
        };
    }

    private function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, $this->key), 0, 32);
    }
}
