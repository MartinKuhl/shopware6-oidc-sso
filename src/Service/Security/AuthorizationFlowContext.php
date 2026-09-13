<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Security;

/**
 * Everything needed to complete one SP-initiated OIDC round trip, stored
 * server-side under the opaque `state` value for the duration of the IdP
 * redirect (see OidcSecurityHelper). Bundling these fields together, keyed by
 * `state` itself, replaces the Magento module's pipe-delimited relay-state
 * string plus separate PKCE-verifier/nonce cookies with a single atomic-cache
 * round trip.
 */
final readonly class AuthorizationFlowContext
{
    public function __construct(
        public string $providerId,
        /** 'customer' | 'admin' */
        public string $loginType,
        public string $relayState,
        public string $codeVerifier,
        /** 'S256' | 'plain' */
        public string $codeChallengeMethod,
        public string $nonce,
    ) {
    }

    /**
     * @return array{providerId: string, loginType: string, relayState: string, codeVerifier: string, codeChallengeMethod: string, nonce: string}
     */
    public function toArray(): array
    {
        return [
            'providerId' => $this->providerId,
            'loginType' => $this->loginType,
            'relayState' => $this->relayState,
            'codeVerifier' => $this->codeVerifier,
            'codeChallengeMethod' => $this->codeChallengeMethod,
            'nonce' => $this->nonce,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['providerId'],
            (string) $data['loginType'],
            (string) $data['relayState'],
            (string) $data['codeVerifier'],
            (string) $data['codeChallengeMethod'],
            (string) $data['nonce'],
        );
    }
}
