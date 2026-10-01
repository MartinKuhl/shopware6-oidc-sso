<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderEntity;
use MartinKuhl\Sw6Oidc\Event\AdminAfterCreateEvent;
use MartinKuhl\Sw6Oidc\Event\AdminBeforeCreateEvent;
use MartinKuhl\Sw6Oidc\Service\Provisioning\Exception\AdminProvisioningDeniedException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\User\UserEntity;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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
    /** user.customFields key: sha256 of the picture URL last imported (M19). */
    public const AVATAR_URL_HASH_FIELD = 'sw6oidc_avatar_url_hash';

    public function __construct(
        private readonly EntityRepository $userRepository,
        private readonly EntityRepository $localeRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly GroupMappingResolver $groupMappingResolver,
        private readonly UserProviderBindingService $bindingService,
        private readonly IdentityResolver $identityResolver,
        private readonly MediaService $mediaService,
        private readonly AvatarFetcher $avatarFetcher,
        private readonly TimeZoneValidator $timeZoneValidator,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EntityRepository $aclUserRoleRepository,
    ) {
    }

    /**
     * @throws AdminProvisioningDeniedException
     * @throws \MartinKuhl\Sw6Oidc\Service\Provisioning\Exception\AccountLinkingRequiredException
     * @throws \MartinKuhl\Sw6Oidc\Service\Provisioning\Exception\EmailNotVerifiedException
     * @throws \MartinKuhl\Sw6Oidc\Service\Provisioning\Exception\ProviderMismatchException
     */
    public function findOrCreateAdmin(Sw6OidcProviderEntity $provider, MappedProfile $profile, ExternalIdentity $identity, Context $context): UserEntity
    {
        $emailMatch = $this->findByEmail($profile->email, $context);

        $userId = $this->identityResolver->resolve(
            Sw6OidcUserProviderEntity::USER_TYPE_ADMIN,
            $provider,
            $identity,
            $emailMatch?->getId(),
            $emailMatch?->isAdmin() ?? false,
            $context,
        );

        if ($userId !== null) {
            $existing = $emailMatch instanceof \Shopware\Core\System\User\UserEntity && $emailMatch->getId() === $userId
                ? $emailMatch
                : $this->userRepository->search(new Criteria([$userId]), $context)->first();

            if (!$existing instanceof UserEntity) {
                throw AdminProvisioningDeniedException::accountMissing();
            }

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
        $this->bindingService->bind(Sw6OidcUserProviderEntity::USER_TYPE_ADMIN, $userId, $identity, $context);

        $created = $this->userRepository->search(new Criteria([$userId]), $context)->first();
        \assert($created instanceof UserEntity);

        $this->eventDispatcher->dispatch(new AdminAfterCreateEvent($provider, $profile, $created, $context));

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

        $event = new AdminBeforeCreateEvent($provider, $profile, $payload, $context);
        $this->eventDispatcher->dispatch($event);
        $payload = $this->recheckListenerPayload($provider, $payload, [...$event->getPayload(), 'id' => $userId]);

        $this->createWithUniqueUsername($payload, $profile, $context);

        if ($profile->picture !== null) {
            $avatarPayload = $this->syncAvatar($userId, $profile->picture, null, $context);

            if ($avatarPayload !== []) {
                $this->userRepository->update([['id' => $userId, ...$avatarPayload]], $context);
            }
        }

        $this->logger->info('sw6oidc: JIT-created Administration user via OIDC.', [
            'providerId' => $provider->getId(),
            'userId' => $userId,
            'superadmin' => (bool) ($payload['admin'] ?? false),
        ]);

        return $userId;
    }

    /**
     * AdminBeforeCreateEvent listeners are trusted code, but an escalation
     * they cause must never be silent: log a superadmin grant or role change
     * the provider's own mapping did not decide, and never let the email
     * drift from the verified claim.
     *
     * @param array<string, mixed> $original
     * @param array<string, mixed> $changed
     *
     * @return array<string, mixed>
     */
    private function recheckListenerPayload(Sw6OidcProviderEntity $provider, array $original, array $changed): array
    {
        if (($changed['email'] ?? null) !== $original['email']) {
            $this->logger->warning('sw6oidc: AdminBeforeCreateEvent listener changed the email; the verified claim is kept.', [
                'providerId' => $provider->getId(),
            ]);
            $changed['email'] = $original['email'];
        }

        if ((bool) ($changed['admin'] ?? false) && !(bool) $original['admin']) {
            $this->logger->warning('sw6oidc: AdminBeforeCreateEvent listener granted superadmin outside the provider group mapping.', [
                'providerId' => $provider->getId(),
                'userId' => $original['id'],
            ]);
        }

        if (($changed['aclRoles'] ?? null) != $original['aclRoles']) {
            $this->logger->warning('sw6oidc: AdminBeforeCreateEvent listener changed the ACL roles.', [
                'providerId' => $provider->getId(),
                'userId' => $original['id'],
            ]);
        }

        return $changed;
    }

    /**
     * The username is unique; two concurrent first logins can derive the same
     * one. Retry with a fresh suffix instead of failing the login.
     *
     * @param array<string, mixed> $payload
     */
    private function createWithUniqueUsername(array $payload, MappedProfile $profile, Context $context): void
    {
        for ($attempt = 0;; ++$attempt) {
            try {
                $this->userRepository->create([$payload], $context);

                return;
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= 2) {
                    throw $exception;
                }

                $payload['username'] = $this->resolveUniqueUsername($profile, $context) . '-' . bin2hex(random_bytes(2));
            }
        }
    }

    /**
     * Makes the admin's permissions match the IdP groups of this login:
     *
     * - A superadmin group match (two gates: provider flag + explicit
     *   mapping row) grants superadmin.
     * - Otherwise the resolved ACL role *replaces* the user's roles, so a
     *   role taken away at the IdP is taken away here too (H3). When nothing
     *   resolves (not even the provider default) the roles are left alone
     *   rather than emptied — a claims glitch must not strip everything.
     * - Superadmin is only revoked with the provider's
     *   `revoke_superadmin_on_sso` opt-in, and never from the last active
     *   superadmin.
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

        $criteria = (new Criteria([$userId]))->addAssociation('aclRoles');
        $user = $this->userRepository->search($criteria, $context)->first();

        if (!$user instanceof UserEntity) {
            return;
        }

        $obsolete = [];
        $hasRole = false;

        foreach ($user->getAclRoles() ?? [] as $role) {
            if ($role->getId() === $aclRoleId) {
                $hasRole = true;

                continue;
            }

            $obsolete[] = ['userId' => $userId, 'aclRoleId' => $role->getId()];
        }

        if ($obsolete !== []) {
            $this->aclUserRoleRepository->delete($obsolete, $context);
        }

        if (!$hasRole) {
            $this->userRepository->update([['id' => $userId, 'aclRoles' => [['id' => $aclRoleId]]]], $context);
        }

        if ($user->isAdmin() && $provider->isRevokeSuperadminOnSso()) {
            $this->revokeSuperadmin($provider, $userId, $context);
        }

        if ($obsolete !== [] || !$hasRole) {
            $this->logger->info('sw6oidc: admin roles synced from IdP groups.', [
                'providerId' => $provider->getId(),
                'userId' => $userId,
                'removedRoles' => \count($obsolete),
            ]);
        }
    }

    private function revokeSuperadmin(Sw6OidcProviderEntity $provider, string $userId, Context $context): void
    {
        $others = (new Criteria())
            ->addFilter(new EqualsFilter('admin', true))
            ->addFilter(new EqualsFilter('active', true))
            ->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('id', $userId)]))
            ->setLimit(1);

        if ($this->userRepository->searchIds($others, $context)->getTotal() === 0) {
            $this->logger->warning('sw6oidc: superadmin not revoked by role sync — this is the last active superadmin.', [
                'providerId' => $provider->getId(),
                'userId' => $userId,
            ]);

            return;
        }

        $this->userRepository->update([['id' => $userId, 'admin' => false]], $context);

        $this->logger->warning('sw6oidc: superadmin revoked by role sync (IdP groups no longer grant it).', [
            'providerId' => $provider->getId(),
            'userId' => $userId,
        ]);
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

            $payload = [...$payload, ...$this->syncAvatar($userId, $profile->picture, $existing, $context)];
        }

        if (\count($payload) > 1) {
            $this->userRepository->update([$payload], $context);
        }
    }

    /**
     * Fetches the picture claim's URL (AvatarFetcher, SSRF-guarded) and
     * imports it as (or overwrites) the user's avatar Media entity. Skipped
     * when the URL's hash matches the one stored in the user's custom fields
     * at the last import and the avatar still exists (M19). A bad or
     * unreachable picture claim must never break login, so any failure is
     * logged and swallowed and the previous avatar stays.
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
    /**
     * @return array<string, mixed> user update fields (empty = nothing to write)
     */
    private function syncAvatar(string $userId, string $pictureUrl, ?UserEntity $existing, Context $context): array
    {
        $existingAvatarId = $existing?->getAvatarId();
        $urlHash = hash('sha256', $pictureUrl);

        if ($existingAvatarId !== null && ($existing?->getCustomFields()[self::AVATAR_URL_HASH_FIELD] ?? null) === $urlHash) {
            return [];
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'sw6oidc_avatar_');

        if ($tempFile === false) {
            return [];
        }

        try {
            $mediaFile = $this->avatarFetcher->fetch($pictureUrl, $tempFile);

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
                'mimeType' => $mediaFile->getMimeType(),
                'fileExtension' => $mediaFile->getFileExtension(),
                'fileSize' => $mediaFile->getFileSize(),
            ]);

            return ['avatarId' => $avatarId, 'customFields' => [self::AVATAR_URL_HASH_FIELD => $urlHash]];
        } catch (\Throwable $e) {
            $this->logger->warning('sw6oidc: failed to import Administration user avatar from picture claim.', [
                'userId' => $userId,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return [];
        } finally {
            if (is_file($tempFile)) {
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
