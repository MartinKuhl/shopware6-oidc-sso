<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Passkey;

use MartinKuhl\Sw6Oidc\Service\Passkey\PasskeyCredentialRepository;
use MartinKuhl\Sw6Oidc\Service\Passkey\WebauthnCeremonyFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Webauthn\TrustPath\EmptyTrustPath;

#[CoversClass(PasskeyCredentialRepository::class)]
final class PasskeyCredentialRepositoryTest extends TestCase
{
    /**
     * A public_key value exactly as webauthn-lib 4.9's
     * PublicKeyCredentialSource::jsonSerialize() stored it (public key
     * material only) — must still deserialize after the 5.x migration.
     */
    private const LEGACY_4X_PUBLIC_KEY = '{"publicKeyCredentialId":"OqgFHZhKQWaxLK8ZEvagkw","type":"public-key","transports":[],"attestationType":"none","trustPath":{"type":"Webauthn\\\\TrustPath\\\\EmptyTrustPath"},"aaguid":"d548826e-79b4-db40-a3d8-11116f7e8349","credentialPublicKey":"pQECAyYgASFYIBh2IVA9JOGag_dFVuLV8s_TlqOWKaw_K23SCLlGDSIXIlggEyJ6eoq3RfZGfw94vtDmvhhJJd0JQ3jouC6xXJT9P-c","userHandle":"ZAzT6hkL9_gnPQspFSBDWdo_YSAsIguSHMgxav-jiSI","counter":0,"backupEligible":true,"backupStatus":true}';

    private InMemoryPasskeyCredentialStore $store;

    private PasskeyCredentialRepository $repository;

    protected function setUp(): void
    {
        $this->store = new InMemoryPasskeyCredentialStore();
        $this->repository = new PasskeyCredentialRepository(
            $this->store->wire($this->createMock(EntityRepository::class)),
            new WebauthnCeremonyFactory(),
            new NullLogger(),
        );
    }

    public function testLegacy4xStoredCredentialDeserializes(): void
    {
        $rawId = (string) base64_decode('OqgFHZhKQWaxLK8ZEvagkw==', true);
        $this->addLegacyRow($rawId);

        $record = $this->repository->findOneByCredentialId($rawId);

        self::assertNotNull($record);
        self::assertSame($rawId, $record->publicKeyCredentialId);
        self::assertSame('none', $record->attestationType);
        self::assertInstanceOf(EmptyTrustPath::class, $record->trustPath);
        self::assertSame(SoftwareAuthenticator::unb64('ZAzT6hkL9_gnPQspFSBDWdo_YSAsIguSHMgxav-jiSI'), $record->userHandle);
        self::assertTrue($record->backupEligible);
    }

    public function testSerializedRecordRoundTrips(): void
    {
        $rawId = (string) base64_decode('OqgFHZhKQWaxLK8ZEvagkw==', true);
        $this->addLegacyRow($rawId);
        $record = $this->repository->findOneByCredentialId($rawId);
        self::assertNotNull($record);

        $record->counter = 7;
        $this->repository->updateAfterAssertion($record);

        $reloaded = $this->repository->findOneByCredentialId($rawId);
        self::assertNotNull($reloaded);
        self::assertSame(7, $reloaded->counter);
        self::assertSame($record->credentialPublicKey, $reloaded->credentialPublicKey);
        self::assertSame(7, $this->store->rows[array_key_first($this->store->rows)]['signCount']);
    }

    public function testFindAllForUserHandleMatchesByHexHandle(): void
    {
        $rawId = (string) base64_decode('OqgFHZhKQWaxLK8ZEvagkw==', true);
        $this->addLegacyRow($rawId);

        $handle = SoftwareAuthenticator::unb64('ZAzT6hkL9_gnPQspFSBDWdo_YSAsIguSHMgxav-jiSI');

        self::assertCount(1, $this->repository->findAllForUserHandle($handle));
        self::assertSame([], $this->repository->findAllForUserHandle(random_bytes(32)));
    }

    public function testUpdateAfterAssertionIgnoresUnknownCredential(): void
    {
        $rawId = (string) base64_decode('OqgFHZhKQWaxLK8ZEvagkw==', true);
        $this->addLegacyRow($rawId);
        $record = $this->repository->findOneByCredentialId($rawId);
        self::assertNotNull($record);
        $this->store->rows = [];

        $this->repository->updateAfterAssertion($record);

        self::assertSame([], $this->store->rows);
    }

    private function addLegacyRow(string $rawId): void
    {
        $this->store->add([
            'id' => Uuid::randomHex(),
            'userType' => 'customer',
            'userId' => Uuid::randomHex(),
            'credentialId' => base64_encode($rawId),
            'publicKey' => self::LEGACY_4X_PUBLIC_KEY,
            'userHandle' => bin2hex(SoftwareAuthenticator::unb64('ZAzT6hkL9_gnPQspFSBDWdo_YSAsIguSHMgxav-jiSI')),
        ]);
    }
}
