<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Passkey;

use MartinKuhl\Sw6Oidc\Service\Cache\AtomicCacheInterface;
use MartinKuhl\Sw6Oidc\Service\Passkey\Exception\PasskeyCeremonyException;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
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
        // its constructor in verifyAndPersist() rather than round-tripping the
        // serialized options. Simpler, and it sidesteps the url-safe vs
        // standard base64 user-id mismatch that broke that round-trip in
        // webauthn-lib 4.9.3 (5.x decodes both alphabets, but there is no
        // reason to depend on that).
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

        return ['optionsJson' => $this->ceremonyFactory->serializeOptions($options), 'nonce' => $nonce];
    }

    /**
     * @param string $host the request host the ceremony ran on (origin/rpId check)
     *
     * @throws PasskeyCeremonyException
     */
    public function verifyAndPersist(string $nonce, string $credentialResponseJson, string $host, ?string $nickname): void
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
            (string) hex2bin($stored['challenge']),
        );

        $response = $this->ceremonyFactory->loadCredential($credentialResponseJson)->response;

        if (!$response instanceof AuthenticatorAttestationResponse) {
            throw new PasskeyCeremonyException('Expected a WebAuthn attestation (registration) response.');
        }

        try {
            $record = $this->ceremonyFactory->attestationResponseValidator()->check($response, $options, $host);
        } catch (\Throwable $exception) {
            throw new PasskeyCeremonyException($exception->getMessage(), 0, $exception);
        }

        $this->credentialRepository->saveNewCredentialRecord($record, $stored['userType'], $stored['userId'], $nickname);
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
            static fn (CredentialRecord $record): PublicKeyCredentialDescriptor => $record->getPublicKeyCredentialDescriptor(),
            $this->credentialRepository->findAllForUserHandle($userHandle),
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
