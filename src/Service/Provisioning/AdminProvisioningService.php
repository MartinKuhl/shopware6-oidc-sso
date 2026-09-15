<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provisioning\Exception\AdminProvisioningDeniedException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Media\File\FileFetcher;
use Shopware\Core\Content\Media\MediaService;
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
        private readonly EntityRepository $mediaRepository,
        private readonly GroupMappingResolver $groupMappingResolver,
        private readonly UserProviderBindingService $bindingService,
        private readonly MediaService $mediaService,
        private readonly FileFetcher $fileFetcher,
        private readonly TimeZoneValidator $timeZoneValidator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws AdminProvisioningDeniedException
     */
    public function findOrCreateAdmin(Sw6OidcProviderEntity $provider, MappedProfile $profile, Context $context): UserEntity
    {
        $existing = $this->findByEmail($profile->email, $context);

        if ($existing instanceof \Shopware\Core\System\User\UserEntity) {
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

            if ($provider->isSyncAdminProfileOnSso()) {
                $this->syncProfile($existing->getId(), $profile, $context);
            }

            return $existing;
        }

        if (!$provider->isAutoCreateAdmin()) {
            throw AdminProvisioningDeniedException::autoCreateDisabled($profile->email);
        }

        $isSuperadmin = $provider->isAllowSuperadminGroupMapping()
            && $this->groupMappingResolver->matchesSuperadminGroup($provider, $profile->groups, $context);

        $aclRoleId = null;

        if (!$isSuperadmin) {
            $aclRoleId = $this->groupMappingResolver->resolveAclRoleId($provider, $profile->groups, $context);

            if ($aclRoleId === null) {
                throw AdminProvisioningDeniedException::noRoleResolved();
            }
        }

        $userId = $this->create($provider, $profile, $aclRoleId, $isSuperadmin, $context);
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

    private function create(Sw6OidcProviderEntity $provider, MappedProfile $profile, ?string $aclRoleId, bool $isSuperadmin, Context $context): string
    {
        $userId = Uuid::randomHex();

        $payload = [
            'id' => $userId,
            'localeId' => $this->resolveLocaleId($profile->locale, $context),
            'username' => $this->resolveUniqueUsername($profile, $context),
            'firstName' => $profile->firstName ?? $profile->email,
            'lastName' => $profile->lastName ?? '-',
            'email' => $profile->email,
            'password' => bin2hex(random_bytes(32)),
            'active' => true,
            'admin' => $isSuperadmin,
            'aclRoles' => $isSuperadmin ? [] : [['id' => $aclRoleId]],
        ];

        $timeZone = $this->resolveTimeZone($profile->zoneinfo);

        if ($timeZone !== null) {
            $payload['timeZone'] = $timeZone;
        }

        $this->userRepository->create([$payload], $context);

        if ($profile->picture !== null) {
            $avatarId = $this->syncAvatar($userId, $profile->picture, null, $context);

            if ($avatarId !== null) {
                $this->userRepository->update([['id' => $userId, 'avatarId' => $avatarId]], $context);
            }
        }

        $this->logger->info('sw6oidc: JIT-created Administration user via OIDC.', [
            'providerId' => $provider->getId(),
            'userId' => $userId,
            'superadmin' => $isSuperadmin,
        ]);

        return $userId;
    }

    /**
     * Only ever grants — a superadmin flag or ACL role from a previous
     * successful match is never revoked here just because this login's
     * groups no longer match (e.g. a transient IdP claims glitch), since
     * that could silently strip the only superadmin's access. Deliberate,
     * matching the create-time superadmin gate: explicit group match AND
     * the provider's `allowSuperadminGroupMapping` toggle.
     *
     * @param string[] $groups
     */
    private function syncRole(Sw6OidcProviderEntity $provider, string $userId, array $groups, Context $context): void
    {
        if ($provider->isAllowSuperadminGroupMapping() && $this->groupMappingResolver->matchesSuperadminGroup($provider, $groups, $context)) {
            $this->userRepository->update([[
                'id' => $userId,
                'admin' => true,
            ]], $context);

            return;
        }

        $aclRoleId = $this->groupMappingResolver->resolveAclRoleId($provider, $groups, $context);

        if ($aclRoleId === null) {
            return;
        }

        $this->userRepository->update([[
            'id' => $userId,
            'aclRoles' => [['id' => $aclRoleId]],
        ]], $context);
    }

    /**
     * Partial update only — a claim that isn't mapped (null on the profile)
     * is left alone rather than overwritten, same rationale as
     * CustomerProvisioningService::syncExisting(). Administration users
     * have no birthday/gender/salutation fields, unlike customers, so this
     * only touches first/last name plus locale/timezone/avatar.
     */
    private function syncProfile(string $userId, MappedProfile $profile, Context $context): void
    {
        $payload = ['id' => $userId];

        if ($profile->firstName !== null) {
            $payload['firstName'] = $profile->firstName;
        }

        if ($profile->lastName !== null) {
            $payload['lastName'] = $profile->lastName;
        }

        if ($profile->locale !== null) {
            $payload['localeId'] = $this->resolveLocaleId($profile->locale, $context);
        }

        $timeZone = $this->resolveTimeZone($profile->zoneinfo);

        if ($timeZone !== null) {
            $payload['timeZone'] = $timeZone;
        }

        if ($profile->picture !== null) {
            $existing = $this->userRepository->search(new Criteria([$userId]), $context)->first();
            \assert($existing instanceof UserEntity);

            $avatarId = $this->syncAvatar($userId, $profile->picture, $existing->getAvatarId(), $context);

            if ($avatarId !== null) {
                $payload['avatarId'] = $avatarId;
            }
        }

        if (\count($payload) > 1) {
            $this->userRepository->update([$payload], $context);
        }
    }

    /**
     * Fetches the picture claim's URL and imports it as (or overwrites) the
     * user's avatar Media entity, reusing Shopware core's own SSRF-hardened
     * FileFetcher (NoPrivateNetworkHttpClient + blocked-subnet resolver,
     * gated by the core.media.enableUrlUploadFeature/enableUrlValidation
     * system config). A bad/unreachable picture claim must never break
     * login, so any failure is logged and swallowed, returning the
     * previous avatar id (if any) unchanged.
     *
     * Uses Shopware core's own 'user' default media folder (seeded by
     * BasicData's `avatarUser` association) rather than leaving the Media
     * row unassigned — same folder a manually-uploaded avatar would land
     * in, so it gets the same thumbnail-size config.
     *
     * Media is always forced public (`private: false`): a `private` Media
     * entity is only servable through an authenticated download action,
     * not a plain `<img src>`, so a private avatar would never render
     * anywhere in the Administration UI. The explicit follow-up update
     * covers both a brand-new Media row and one from a previous sync that
     * was created private, self-healing on the next login rather than
     * requiring a one-off manual fix.
     */
    private function syncAvatar(string $userId, string $pictureUrl, ?string $existingAvatarId, Context $context): ?string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'sw6oidc_avatar_');

        if ($tempFile === false) {
            return $existingAvatarId;
        }

        try {
            $mediaFile = $this->fileFetcher->fetchFromURL($pictureUrl, $tempFile);

            $avatarId = $this->mediaService->saveMediaFile(
                $mediaFile,
                'sw6oidc-avatar-' . $userId,
                $context,
                'user',
                $existingAvatarId,
                false,
            );

            $this->mediaRepository->update([['id' => $avatarId, 'private' => false]], $context);

            $this->logger->info('sw6oidc: imported Administration user avatar from picture claim.', [
                'userId' => $userId,
                'avatarId' => $avatarId,
            ]);

            return $avatarId;
        } catch (\Throwable $e) {
            $this->logger->warning('sw6oidc: failed to import Administration user avatar from picture claim.', [
                'userId' => $userId,
                'pictureUrl' => $pictureUrl,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return $existingAvatarId;
        } finally {
            if (isset($mediaFile)) {
                $this->fileFetcher->cleanUpTempFile($mediaFile);
            } elseif (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
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

    /**
     * Resolves the `locale` claim (e.g. "de-DE") against an exact `Locale.code`
     * match; falls back to the previous hardcoded "en-GB"-or-first-available
     * behavior when the claim is absent or doesn't match any known locale.
     */
    private function resolveLocaleId(?string $localeClaim, Context $context): string
    {
        if ($localeClaim !== null) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('code', $localeClaim));
            $criteria->setLimit(1);

            $id = $this->localeRepository->searchIds($criteria, $context)->firstId();

            if ($id !== null) {
                return $id;
            }
        }

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

    /**
     * Validates the `zoneinfo` claim against PHP's known IANA identifiers
     * (the same set Shopware's own TimeZoneFieldSerializer validates
     * against) — returns null (leave the field untouched) for a missing or
     * malformed claim rather than letting the DAL write fail the login.
     */
    private function resolveTimeZone(?string $zoneinfoClaim): ?string
    {
        return $this->timeZoneValidator->isValid($zoneinfoClaim) ? $zoneinfoClaim : null;
    }
}
