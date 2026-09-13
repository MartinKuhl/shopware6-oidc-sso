<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Passkey;

use MartinKuhl\Sw6Oidc\Core\Content\PasskeyCredential\Sw6OidcPasskeyCredentialEntity;
use MartinKuhl\Sw6Oidc\Service\Cache\AtomicCacheInterface;
use MartinKuhl\Sw6Oidc\Service\Passkey\Exception\PasskeyCeremonyException;
use Psr\Http\Message\ServerRequestInterface;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Builds WebAuthn authentication (assertion) ceremonies and verifies the
 * result, resolving back to our own userType/userId — shared by Storefront
 * (usernameless/discoverable: empty allowCredentials) and Administration
 * (email-scoped: allowCredentials limited to that admin's own credentials).
 * Mirrors the Magento module's Model/Passkey/PasskeyAuthenticationService.php.
 */
class PasskeyAuthenticationService
{
    private const TTL_SECONDS = 300;
    private const CACHE_PREFIX = 'sw6oidc_passkey_auth_';

    public function __construct(
        private readonly WebauthnCeremonyFactory $ceremonyFactory,
        private readonly PasskeyCredentialRepository $credentialRepository,
        private readonly AtomicCacheInterface $cache,
    ) {
    }

    /**
     * @param PublicKeyCredentialDescriptor[] $allowCredentials empty = usernameless/discoverable login
     *
     * @return array{optionsJson: string, nonce: string}
     */
    public function buildRequestOptions(array $allowCredentials, string $rpId): array
    {
        $options = new PublicKeyCredentialRequestOptions(
            random_bytes(32),
            rpId: $rpId,
            allowCredentials: $allowCredentials,
            timeout: 60000,
        );

        $nonce = bin2hex(random_bytes(16));
        $optionsJson = json_encode($options->jsonSerialize(), JSON_THROW_ON_ERROR);

        $this->cache->save(self::CACHE_PREFIX . $nonce, $optionsJson, self::TTL_SECONDS);

        return ['optionsJson' => $optionsJson, 'nonce' => $nonce];
    }

    /**
     * @return array{userType: string, userId: string, credentialId: string}
     *
     * @throws PasskeyCeremonyException
     */
    public function verifyAssertion(string $nonce, string $assertionResponseJson, ServerRequestInterface $request): array
    {
        $optionsJson = $this->cache->getAndDelete(self::CACHE_PREFIX . $nonce);

        if ($optionsJson === null) {
            throw new PasskeyCeremonyException('Unknown, expired, or already-used passkey login ceremony.');
        }

        $options = PublicKeyCredentialRequestOptions::createFromArray(json_decode($optionsJson, true, 512, JSON_THROW_ON_ERROR));

        $publicKeyCredential = $this->ceremonyFactory->credentialLoader()->load($assertionResponseJson);
        $response = $publicKeyCredential->getResponse();

        if (!$response instanceof AuthenticatorAssertionResponse) {
            throw new PasskeyCeremonyException('Expected a WebAuthn assertion (login) response.');
        }

        $rawId = $publicKeyCredential->getRawId();
        $credentialIdBase64 = base64_encode($rawId);
        $entity = $this->credentialRepository->findEntityByCredentialId($credentialIdBase64);

        if ($entity === null) {
            throw new PasskeyCeremonyException('This passkey is not registered.');
        }

        // check()'s first argument, when passed as a string, gets forwarded
        // as-is to PasskeyCredentialRepository::findOneByCredentialId(),
        // which base64-encodes it itself to match how credentialId is
        // stored - passing the already-encoded $credentialIdBase64 here
        // double-encodes it, so the lookup never matches and check() throws
        // "The credential ID is invalid." Raw bytes only.
        $this->ceremonyFactory->assertionResponseValidator()->check(
            $rawId,
            $response,
            $options,
            $request,
            null,
            [],
        );

        \assert($entity instanceof Sw6OidcPasskeyCredentialEntity);

        return ['userType' => $entity->getUserType(), 'userId' => $entity->getUserId(), 'credentialId' => $entity->getId()];
    }
}
