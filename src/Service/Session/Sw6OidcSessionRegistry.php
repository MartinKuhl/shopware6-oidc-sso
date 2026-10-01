<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Session;

use Doctrine\DBAL\Connection;
use MartinKuhl\Sw6Oidc\Service\Security\Sw6OidcEncryptor;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Maps IdP subjects / IdP sessions (`sub`, `sid`, both scoped to the
 * provider — they are only unique per issuer) and local accounts to the
 * local sessions an OIDC login created, so Back-/Front-Channel Logout and
 * forced logouts can find what to destroy.
 *
 * This is security state, so it lives in the `sw6oidc_session` table rather
 * than a cache pool that cache:clear or a deploy would empty: an IdP logout
 * must find a session for as long as that session can be used. Entries
 * expire with the real session lifetime (admins: the refresh-token TTL,
 * customers: a generous upper bound for sliding context tokens) and are
 * pruned by the daily cleanup task. Session keys (context tokens / jtis)
 * and IdP tokens are credentials: stored encrypted, looked up by hash.
 */
class Sw6OidcSessionRegistry
{
    /** Upper bound for customer entries: context tokens slide with activity. */
    public const CUSTOMER_TTL_SECONDS = 2592000;

    private readonly int $adminTtlSeconds;

    /**
     * @param string $adminSessionLifetime ISO 8601 duration, the Admin API refresh-token TTL
     *                                     (an admin session lives as long as its refresh token)
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Sw6OidcEncryptor $encryptor,
        private readonly LoggerInterface $logger,
        string $adminSessionLifetime = 'P1W',
        private readonly int $customerTtlSeconds = self::CUSTOMER_TTL_SECONDS,
    ) {
        $this->adminTtlSeconds = (new \DateTimeImmutable('@0'))->add(new \DateInterval($adminSessionLifetime))->getTimestamp();
    }

    public function register(
        string $providerId,
        string $sub,
        ?string $sid,
        string $userType,
        string $userId,
        string $sessionKey,
        ?string $salesChannelId = null,
        ?string $idToken = null,
        ?string $idpAccessToken = null,
        ?string $idpRefreshToken = null,
        ?int $ttlSeconds = null,
    ): Sw6OidcSession {
        $session = new Sw6OidcSession(
            bin2hex(random_bytes(16)),
            $providerId,
            $sub,
            $sid !== '' ? $sid : null,
            $userType,
            $userId,
            $sessionKey,
            $salesChannelId,
            $idToken,
            time(),
            $idpAccessToken,
            $idpRefreshToken,
        );

        $ttl = $ttlSeconds ?? $this->ttlFor($userType);

        $this->connection->insert('sw6oidc_session', [
            'id' => $session->id,
            'provider_id' => Uuid::fromHexToBytes($providerId),
            'sub' => $sub,
            'sid' => $session->sid,
            'user_type' => $userType,
            'user_id' => Uuid::fromHexToBytes($userId),
            'session_key' => $this->encryptor->encrypt($sessionKey, 'sw6oidc_session.session_key'),
            'session_key_hash' => self::hashSessionKey($sessionKey),
            'sales_channel_id' => $salesChannelId !== null ? Uuid::fromHexToBytes($salesChannelId) : null,
            'id_token' => $this->encryptNullable($idToken, 'id_token'),
            'idp_access_token' => $this->encryptNullable($idpAccessToken, 'idp_access_token'),
            'idp_refresh_token' => $this->encryptNullable($idpRefreshToken, 'idp_refresh_token'),
            'created_at' => (new \DateTimeImmutable('@' . $session->createdAt))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'expires_at' => $this->expiresAt($ttl),
        ]);

        $this->logger->debug('sw6oidc: session registered.', [
            'providerId' => $providerId,
            'userType' => $userType,
            'userId' => $userId,
            'hasSid' => $session->sid !== null,
        ]);

        return $session;
    }

    /**
     * All live sessions of an IdP subject at this provider.
     *
     * @return list<Sw6OidcSession>
     */
    public function resolve(string $providerId, string $sub): array
    {
        return $this->fetch('`provider_id` = :providerId AND `sub` = :sub', [
            'providerId' => Uuid::fromHexToBytes($providerId),
            'sub' => $sub,
        ]);
    }

    /**
     * @return list<Sw6OidcSession>
     */
    public function resolveBySid(string $providerId, string $sid): array
    {
        return $this->fetch('`provider_id` = :providerId AND `sid` = :sid', [
            'providerId' => Uuid::fromHexToBytes($providerId),
            'sid' => $sid,
        ]);
    }

    /**
     * All live OIDC sessions of a local account, newest last.
     *
     * @return list<Sw6OidcSession>
     */
    public function resolveByUser(string $userType, string $userId): array
    {
        return $this->fetch('`user_type` = :userType AND `user_id` = :userId', [
            'userType' => $userType,
            'userId' => Uuid::fromHexToBytes($userId),
        ]);
    }

