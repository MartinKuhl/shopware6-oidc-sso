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
        $challenge = random_bytes(32);
        $options = $this->buildOptions($userType, $userId, $username, $displayName, $rpId, $rpName, $challenge);

        $nonce = bin2hex(random_bytes(16));

        // We cache the raw inputs and rebuild the options object directly via
        // its constructor in verifyAndPersist(), rather than caching
        // $options->jsonSerialize() and reloading it via
        // PublicKeyCredentialCreationOptions::createFromArray(). That
        // round-trip is broken in web-auth/webauthn-lib 4.9.3:
        // PublicKeyCredentialUserEntity::jsonSerialize() encodes the user id
        // as url-safe base64, but ::createFromArray() decodes it as
        // *standard* base64 - which throws a raw sodium_base642bin() error
        // whenever the id (a sha256 hash here) happens to contain a
        // url-safe-only character. Deterministic per user, so it either
        // always fails or never does for a given account.
        $this->cache->save(
            self::CACHE_PREFIX . $nonce,
            json_encode([
                'challenge' => bin2hex($challenge),
                'userType' => $userType,
                'userId' => $userId,
                'username' => $username,
                'displayName' => $displayName,
                'rpId' => $rpId,
                'rpName' => $rpName,
            ], JSON_THROW_ON_ERROR),
            self::TTL_SECONDS,
        );

        return ['optionsJson' => json_encode($options->jsonSerialize(), JSON_THROW_ON_ERROR), 'nonce' => $nonce];
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

        $options = $this->buildOptions(
            $stored['userType'],
            $stored['userId'],
            $stored['username'],
            $stored['displayName'],
            $stored['rpId'],
            $stored['rpName'],
            hex2bin($stored['challenge']),
        );

        $publicKeyCredential = $this->ceremonyFactory->credentialLoader()->load($credentialResponseJson);
        $response = $publicKeyCredential->getResponse();

        if (!$response instanceof AuthenticatorAttestationResponse) {
            throw new PasskeyCeremonyException('Expected a WebAuthn attestation (registration) response.');
        }

        $source = $this->ceremonyFactory->attestationResponseValidator()->check($response, $options, $request);

        $this->credentialRepository->saveNewCredentialSource($source, $stored['userType'], $stored['userId'], $nickname);
    }

    private function buildOptions(
        string $userType,
        string $userId,
        string $username,
        string $displayName,
        string $rpId,
        string $rpName,
        string $challenge,
    ): PublicKeyCredentialCreationOptions {
        $userHandle = hash('sha256', $userType . ':' . $userId, true);
        $userEntity = new PublicKeyCredentialUserEntity($username, $userHandle, $displayName);

        $excludeCredentials = array_map(
            static fn (\Webauthn\PublicKeyCredentialSource $source): \Webauthn\PublicKeyCredentialDescriptor => $source->getPublicKeyCredentialDescriptor(),
            $this->credentialRepository->findAllForUserEntity($userEntity),
        );

        return new PublicKeyCredentialCreationOptions(
            $this->ceremonyFactory->rpEntity($rpId, $rpName),
            $userEntity,
            $challenge,
            $this->ceremonyFactory->credentialParameters(),
            authenticatorSelection: $this->ceremonyFactory->residentKeyAuthenticatorSelection(),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $excludeCredentials,
            timeout: 60000,
        );
    }
}
