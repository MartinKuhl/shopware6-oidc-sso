<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Security;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Answers "could anybody still log in to the Administration?" for the
 * SSO-only mode (disable_non_oidc_admin_login): with password login off,
 * an admin can only get in through a binding to an active admin-serving
 * provider. Used by the provider and user write guards so that neither
 * switching the flag on nor later changes (deactivating/deleting the last
 * bound admin or the provider they use) silently lock everybody out.
 *
 * All ids are hex; queries run on raw tables because the write guards run
 * inside the DAL write (PreWriteValidationEvent).
 */
class LockoutGuard
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Whether at least one active admin is bound to an active provider that
     * serves admin logins.
     *
     * @param list<string> $excludedUserIds     accounts about to be deleted/deactivated
     * @param list<string> $excludedProviderIds providers about to be deleted/deactivated/re-scoped
     * @param list<string> $includedProviderIds providers to count as active + admin-serving
     *                                          (e.g. the one being saved, before the write lands)
     */
    public function adminLoginRemainsPossible(array $excludedUserIds = [], array $excludedProviderIds = [], array $includedProviderIds = []): bool
    {
        $sql = <<<'SQL'
            SELECT 1
            FROM `sw6oidc_user_provider` binding
            INNER JOIN `user` u ON u.`id` = binding.`user_id`
            INNER JOIN `sw6oidc_provider` provider ON provider.`id` = binding.`provider_id`
            WHERE binding.`user_type` = 'admin'
              AND u.`active` = 1
              AND (
                  (provider.`is_active` = 1 AND provider.`login_type` IN ('admin', 'both'))
                  OR provider.`id` IN (:included)
              )
              AND u.`id` NOT IN (:excludedUsers)
              AND provider.`id` NOT IN (:excludedProviders)
            LIMIT 1
        SQL;

        return $this->connection->fetchOne($sql, [
            'included' => $this->bytes($includedProviderIds),
            'excludedUsers' => $this->bytes($excludedUserIds),
            'excludedProviders' => $this->bytes($excludedProviderIds),
        ], [
            'included' => ArrayParameterType::BINARY,
            'excludedUsers' => ArrayParameterType::BINARY,
            'excludedProviders' => ArrayParameterType::BINARY,
        ]) !== false;
    }

    /**
     * Active admins with no binding to any active admin-serving provider:
     * the accounts SSO-only mode would lock out.
     *
     * @param list<string> $includedProviderIds providers to count as active + admin-serving
     *
     * @return list<string> hex user ids
     */
    public function unboundActiveAdminIds(array $includedProviderIds = []): array
    {
        $sql = <<<'SQL'
            SELECT LOWER(HEX(u.`id`))
            FROM `user` u
            WHERE u.`active` = 1
              AND NOT EXISTS (
                  SELECT 1
                  FROM `sw6oidc_user_provider` binding
                  INNER JOIN `sw6oidc_provider` provider ON provider.`id` = binding.`provider_id`
                  WHERE binding.`user_type` = 'admin'
                    AND binding.`user_id` = u.`id`
                    AND (
                        (provider.`is_active` = 1 AND provider.`login_type` IN ('admin', 'both'))
                        OR provider.`id` IN (:included)
                    )
              )
        SQL;

        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            $sql,
            ['included' => $this->bytes($includedProviderIds)],
            ['included' => ArrayParameterType::BINARY],
        );

        return $ids;
    }

    /**
     * Whether another active provider (than $providerId) serving this login
     * type has its SSO button visible.
     */
    public function otherVisibleProviderExists(string $providerId, string $loginType): bool
    {
        $showColumn = $loginType === 'admin' ? 'show_admin_link' : 'show_customer_link';

        return $this->connection->fetchOne(
            sprintf(
                'SELECT 1 FROM `sw6oidc_provider` WHERE `is_active` = 1 AND `login_type` IN (:types) AND `%s` = 1 AND `id` <> :id LIMIT 1',
                $showColumn,
            ),
            ['types' => [$loginType, 'both'], 'id' => Uuid::fromHexToBytes($providerId)],
            ['types' => ArrayParameterType::STRING],
        ) !== false;
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