    /**
     * The account's session created with this session key (context token /
     * first jti), if still registered.
     */
    public function findBySessionKey(string $userType, string $userId, string $sessionKey): ?Sw6OidcSession
    {
        return $this->fetch('`user_type` = :userType AND `user_id` = :userId AND `session_key_hash` = :hash', [
            'userType' => $userType,
            'userId' => Uuid::fromHexToBytes($userId),
            'hash' => self::hashSessionKey($sessionKey),
        ])[0] ?? null;
    }

    /**
     * The account's session with this registry id — for admins the
     * login-session handle the Administration sends back at logout.
     */
    public function findForUser(string $userType, string $userId, string $id): ?Sw6OidcSession
    {
        return $this->fetch('`user_type` = :userType AND `user_id` = :userId AND `id` = :id', [
            'userType' => $userType,
            'userId' => Uuid::fromHexToBytes($userId),
            'id' => $id,
        ])[0] ?? null;
    }

    /**
     * Completes an entry registered before its session existed (admin OIDC:
     * the callback registers, the nonce exchange mints the access token):
     * sets the real session key and the full session lifetime.
     */
    public function activate(string $id, string $sessionKey): void
    {
        $this->connection->executeStatement(
            'UPDATE `sw6oidc_session` SET `session_key` = :key, `session_key_hash` = :hash, `expires_at` = :expiresAt WHERE `id` = :id',
            [
                'id' => $id,
                'key' => $this->encryptor->encrypt($sessionKey, 'sw6oidc_session.session_key'),
                'hash' => self::hashSessionKey($sessionKey),
                'expiresAt' => $this->expiresAt($this->adminTtlSeconds),
            ],
        );
    }

    public function remove(Sw6OidcSession $session): void
    {
        $this->connection->delete('sw6oidc_session', ['id' => $session->id]);
    }

    /**
     * Removes every registered session of the account; returns how many.
     */
    public function removeAllForUser(string $userType, string $userId): int
    {
        return (int) $this->connection->delete('sw6oidc_session', [
            'user_type' => $userType,
            'user_id' => Uuid::fromHexToBytes($userId),
        ]);
    }

    public function get(string $id): ?Sw6OidcSession
    {
        return $this->fetch('`id` = :id', ['id' => $id])[0] ?? null;
    }

    /**
     * Deletes expired entries; returns how many.
     */
    public function prune(): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM `sw6oidc_session` WHERE `expires_at` <= :now',
            ['now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT)],
        );
    }

    private function expiresAt(int $ttlSeconds): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(sprintf('+%d seconds', $ttlSeconds))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }

    private function ttlFor(string $userType): int
    {
        return $userType === Sw6OidcSession::USER_TYPE_ADMIN ? $this->adminTtlSeconds : $this->customerTtlSeconds;
    }

    public static function hashSessionKey(string $sessionKey): string
    {
        return hash('sha256', $sessionKey);
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<Sw6OidcSession>
     */
    private function fetch(string $where, array $parameters): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM `sw6oidc_session` WHERE ' . $where . ' AND `expires_at` > :now ORDER BY `created_at` ASC',
            [...$parameters, 'now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT)],
        );

        $sessions = [];

        foreach ($rows as $row) {
            $session = $this->hydrate($row);

            if ($session instanceof Sw6OidcSession) {
                $sessions[] = $session;
            }
        }

        return $sessions;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ?Sw6OidcSession
    {
        $sessionKey = $this->encryptor->decryptOrNull((string) $row['session_key'], 'sw6oidc_session.session_key');

        if ($sessionKey === null) {
            // Written under a different APP_SECRET: the session can't be targeted anymore.
            return null;
        }

        $createdAt = \DateTimeImmutable::createFromFormat(Defaults::STORAGE_DATE_TIME_FORMAT, (string) $row['created_at'], new \DateTimeZone('UTC'));

        return new Sw6OidcSession(
            (string) $row['id'],
            Uuid::fromBytesToHex((string) $row['provider_id']),
            (string) $row['sub'],
            \is_string($row['sid']) ? $row['sid'] : null,
            (string) $row['user_type'],
            Uuid::fromBytesToHex((string) $row['user_id']),
            $sessionKey,
            \is_string($row['sales_channel_id']) ? Uuid::fromBytesToHex($row['sales_channel_id']) : null,
            $this->decryptNullable($row['id_token'], 'id_token'),
            $createdAt !== false ? $createdAt->getTimestamp() : 0,
            $this->decryptNullable($row['idp_access_token'], 'idp_access_token'),
            $this->decryptNullable($row['idp_refresh_token'], 'idp_refresh_token'),
        );
    }

    private function encryptNullable(?string $value, string $column): ?string
    {
        return $value === null || $value === '' ? null : $this->encryptor->encrypt($value, 'sw6oidc_session.' . $column);
    }

    private function decryptNullable(mixed $value, string $column): ?string
    {
        return \is_string($value) ? $this->encryptor->decryptOrNull($value, 'sw6oidc_session.' . $column) : null;
    }
}
