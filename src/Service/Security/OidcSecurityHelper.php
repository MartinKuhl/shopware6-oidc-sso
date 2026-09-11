<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Security;

use MartinKuhl\Sw6Oidc\Service\Cache\AtomicCacheInterface;
use MartinKuhl\Sw6Oidc\Service\Security\Exception\InvalidStateException;

/**
 * PKCE, OAuth state, and OIDC nonce generation/consumption — the Shopware
 * equivalent of the Magento module's Helper/OAuthSecurityHelper.php, built on
 * AtomicCacheInterface so state can never be replayed (getAndDelete is atomic).
 */
class OidcSecurityHelper
{
    private const FLOW_TTL_SECONDS = 600;
    private const FLOW_CACHE_PREFIX = 'sw6oidc_flow_';

    public function __construct(private readonly AtomicCacheInterface $cache)
    {
    }

    /**
     * Starts an SP-initiated authorization request: generates the PKCE verifier
     * and OIDC nonce, stores the whole flow under a fresh opaque `state` value,
     * and returns what the authorize-URL builder needs.
     *
     * @return array{state: string, nonce: string, codeChallenge: string}
     */
    public function beginAuthorizationRequest(
        string $providerId,
        string $loginType,
        string $relayState,
        string $codeChallengeMethod,
    ): array {
        $state = $this->randomUrlSafeString(32);
        $codeVerifier = $this->randomUrlSafeString(64);
        $nonce = $this->randomUrlSafeString(32);

        $context = new AuthorizationFlowContext($providerId, $loginType, $relayState, $codeVerifier, $codeChallengeMethod, $nonce);

        $this->cache->save(
            self::FLOW_CACHE_PREFIX . $state,
            json_encode($context->toArray(), JSON_THROW_ON_ERROR),
            self::FLOW_TTL_SECONDS,
        );

        return [
            'state' => $state,
            'nonce' => $nonce,
            'codeChallenge' => $this->deriveCodeChallenge($codeVerifier, $codeChallengeMethod),
        ];
    }

    /**
     * Redeems the `state` value from an authorization callback. Can only ever
     * succeed once per flow — a replayed callback fails with InvalidStateException.
     *
     * @throws InvalidStateException
     */
    public function consumeAuthorizationFlow(?string $state): AuthorizationFlowContext
    {
        if ($state === null || $state === '') {
            throw new InvalidStateException('Callback is missing the "state" parameter.');
        }

        $raw = $this->cache->getAndDelete(self::FLOW_CACHE_PREFIX . $state);

        if ($raw === null) {
            throw new InvalidStateException('Unknown, expired, or already-used OAuth state.');
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidStateException('Stored OAuth state is corrupted.', 0, $exception);
        }

        return AuthorizationFlowContext::fromArray($data);
    }

    public function deriveCodeChallenge(string $codeVerifier, string $codeChallengeMethod): string
    {
        if ($codeChallengeMethod === 'plain') {
            return $codeVerifier;
        }

        return $this->base64UrlEncode(hash('sha256', $codeVerifier, true));
    }

    private function randomUrlSafeString(int $bytes): string
    {
        return $this->base64UrlEncode(random_bytes($bytes));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
