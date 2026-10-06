<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Security;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The one SSO-only invariant (R3-H7): whenever Administration password
 * login is effectively off, at least one *active* admin is bound to an
 * *active, admin-serving* provider. Every write that can change the answer
 * asks this class about its result, not about its payload: provider inserts,
 * updates and deletes (whatever field they touch: the flag, `is_active` or
 * `login_type`), user deactivation and deletion, and unlinking a binding.
 *
 * Queries run on raw tables because the callers run inside the DAL write
 * (PreWriteValidationEvent). All ids are hex.
 */
class SsoOnlyInvariant
{
    private const FLAG_COLUMNS = [
        'admin' => 'disable_non_oidc_admin_login',
        'customer' => 'disable_non_oidc_customer_login',
    ];

    private const SHOW_COLUMNS = [
        'admin' => 'show_admin_link',
        'customer' => 'show_customer_link',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly bool $breakGlassAllowPasswordLogin = false,
    ) {
    }

    /**
     * Whether password login of this type is off, the same rule as
     * PasswordLoginPolicy, with pending provider changes applied.
     *
     * @param array<string, array<string, mixed>|null> $providerChanges provider id => stored columns to
     *                                                                   override, or null for a deleted provider
     */
    public function passwordLoginDisabled(string $loginType, array $providerChanges = []): bool
    {
        if ($this->breakGlassAllowPasswordLogin) {
            return false;
        }

        foreach ($this->servingProviders($loginType, $providerChanges) as $provider) {
            if ((bool) $provider[self::FLAG_COLUMNS[$loginType]]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an active, admin-serving provider shows its SSO button for
     * this login type after the pending changes.
     *
     * @param array<string, array<string, mixed>|null> $providerChanges
     */
    public function loginButtonVisible(string $loginType, array $providerChanges = []): bool
    {
        foreach ($this->servingProviders($loginType, $providerChanges) as $provider) {
            if ((bool) $provider[self::SHOW_COLUMNS[$loginType]]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether some active admin can still log in through SSO after the
     * pending changes.
     *
     * @param array<string, array<string, mixed>|null> $providerChanges
     * @param list<string>                             $removedUserIds    admins about to be deleted or deactivated
     * @param list<string>                             $unboundUserIds    admins about to lose their binding
     * @param list<string>                             $disconnectedProviderIds providers whose bindings stop counting
     *                                                                          (issuer change without re-binding, R3-M9)
     */
    public function adminAccessPossible(array $providerChanges = [], array $removedUserIds = [], array $unboundUserIds = [], array $disconnectedProviderIds = []): bool
    {
        $providerIds = array_values(array_diff(array_keys($this->servingProviders('admin', $providerChanges)), $disconnectedProviderIds));

        if ($providerIds === []) {
            return false;
        }

        $excludedUserIds = array_values(array_unique([...$removedUserIds, ...$unboundUserIds]));

        return $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1
                FROM `sw6oidc_user_provider` binding
                INNER JOIN `user` u ON u.`id` = binding.`user_id`
                WHERE binding.`user_type` = 'admin'
                  AND u.`active` = 1
                  AND binding.`provider_id` IN (:providers)
                  AND u.`id` NOT IN (:excluded)
                LIMIT 1
            SQL,
            ['providers' => $this->bytes($providerIds), 'excluded' => $this->bytes($excludedUserIds)],
            ['providers' => ArrayParameterType::BINARY, 'excluded' => ArrayParameterType::BINARY],
        ) !== false;
    }

    /**
     * Whether the invariant holds after the pending changes: password login
     * stays on, or someone can still log in through SSO.
     *
     * @param array<string, array<string, mixed>|null> $providerChanges
     * @param list<string>                             $removedUserIds
     * @param list<string>                             $unboundUserIds
     * @param list<string>                             $disconnectedProviderIds
     */
    public function holds(array $providerChanges = [], array $removedUserIds = [], array $unboundUserIds = [], array $disconnectedProviderIds = []): bool
    {
        return !$this->passwordLoginDisabled('admin', $providerChanges)
            || $this->adminAccessPossible($providerChanges, $removedUserIds, $unboundUserIds, $disconnectedProviderIds);
    }

    /**
     * Active admins with no binding to a provider that serves admin logins
     * after the pending changes: the accounts SSO-only mode locks out.
     *
     * @param array<string, array<string, mixed>|null> $providerChanges
     *
     * @return list<string> hex user ids
     */
    public function unboundActiveAdminIds(array $providerChanges = []): array
    {
        $providerIds = array_keys($this->servingProviders('admin', $providerChanges));

        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT LOWER(HEX(u.`id`))
                FROM `user` u
                WHERE u.`active` = 1
                  AND NOT EXISTS (
                      SELECT 1 FROM `sw6oidc_user_provider` binding
                      WHERE binding.`user_type` = 'admin'
                        AND binding.`user_id` = u.`id`
                        AND binding.`provider_id` IN (:providers)
                  )
            SQL,
            ['providers' => $this->bytes($providerIds)],
            ['providers' => ArrayParameterType::BINARY],
        );

        return $ids;
    }

    /**
     * Active providers serving this login type after the pending changes.
     *
     * @param array<string, array<string, mixed>|null> $providerChanges
     *
     * @return array<string, array<string, mixed>> hex id => columns
     */
    private function servingProviders(string $loginType, array $providerChanges): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`id`)) AS `id`, `is_active`, `login_type`, `disable_non_oidc_admin_login`, `disable_non_oidc_customer_login`, `show_admin_link`, `show_customer_link`
             FROM `sw6oidc_provider`',
        );

        $providers = [];

        foreach ($rows as $row) {
            $providers[(string) $row['id']] = $row;
        }

        foreach ($providerChanges as $id => $columns) {
            $id = strtolower($id);

            if ($columns === null) {
                unset($providers[$id]);

                continue;
            }

            $providers[$id] = [...($providers[$id] ?? ['id' => $id]), ...$columns];
        }

        return array_filter(
            $providers,
            static fn (array $provider): bool => (bool) ($provider['is_active'] ?? false)
                && \in_array($provider['login_type'] ?? null, [$loginType, 'both'], true),
        );
    }

    /**
     * @param list<string> $hexIds
     *
     * @return list<string>
     */
    private function bytes(array $hexIds): array
    {
        // An empty IN () is invalid SQL; a random id never matches.
        $hexIds = $hexIds === [] ? [Uuid::randomHex()] : $hexIds;

        return array_map(Uuid::fromHexToBytes(...), $hexIds);
    }
}
