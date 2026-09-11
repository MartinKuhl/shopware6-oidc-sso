<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Passkey;

use MartinKuhl\Sw6Oidc\Core\Content\PasskeyCredential\Sw6OidcPasskeyCredentialEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialSourceRepository;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Persists WebAuthn credentials to sw6oidc_passkey_credential — implements
 * web-auth/webauthn-lib's own repository contract directly (independent code,
 * not reused from any third-party plugin), the sole seam between the
 * library's PublicKeyCredentialSource objects and our DAL storage. Mirrors
 * the Magento module's ResourceModel/PasskeyCredentialRepository.php.
 *
 * Two save paths, matching how the library itself is designed to be used:
 *  - saveCredentialSource() satisfies the interface contract the library
 *    calls internally to bump the signature counter after a successful
 *    assertion (login) — a no-op if the credential row doesn't exist yet.
 *  - saveNewCredentialSource() is our own method, called explicitly by
 *    PasskeyRegistrationService right after attestation (registration)
 *    validation succeeds, since the library does not persist new credentials
 *    on our behalf — only the application knows which user_type/user_id/
 *    nickname a brand-new credential belongs to.
 *
 * web-auth/webauthn-lib is pinned to ^4.7 in composer.json: this interface
 * (and the repository-based validator constructors in
 * WebauthnCeremonyFactory) was removed entirely in 5.x in favor of a
 * CredentialRecord-based design, so this class only works against 4.x.
 */
class PasskeyCredentialRepository implements PublicKeyCredentialSourceRepository
{
    private Context $context;

    public function __construct(
        private readonly EntityRepository $passkeyCredentialRepository,
        private readonly LoggerInterface $logger,
    ) {
        $this->context = Context::createDefaultContext();
    }

    public function setContext(Context $context): void
    {
        $this->context = $context;
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?PublicKeyCredentialSource
    {
        $entity = $this->findEntityByCredentialId(base64_encode($publicKeyCredentialId));

        return $entity !== null ? $this->toSource($entity) : null;
    }

    /**
     * @return PublicKeyCredentialSource[]
     */
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array
    {
        // The library's user.id is raw bytes; stored/compared as hex since a
        // varchar column can't safely round-trip arbitrary binary data.
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('userHandle', bin2hex($publicKeyCredentialUserEntity->getId())));

        $sources = [];

        foreach ($this->passkeyCredentialRepository->search($criteria, $this->context)->getEntities() as $entity) {
            \assert($entity instanceof Sw6OidcPasskeyCredentialEntity);
            $sources[] = $this->toSource($entity);
        }

        return $sources;
    }

    public function saveCredentialSource(PublicKeyCredentialSource $publicKeyCredentialSource): void
    {
        $credentialId = base64_encode($publicKeyCredentialSource->getPublicKeyCredentialId());
        $existing = $this->findEntityByCredentialId($credentialId);

        if ($existing === null) {
            $this->logger->warning('sw6oidc: saveCredentialSource() called for an unknown credential; ignoring.', [
                'credentialId' => $credentialId,
            ]);

            return;
        }

        $this->passkeyCredentialRepository->update([[
            'id' => $existing->getId(),
            'publicKey' => json_encode($publicKeyCredentialSource->jsonSerialize(), JSON_THROW_ON_ERROR),
            'signCount' => $publicKeyCredentialSource->getCounter(),
        ]], $this->context);
    }

    public function saveNewCredentialSource(
        PublicKeyCredentialSource $publicKeyCredentialSource,
        string $userType,
        string $userId,
        ?string $nickname,
    ): void {
        $this->passkeyCredentialRepository->create([[
            'id' => Uuid::randomHex(),
            'userType' => $userType,
            'userId' => $userId,
            'credentialId' => base64_encode($publicKeyCredentialSource->getPublicKeyCredentialId()),
            'publicKey' => json_encode($publicKeyCredentialSource->jsonSerialize(), JSON_THROW_ON_ERROR),
            'signCount' => $publicKeyCredentialSource->getCounter(),
            'userHandle' => bin2hex($publicKeyCredentialSource->getUserHandle()),
            'nickname' => $nickname,
        ]], $this->context);
    }

    public function findEntityByCredentialId(string $base64CredentialId): ?Sw6OidcPasskeyCredentialEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('credentialId', $base64CredentialId));
        $criteria->setLimit(1);

        $entity = $this->passkeyCredentialRepository->search($criteria, $this->context)->first();

        return $entity instanceof Sw6OidcPasskeyCredentialEntity ? $entity : null;
    }

    private function toSource(Sw6OidcPasskeyCredentialEntity $entity): PublicKeyCredentialSource
    {
        return PublicKeyCredentialSource::createFromArray(
            json_decode($entity->getPublicKey(), true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
