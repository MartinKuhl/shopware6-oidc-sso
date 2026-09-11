<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provisioning\Exception\AdminProvisioningDeniedException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\User\UserEntity;

/**
 * Finds-or-JIT-creates a Shopware Administration `user` from a MappedProfile,
 * with ACL role mapping and per-user IdP binding — Shopware equivalent of the
 * Magento module's Model/Service/AdminUserCreator.php +
 * Model/Service/AdminProfileSyncService.php's role re-sync.
 *
 * NOTE: same live-instance verification caveat as CustomerProvisioningService.
 */
class AdminProvisioningService
{
    public function __construct(
        private readonly EntityRepository $userRepository,
        private readonly EntityRepository $localeRepository,
        private readonly GroupMappingResolver $groupMappingResolver,
        private readonly UserProviderBindingService $bindingService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws AdminProvisioningDeniedException
     */
    public function findOrCreateAdmin(Sw6OidcProviderEntity $provider, MappedProfile $profile, Context $context): UserEntity
    {
        $existing = $this->findByEmail($profile->email, $context);

        if ($existing !== null) {
            $this->bindingService->assertNotBoundToDifferentProvider(
                Sw6OidcUserProviderEntity::USER_TYPE_ADMIN,
                $existing->getId(),
                $provider->getId(),
                $context,
            );
            $this->bindingService->bindIfUnbound(
                Sw6OidcUserProviderEntity::USER_TYPE_ADMIN,
                $existing->getId(),
                $provider->getId(),
                $context,
            );

            if ($provider->isSyncAdminRoleOnSso()) {
                $this->syncRole($provider, $existing->getId(), $profile->groups, $context);
            }

            return $existing;
        }

        if (!$provider->isAutoCreateAdmin()) {
            throw new AdminProvisioningDeniedException(sprintf(
                'No admin account exists for "%s" and auto-creation is disabled for this provider.',
                $profile->email,
            ));
        }

        $aclRoleId = $this->groupMappingResolver->resolveAclRoleId($provider, $profile->groups, $context);

        if ($aclRoleId === null) {
            throw new AdminProvisioningDeniedException(
                "No admin role mapping (or default role) matched this user's OIDC groups; refusing to create an admin without a role.",
            );
        }

        $userId = $this->create($provider, $profile, $aclRoleId, $context);
        $this->bindingService->bindIfUnbound(Sw6OidcUserProviderEntity::USER_TYPE_ADMIN, $userId, $provider->getId(), $context);

        $created = $this->userRepository->search(new Criteria([$userId]), $context)->first();
        \assert($created instanceof UserEntity);

        return $created;
    }

    private function findByEmail(string $email, Context $context): ?UserEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('email', $email));
        $criteria->setLimit(1);

        $user = $this->userRepository->search($criteria, $context)->first();
        \assert($user === null || $user instanceof UserEntity);

        return $user;
    }

    private function create(Sw6OidcProviderEntity $provider, MappedProfile $profile, string $aclRoleId, Context $context): string
    {
        $userId = Uuid::randomHex();

        $this->userRepository->create([[
            'id' => $userId,
            'localeId' => $this->resolveDefaultLocaleId($context),
            'username' => $this->resolveUniqueUsername($profile, $context),
            'firstName' => $profile->firstName ?? $profile->email,
            'lastName' => $profile->lastName ?? '-',
            'email' => $profile->email,
            'password' => bin2hex(random_bytes(32)),
            'active' => true,
            'admin' => false,
            'aclRoles' => [['id' => $aclRoleId]],
        ]], $context);

        $this->logger->info('sw6oidc: JIT-created Administration user via OIDC.', [
            'providerId' => $provider->getId(),
            'userId' => $userId,
        ]);

        return $userId;
    }

    /**
     * @param string[] $groups
     */
    private function syncRole(Sw6OidcProviderEntity $provider, string $userId, array $groups, Context $context): void
    {
        $aclRoleId = $this->groupMappingResolver->resolveAclRoleId($provider, $groups, $context);

        if ($aclRoleId === null) {
            return;
        }

        $this->userRepository->update([[
            'id' => $userId,
            'aclRoles' => [['id' => $aclRoleId]],
        ]], $context);
    }

    private function resolveUniqueUsername(MappedProfile $profile, Context $context): string
    {
        $base = $profile->username ?? explode('@', $profile->email)[0];
        $base = preg_replace('/[^a-zA-Z0-9._-]/', '', $base) ?: 'user';
        $candidate = $base;
        $suffix = 1;

        while ($this->usernameExists($candidate, $context)) {
            $candidate = $base . $suffix;
            ++$suffix;
        }

        return $candidate;
    }

    private function usernameExists(string $username, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('username', $username));
        $criteria->setLimit(1);

        return $this->userRepository->searchIds($criteria, $context)->getTotal() > 0;
    }

    private function resolveDefaultLocaleId(Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('code', 'en-GB'));
        $criteria->setLimit(1);

        $id = $this->localeRepository->searchIds($criteria, $context)->firstId();

        if ($id !== null) {
            return $id;
        }

        $fallback = $this->localeRepository->searchIds(new Criteria(), $context)->firstId();
        \assert($fallback !== null);

        return $fallback;
    }
}
