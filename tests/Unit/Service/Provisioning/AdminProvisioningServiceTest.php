<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Provisioning;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderCollection;
use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderDefinition;
use MartinKuhl\Sw6Oidc\Core\Content\UserProvider\Sw6OidcUserProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provisioning\AdminProvisioningService;
use MartinKuhl\Sw6Oidc\Service\Provisioning\Exception\AdminProvisioningDeniedException;
use MartinKuhl\Sw6Oidc\Service\Provisioning\Exception\ProviderMismatchException;
use MartinKuhl\Sw6Oidc\Service\Provisioning\GroupMappingResolver;
use MartinKuhl\Sw6Oidc\Service\Provisioning\MappedProfile;
use MartinKuhl\Sw6Oidc\Service\Provisioning\TimeZoneValidator;
use MartinKuhl\Sw6Oidc\Service\Provisioning\UserProviderBindingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Media\File\FileFetcher;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Event\NestedEventCollection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\User\UserCollection;
use Shopware\Core\System\User\UserDefinition;
use Shopware\Core\System\User\UserEntity;

#[CoversClass(AdminProvisioningService::class)]
final class AdminProvisioningServiceTest extends TestCase
{
    private Context $context;

    private ?UserEntity $existingUser = null;

    private ?string $boundProviderId = null;

    /** @var string[] */
    private array $takenUsernames = [];

    /** @var array<string, string> locale code => locale id */
    private array $localeIds = [];

    private string $fallbackLocaleId;

    private bool $superadminMatch = false;

    private ?string $resolvedAclRoleId = null;

    /** @var list<array<string, mixed>> */
    private array $userCreates = [];

    /** @var list<array<string, mixed>> */
    private array $userUpdates = [];

    /** @var list<array<string, mixed>> */
    private array $bindingCreates = [];

    /** @var list<array<string, mixed>> */
    private array $mediaUpdates = [];

    private GroupMappingResolver&MockObject $groupMappingResolver;

    private FileFetcher&MockObject $fileFetcher;

    private MediaService&MockObject $mediaService;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
        $this->fallbackLocaleId = Uuid::randomHex();

        $this->groupMappingResolver = $this->createMock(GroupMappingResolver::class);
        $this->groupMappingResolver->method('matchesSuperadminGroup')
            ->willReturnCallback(fn (): bool => $this->superadminMatch);
        $this->groupMappingResolver->method('resolveAclRoleId')
            ->willReturnCallback(fn (): ?string => $this->resolvedAclRoleId);

