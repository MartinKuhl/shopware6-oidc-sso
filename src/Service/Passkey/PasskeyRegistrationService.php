<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Passkey;

use MartinKuhl\Sw6Oidc\Service\Cache\AtomicCacheInterface;
use MartinKuhl\Sw6Oidc\Service\Passkey\Exception\PasskeyCeremonyException;
use Psr\Http\Message\ServerRequestInterface;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Builds WebAuthn registration (attestation) ceremonies and verifies +
 * persists the resulting credential. Shared by the Storefront customer
 * self-service flow and the Administration self-service flow — the caller
 * supplies userType/userId/rpId/rpName, everything else is identical.
 * Mirrors the Magento module's Model/Passkey/PasskeyRegistrationService.php.
 */
class PasskeyRegistrationService
{
    private const TTL_SECONDS = 300;
    private const CACHE_PREFIX = 'sw6oidc_passkey_reg_';

    public function __construct(
        private readonly WebauthnCeremonyFactory $ceremonyFactory,
        private readonly PasskeyCredentialRepository $credentialRepository,
        private readonly AtomicCacheInterface $cache,
    ) {
    }

    /**
     * @return array{optionsJson: string, nonce: string}
     */
    public function buildCreationOptions(string $userType, string $userId, string $username, string $displayName, string $rpId, string $rpName): array
    {
        $userHandle = hash('sha256', $userType . ':' . $userId, true);
        $userEntity = new PublicKeyCredentialUserEntity($username, $userHandle, $displayName);

        $excludeCredentials = array_map(
            static fn ($source) => $source->getPublicKeyCredentialDescriptor(),
            $this->credentialRepository->findAllForUserEntity($userEntity),
        );

        $options = new PublicKeyCredentialCreationOptions(
            $this->ceremonyFactory->rpEntity($rpId, $rpName),
            $userEntity,
            random_bytes(32),
            $this->ceremonyFactory->credentialParameters(),
            timeout: 60000,
            excludeCredentials: $excludeCredentials,
            authenticatorSelection: $this->ceremonyFactory->residentKeyAuthenticatorSelection(),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
        );

        $nonce = bin2hex(random_bytes(16));
        $optionsJson = json_encode($options->jsonSerialize(), JSON_THROW_ON_ERROR);

        $this->cache->save(
            self::CACHE_PREFIX . $nonce,
            json_encode(['options' => $optionsJson, 'userType' => $userType, 'userId' => $userId], JSON_THROW_ON_ERROR),
            self::TTL_SECONDS,
        );

        return ['optionsJson' => $optionsJson, 'nonce' => $nonce];
    }

    /**
     * @throws PasskeyCeremonyException
     */
    public function verifyAndPersist(string $nonce, string $credentialResponseJson, ServerRequestInterface $request, ?string $nickname): void
    {
        $raw = $this->cache->getAndDelete(self::CACHE_PREFIX . $nonce);

        if ($raw === null) {
            throw new PasskeyCeremonyException('Unknown, expired, or already-used passkey registration ceremony.');
        }

        $stored = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $options = PublicKeyCredentialCreationOptions::createFromArray(json_decode($stored['options'], true, 512, JSON_THROW_ON_ERROR));

        $publicKeyCredential = $this->ceremonyFactory->credentialLoader()->load($credentialResponseJson);
        $response = $publicKeyCredential->getResponse();

        if (!$response instanceof AuthenticatorAttestationResponse) {
            throw new PasskeyCeremonyException('Expected a WebAuthn attestation (registration) response.');
        }

        $source = $this->ceremonyFactory->attestationResponseValidator()->check($response, $options, $request);

        $this->credentialRepository->saveNewCredentialSource($source, $stored['userType'], $stored['userId'], $nickname);
    }
}