        $this->fileFetcher = $this->createMock(FileFetcher::class);
        $this->mediaService = $this->createMock(MediaService::class);
    }

    public function testExistingAdminBoundToDifferentProviderThrowsProviderMismatch(): void
    {
        $this->existingUser = $this->user('jane');
        $this->boundProviderId = Uuid::randomHex();

        $provider = $this->provider();
        $provider->setSyncAdminRoleOnSso(true);
        $provider->setSyncAdminProfileOnSso(true);

        try {
            $this->createService()->findOrCreateAdmin($provider, new MappedProfile('jane@example.com', firstName: 'Jane'), $this->context);
            self::fail('Expected ProviderMismatchException');
        } catch (ProviderMismatchException) {
        }

        self::assertSame([], $this->bindingCreates);
        self::assertSame([], $this->userUpdates);
        self::assertSame([], $this->userCreates);
    }

    public function testExistingUnboundAdminGetsBoundAndReturnedWithoutSync(): void
    {
        $this->existingUser = $this->user('jane');
        $provider = $this->provider();

        $result = $this->createService()->findOrCreateAdmin($provider, new MappedProfile('jane@example.com', firstName: 'Jane', groups: ['admins']), $this->context);

        self::assertSame($this->existingUser, $result);
        self::assertCount(1, $this->bindingCreates);
        self::assertSame(Sw6OidcUserProviderEntity::USER_TYPE_ADMIN, $this->bindingCreates[0]['userType']);
        self::assertSame($this->existingUser->getId(), $this->bindingCreates[0]['userId']);
        self::assertSame($provider->getId(), $this->bindingCreates[0]['providerId']);
        self::assertSame([], $this->userUpdates);
        self::assertSame([], $this->userCreates);
    }

    public function testExistingAdminAlreadyBoundToSameProviderIsNotRebound(): void
    {
        $this->existingUser = $this->user('jane');
        $provider = $this->provider();
        $this->boundProviderId = $provider->getId();

        $result = $this->createService()->findOrCreateAdmin($provider, new MappedProfile('jane@example.com'), $this->context);

        self::assertSame($this->existingUser, $result);
        self::assertSame([], $this->bindingCreates);
    }

    public function testSyncRoleGrantsSuperadminWhenAllowedAndGroupMatches(): void
    {
        $this->existingUser = $this->user('jane');
        $this->superadminMatch = true;
        $this->resolvedAclRoleId = Uuid::randomHex();

        $provider = $this->provider();
        $provider->setSyncAdminRoleOnSso(true);
        $provider->setAllowSuperadminGroupMapping(true);

        $this->groupMappingResolver->expects(self::never())->method('resolveAclRoleId');

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile('jane@example.com', groups: ['root']), $this->context);

        self::assertSame([['id' => $this->existingUser->getId(), 'admin' => true]], $this->userUpdates);
    }

    public function testSyncRoleIgnoresStraySuperadminMappingWhenProviderToggleIsOff(): void
    {
        $this->existingUser = $this->user('jane');
        $this->superadminMatch = true;
        $roleId = Uuid::randomHex();
        $this->resolvedAclRoleId = $roleId;

        $provider = $this->provider();
        $provider->setSyncAdminRoleOnSso(true);
        $provider->setAllowSuperadminGroupMapping(false);

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile('jane@example.com', groups: ['root']), $this->context);

        self::assertSame([['id' => $this->existingUser->getId(), 'aclRoles' => [['id' => $roleId]]]], $this->userUpdates);
        foreach ($this->userUpdates as $update) {
            self::assertArrayNotHasKey('admin', $update);
        }
    }

    public function testSyncRoleAppliesResolvedAclRoleWhenNoSuperadminMatch(): void
    {
        $this->existingUser = $this->user('jane');
        $roleId = Uuid::randomHex();
        $this->resolvedAclRoleId = $roleId;

        $provider = $this->provider();
        $provider->setSyncAdminRoleOnSso(true);
        $provider->setAllowSuperadminGroupMapping(true);

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile('jane@example.com', groups: ['editors']), $this->context);

        self::assertSame([['id' => $this->existingUser->getId(), 'aclRoles' => [['id' => $roleId]]]], $this->userUpdates);
    }

    public function testSyncRoleNeverRevokesWhenNothingMatches(): void
    {
        $this->existingUser = $this->user('jane');
        $this->existingUser->setAdmin(true);

        $provider = $this->provider();
        $provider->setSyncAdminRoleOnSso(true);
        $provider->setAllowSuperadminGroupMapping(true);

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile('jane@example.com', groups: []), $this->context);

        self::assertSame([], $this->userUpdates);
    }

    public function testSyncRoleIsSkippedWhenToggleIsOff(): void
    {
        $this->existingUser = $this->user('jane');
        $this->superadminMatch = true;
        $this->resolvedAclRoleId = Uuid::randomHex();

        $provider = $this->provider();
        $provider->setAllowSuperadminGroupMapping(true);

        $this->groupMappingResolver->expects(self::never())->method('matchesSuperadminGroup');
        $this->groupMappingResolver->expects(self::never())->method('resolveAclRoleId');

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile('jane@example.com', groups: ['root']), $this->context);

        self::assertSame([], $this->userUpdates);
    }

    public function testSyncProfileOnlyUpdatesMappedNonNullFields(): void
    {
        $this->existingUser = $this->user('jane');

        $provider = $this->provider();
        $provider->setSyncAdminProfileOnSso(true);

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile('jane@example.com', firstName: 'Janet'), $this->context);

        self::assertSame([['id' => $this->existingUser->getId(), 'firstName' => 'Janet']], $this->userUpdates);
    }

    public function testSyncProfileAppliesAllMappedFieldsIncludingLocaleAndTimeZone(): void
    {
        $this->existingUser = $this->user('jane');
        $deLocaleId = Uuid::randomHex();
        $this->localeIds['de-DE'] = $deLocaleId;

        $provider = $this->provider();
        $provider->setSyncAdminProfileOnSso(true);

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile(
            'jane@example.com',
            firstName: 'Janet',
            lastName: 'Doe',
            locale: 'de-DE',
            zoneinfo: 'Europe/Berlin',
        ), $this->context);

        self::assertSame([[
            'id' => $this->existingUser->getId(),
            'firstName' => 'Janet',
            'lastName' => 'Doe',
            'localeId' => $deLocaleId,
            'timeZone' => 'Europe/Berlin',
        ]], $this->userUpdates);
    }

    public function testSyncProfileSkipsInvalidTimeZoneAndWritesNothingWhenNothingIsMapped(): void
    {
        $this->existingUser = $this->user('jane');

        $provider = $this->provider();
        $provider->setSyncAdminProfileOnSso(true);

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile('jane@example.com', zoneinfo: 'Not/AZone'), $this->context);

        self::assertSame([], $this->userUpdates);
    }

    public function testSyncProfileImportsAvatarReusingExistingMediaId(): void
    {
        $existingAvatarId = Uuid::randomHex();
        $this->existingUser = $this->user('jane');
        $this->existingUser->setAvatarId($existingAvatarId);

        $provider = $this->provider();
        $provider->setSyncAdminProfileOnSso(true);

        $this->expectAvatarImport('https://idp.example.com/a.png', $existingAvatarId, $existingAvatarId);

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile('jane@example.com', picture: 'https://idp.example.com/a.png'), $this->context);

        self::assertSame([['id' => $this->existingUser->getId(), 'avatarId' => $existingAvatarId]], $this->userUpdates);
        self::assertSame([['id' => $existingAvatarId, 'private' => false]], $this->mediaUpdates);
    }

    public function testSyncProfileSwallowsAvatarFetchFailure(): void
    {
        $this->existingUser = $this->user('jane');

        $provider = $this->provider();
        $provider->setSyncAdminProfileOnSso(true);

        $this->fileFetcher->method('fetchFromURL')->willThrowException(new \RuntimeException('unreachable'));
        $this->mediaService->expects(self::never())->method('saveMediaFile');

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile('jane@example.com', firstName: 'Jane', picture: 'https://idp.example.com/a.png'), $this->context);

        self::assertSame([['id' => $this->existingUser->getId(), 'firstName' => 'Jane']], $this->userUpdates);
        self::assertSame([], $this->mediaUpdates);
    }

    public function testNotFoundWithAutoCreateDisabledIsDenied(): void
    {
        $provider = $this->provider();
        $provider->setAutoCreateAdmin(false);
        $this->resolvedAclRoleId = Uuid::randomHex();

        try {
            $this->createService()->findOrCreateAdmin($provider, new MappedProfile('new@example.com'), $this->context);
            self::fail('Expected AdminProvisioningDeniedException');
        } catch (AdminProvisioningDeniedException $e) {
            self::assertSame(AdminProvisioningDeniedException::REASON_AUTO_CREATE_DISABLED, $e->reason);
            self::assertStringContainsString('new@example.com', $e->getMessage());
        }

        self::assertSame([], $this->userCreates);
        self::assertSame([], $this->bindingCreates);
    }

    public function testNotFoundWithoutResolvedRoleIsDenied(): void
    {
        $provider = $this->provider();
        $provider->setAutoCreateAdmin(true);
        $provider->setAllowSuperadminGroupMapping(true);

        try {
            $this->createService()->findOrCreateAdmin($provider, new MappedProfile('new@example.com', groups: ['nobody']), $this->context);
            self::fail('Expected AdminProvisioningDeniedException');
        } catch (AdminProvisioningDeniedException $e) {
            self::assertSame(AdminProvisioningDeniedException::REASON_NO_ROLE, $e->reason);
        }

        self::assertSame([], $this->userCreates);
        self::assertSame([], $this->bindingCreates);
    }

    public function testStraySuperadminMappingAloneNeverGrantsOnCreate(): void
    {
        $provider = $this->provider();
        $provider->setAutoCreateAdmin(true);
        $provider->setAllowSuperadminGroupMapping(false);
        $this->superadminMatch = true;

        try {
            $this->createService()->findOrCreateAdmin($provider, new MappedProfile('new@example.com', groups: ['root']), $this->context);
            self::fail('Expected AdminProvisioningDeniedException');
        } catch (AdminProvisioningDeniedException $e) {
            self::assertSame(AdminProvisioningDeniedException::REASON_NO_ROLE, $e->reason);
        }

        self::assertSame([], $this->userCreates);
    }

    public function testStraySuperadminMappingWithToggleOffCreatesRegularAdminWithAclRole(): void
    {
        $provider = $this->provider();
        $provider->setAutoCreateAdmin(true);
        $provider->setAllowSuperadminGroupMapping(false);
        $this->superadminMatch = true;
        $roleId = Uuid::randomHex();
        $this->resolvedAclRoleId = $roleId;

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile('new@example.com', groups: ['root']), $this->context);

        self::assertCount(1, $this->userCreates);
        self::assertFalse($this->userCreates[0]['admin']);
        self::assertSame([['id' => $roleId]], $this->userCreates[0]['aclRoles']);
    }

    public function testCreatesSuperadminWhenToggleOnAndGroupMatches(): void
    {
        $provider = $this->provider();
        $provider->setAutoCreateAdmin(true);
        $provider->setAllowSuperadminGroupMapping(true);
        $this->superadminMatch = true;

        $this->groupMappingResolver->expects(self::never())->method('resolveAclRoleId');

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile('root@example.com', groups: ['root']), $this->context);

        self::assertCount(1, $this->userCreates);
        self::assertTrue($this->userCreates[0]['admin']);
        self::assertSame([], $this->userCreates[0]['aclRoles']);
    }

    public function testCreatePayloadBindingAndReturnedEntity(): void
    {
        $provider = $this->provider();
        $provider->setAutoCreateAdmin(true);
        $roleId = Uuid::randomHex();
        $this->resolvedAclRoleId = $roleId;
        $deLocaleId = Uuid::randomHex();
        $this->localeIds['de-DE'] = $deLocaleId;

        $result = $this->createService()->findOrCreateAdmin($provider, new MappedProfile(
            'jane.doe@example.com',
            username: 'jdoe',
            firstName: 'Jane',
            lastName: 'Doe',
            locale: 'de-DE',
            zoneinfo: 'Europe/Berlin',
            groups: ['editors'],
        ), $this->context);

        self::assertCount(1, $this->userCreates);
        $payload = $this->userCreates[0];

        self::assertTrue(Uuid::isValid($payload['id']));
        self::assertSame(64, \strlen($payload['password']));
        unset($payload['password']);

        self::assertSame([
            'id' => $payload['id'],
            'localeId' => $deLocaleId,
            'username' => 'jdoe',
            'firstName' => 'Jane',
            'lastName' => 'Doe',
            'email' => 'jane.doe@example.com',
            'active' => true,
            'admin' => false,
            'aclRoles' => [['id' => $roleId]],
            'timeZone' => 'Europe/Berlin',
        ], $payload);

        self::assertSame([], $this->userUpdates);
        self::assertCount(1, $this->bindingCreates);
        self::assertSame($payload['id'], $this->bindingCreates[0]['userId']);
        self::assertSame($provider->getId(), $this->bindingCreates[0]['providerId']);
        self::assertSame(Sw6OidcUserProviderEntity::USER_TYPE_ADMIN, $this->bindingCreates[0]['userType']);
        self::assertSame($payload['id'], $result->getId());
    }

    public function testCreateFallsBackForMissingNamesLocaleAndInvalidTimeZone(): void
    {
        $provider = $this->provider();
        $provider->setAutoCreateAdmin(true);
        $this->resolvedAclRoleId = Uuid::randomHex();
        $enLocaleId = Uuid::randomHex();
        $this->localeIds['en-GB'] = $enLocaleId;

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile(
            'jane@example.com',
            locale: 'xx-XX',
            zoneinfo: 'Mars/Olympus',
        ), $this->context);

        $payload = $this->userCreates[0];
        self::assertSame('jane', $payload['username']);
        self::assertSame('jane@example.com', $payload['firstName']);
        self::assertSame('-', $payload['lastName']);
        self::assertSame($enLocaleId, $payload['localeId']);
        self::assertArrayNotHasKey('timeZone', $payload);
    }

    public function testCreateFallsBackToFirstAvailableLocaleWhenEnGbIsMissing(): void
    {
        $provider = $this->provider();
        $provider->setAutoCreateAdmin(true);
        $this->resolvedAclRoleId = Uuid::randomHex();

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile('jane@example.com'), $this->context);

        self::assertSame($this->fallbackLocaleId, $this->userCreates[0]['localeId']);
    }

    public function testCreateDerivesSanitizedUsernameFromEmailLocalPartAndDeduplicates(): void
    {
        $provider = $this->provider();
        $provider->setAutoCreateAdmin(true);
        $this->resolvedAclRoleId = Uuid::randomHex();
        $this->takenUsernames = ['jane.doe', 'jane.doe1'];

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile('jane.doe+test@example.com'), $this->context);

        // "+" is stripped by the sanitizer: "jane.doe+test" -> "jane.doetest"
        self::assertSame('jane.doetest', $this->userCreates[0]['username']);
    }

    public function testCreateDeduplicatesMappedUsernameWithIncrementingSuffix(): void
    {
        $provider = $this->provider();
        $provider->setAutoCreateAdmin(true);
        $this->resolvedAclRoleId = Uuid::randomHex();
        $this->takenUsernames = ['jane', 'jane1', 'jane2'];

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile('someone@example.com', username: 'jane'), $this->context);

        self::assertSame('jane3', $this->userCreates[0]['username']);
    }

    public function testCreateUsesFallbackUsernameWhenSanitizedBaseIsEmpty(): void
    {
        $provider = $this->provider();
        $provider->setAutoCreateAdmin(true);
        $this->resolvedAclRoleId = Uuid::randomHex();
        $this->takenUsernames = ['user'];

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile('x@example.com', username: '!!!'), $this->context);

        self::assertSame('user1', $this->userCreates[0]['username']);
    }

    public function testCreateImportsAvatarAndUpdatesUser(): void
    {
        $provider = $this->provider();
        $provider->setAutoCreateAdmin(true);
        $this->resolvedAclRoleId = Uuid::randomHex();
        $newAvatarId = Uuid::randomHex();

        $this->expectAvatarImport('https://idp.example.com/a.png', null, $newAvatarId);

        $this->createService()->findOrCreateAdmin($provider, new MappedProfile('jane@example.com', picture: 'https://idp.example.com/a.png'), $this->context);

        $userId = $this->userCreates[0]['id'];
        self::assertArrayNotHasKey('avatarId', $this->userCreates[0]);
        self::assertSame([['id' => $userId, 'avatarId' => $newAvatarId]], $this->userUpdates);
        self::assertSame([['id' => $newAvatarId, 'private' => false]], $this->mediaUpdates);
    }

    public function testCreateDoesNotFailLoginWhenAvatarImportFails(): void
    {
        $provider = $this->provider();
        $provider->setAutoCreateAdmin(true);
        $this->resolvedAclRoleId = Uuid::randomHex();

        $this->fileFetcher->method('fetchFromURL')->willThrowException(new \RuntimeException('blocked'));

        $result = $this->createService()->findOrCreateAdmin($provider, new MappedProfile('jane@example.com', picture: 'https://10.0.0.1/a.png'), $this->context);

        self::assertCount(1, $this->userCreates);
        self::assertSame([], $this->userUpdates);
        self::assertSame($this->userCreates[0]['id'], $result->getId());
    }

    private function createService(): AdminProvisioningService
    {
        return new AdminProvisioningService(
            $this->userRepository(),
            $this->localeRepository(),
            $this->mediaRepository(),
            $this->groupMappingResolver,
            new UserProviderBindingService($this->userProviderRepository()),
            $this->mediaService,
            $this->fileFetcher,
            new TimeZoneValidator(),
            $this->createStub(LoggerInterface::class),
        );
    }

    private function expectAvatarImport(string $url, ?string $expectedExistingMediaId, string $returnedMediaId): void
    {
        $this->fileFetcher->expects(self::once())
            ->method('fetchFromURL')
            ->willReturnCallback(static function (string $pictureUrl, string $tempFile) use ($url): MediaFile {
                self::assertSame($url, $pictureUrl);

                return new MediaFile($tempFile, 'image/png', 'png', 123);
            });
        $this->fileFetcher->expects(self::once())
            ->method('cleanUpTempFile')
            ->willReturnCallback(static function (MediaFile $file): void {
                if (is_file($file->getFileName())) {
                    unlink($file->getFileName());
                }
            });
        $this->mediaService->expects(self::once())
            ->method('saveMediaFile')
            ->willReturnCallback(static function (
                MediaFile $file,
                string $name,
                Context $ctx,
                ?string $folder,
                ?string $mediaId,
                bool $private,
            ) use (
                $expectedExistingMediaId,
                $returnedMediaId,
            ): string {
                self::assertStringStartsWith('sw6oidc-avatar-', $name);
                self::assertSame('user', $folder);
                self::assertSame($expectedExistingMediaId, $mediaId);
                self::assertFalse($private);

                return $returnedMediaId;
            });
    }

    private function provider(): Sw6OidcProviderEntity
    {
        $provider = new Sw6OidcProviderEntity();
        $provider->setId(Uuid::randomHex());

        return $provider;
    }

    private function user(string $username): UserEntity
    {
        $user = new UserEntity();
        $user->setId(Uuid::randomHex());
        $user->setUsername($username);
        $user->setEmail($username . '@example.com');

        return $user;
    }

    private function userRepository(): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);

        $repository->method('search')->willReturnCallback(function (Criteria $criteria, Context $context): EntitySearchResult {
            $ids = $criteria->getIds();

            if ($ids === []) {
                // findByEmail()
                $users = $this->existingUser === null ? [] : [$this->existingUser];
            } elseif ($this->existingUser !== null && $ids === [$this->existingUser->getId()]) {
                $users = [$this->existingUser];
            } else {
                $id = $ids[0];
                \assert(\is_string($id));
                $user = new UserEntity();
                $user->setId($id);
                $users = [$user];
            }

            return new EntitySearchResult(UserDefinition::ENTITY_NAME, \count($users), new UserCollection($users), null, $criteria, $context);
        });

        $repository->method('searchIds')->willReturnCallback(function (Criteria $criteria, Context $context): IdSearchResult {
            $username = $this->filterValue($criteria, 'username');
            $ids = \in_array($username, $this->takenUsernames, true) ? [Uuid::randomHex()] : [];

            return $this->idSearchResult($ids, $criteria, $context);
        });

        $repository->method('create')->willReturnCallback(function (array $payload, Context $context): EntityWrittenContainerEvent {
            array_push($this->userCreates, ...$payload);

            return $this->writtenEvent($context);
        });

        $repository->method('update')->willReturnCallback(function (array $payload, Context $context): EntityWrittenContainerEvent {
            array_push($this->userUpdates, ...$payload);

            return $this->writtenEvent($context);
        });

        return $repository;
    }

    private function localeRepository(): EntityRepository
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('searchIds')->willReturnCallback(function (Criteria $criteria, Context $context): IdSearchResult {
            $code = $this->filterValue($criteria, 'code');

            if ($code === null) {
                return $this->idSearchResult([$this->fallbackLocaleId], $criteria, $context);
            }

            $id = $this->localeIds[$code] ?? null;

            return $this->idSearchResult($id === null ? [] : [$id], $criteria, $context);
        });

        return $repository;
    }

    private function mediaRepository(): EntityRepository
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('update')->willReturnCallback(function (array $payload, Context $context): EntityWrittenContainerEvent {
            array_push($this->mediaUpdates, ...$payload);

            return $this->writtenEvent($context);
        });

        return $repository;
    }

    private function userProviderRepository(): EntityRepository
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('search')->willReturnCallback(function (Criteria $criteria, Context $context): EntitySearchResult {
            $bindings = [];
            $userId = $this->filterValue($criteria, 'userId');

            if ($this->boundProviderId !== null && $userId !== null) {
                $binding = new Sw6OidcUserProviderEntity();
                $binding->setId(Uuid::randomHex());
                $binding->setUserType(Sw6OidcUserProviderEntity::USER_TYPE_ADMIN);
                $binding->setUserId($userId);
                $binding->setProviderId($this->boundProviderId);
                $bindings[] = $binding;
            }

            return new EntitySearchResult(
                Sw6OidcUserProviderDefinition::ENTITY_NAME,
                \count($bindings),
                new Sw6OidcUserProviderCollection($bindings),
                null,
                $criteria,
                $context,
            );
        });
        $repository->method('create')->willReturnCallback(function (array $payload, Context $context): EntityWrittenContainerEvent {
            array_push($this->bindingCreates, ...$payload);

            return $this->writtenEvent($context);
        });

        return $repository;
    }

    private function writtenEvent(Context $context): EntityWrittenContainerEvent
    {
        return new EntityWrittenContainerEvent($context, new NestedEventCollection(), []);
    }

    private function filterValue(Criteria $criteria, string $field): ?string
    {
        foreach ($criteria->getFilters() as $filter) {
            if ($filter instanceof EqualsFilter && $filter->getField() === $field) {
                $value = $filter->getValue();

                return \is_string($value) ? $value : null;
            }
        }

        return null;
    }

    /**
     * @param string[] $ids
     */
    private function idSearchResult(array $ids, Criteria $criteria, Context $context): IdSearchResult
    {
        return new IdSearchResult(
            \count($ids),
            array_map(static fn (string $id): array => ['primaryKey' => $id, 'data' => []], $ids),
            $criteria,
            $context,
        );
    }
}
