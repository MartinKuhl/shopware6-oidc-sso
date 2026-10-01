<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use MartinKuhl\Sw6Oidc\Service\Provisioning\AttributeMapper;
use MartinKuhl\Sw6Oidc\Service\Security\Exception\InvalidStateException;
use MartinKuhl\Sw6Oidc\Service\Security\Sw6OidcAccessControlEvaluator;
use MartinKuhl\Sw6Oidc\Service\Security\OidcSecurityHelper;
use MartinKuhl\Sw6Oidc\Service\Jwt\JwtVerifier;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;

/**
 * The shared post-redirect half of the OIDC flow: redeem state, exchange the
 * code for tokens, verify the id_token, fetch userinfo, normalize claims, and
 * map attributes. Used by both the Storefront and Administration callback
 * controllers so the two flows can never drift apart on JWT/claims handling —
 * mirrors the Magento module's ReadAuthorizationResponse +
 * OidcAuthenticationService being shared across both login types.
 */
class OidcCallbackProcessor
{
    public function __construct(
        private readonly ProviderResolver $providerResolver,
        private readonly OidcSecurityHelper $securityHelper,
        private readonly TokenExchangeService $tokenExchangeService,
        private readonly UserInfoService $userInfoService,
        private readonly JwtVerifier $jwtVerifier,
        private readonly ClaimsNormalizer $claimsNormalizer,
        private readonly AttributeMapper $attributeMapper,
        private readonly LoggerInterface $logger,
        private readonly Sw6OidcAccessControlEvaluator $accessControlEvaluator,
    ) {
    }

    /**
     * @throws InvalidStateException
     * @throws \MartinKuhl\Sw6Oidc\Service\Jwt\Exception\InvalidJwtException
     * @throws \MartinKuhl\Sw6Oidc\Service\Provisioning\Exception\MissingEmailClaimException
     * @throws \MartinKuhl\Sw6Oidc\Service\Provider\Exception\ProviderNotFoundException
     * @throws \MartinKuhl\Sw6Oidc\Service\Security\Exception\AccessControlDeniedException
     */
    /**
     * @param string $expectedLoginType the login type of the callback endpoint ('customer' or 'admin');
     *                                  a flow started for the other one is rejected
     */
    public function process(?string $code, ?string $state, string $redirectUri, string $expectedLoginType, Context $context): OidcCallbackResult
    {
        $flow = $this->securityHelper->consumeAuthorizationFlow($state);

        if ($flow->loginType !== $expectedLoginType) {
            throw new InvalidStateException(sprintf(
                'OAuth state was issued for a "%s" login, not "%s".',
                $flow->loginType,
                $expectedLoginType,
            ));
        }

        if ($code === null || $code === '') {
            throw new InvalidStateException('Callback is missing the "code" parameter.');
        }

        // Re-checked here, not only at flow start: the provider may have been
        // deactivated or re-scoped while the user was at the IdP.
        $provider = $this->providerResolver->getActiveById($flow->providerId, $flow->loginType, $context);

        $tokens = $this->tokenExchangeService->exchangeCodeForTokens($provider, $code, $redirectUri, $flow->codeVerifier);

        $this->logger->debug('sw6oidc: token exchange completed.', [
            'providerId' => $provider->getId(),
            'tokenKeys' => array_keys($tokens),
        ]);

        if (!isset($tokens['access_token']) || !\is_string($tokens['access_token'])) {
            throw new InvalidStateException('Token endpoint response did not include an access_token.');
        }

        $idTokenClaims = [];

        if (isset($tokens['id_token']) && \is_string($tokens['id_token'])) {
            $idTokenClaims = $this->jwtVerifier->verify(
                $tokens['id_token'],
                (string) $provider->getJwksEndpoint(),
                (string) $provider->getIssuer(),
                $provider->getClientId(),
                $flow->nonce,
                $provider->getJwksCacheTtl(),
                $provider->getHttpTimeout(),
            );
        } elseif ($this->requestsOpenIdScope($provider->getScope())) {
            // OIDC Core §3.1.3.3: an openid request always yields an id_token.
            // Its absence means the only signed statement about the user is
            // missing, so userinfo alone is not trusted.
            throw new InvalidStateException('Token response did not include the id_token required for the "openid" scope.');
        } else {
            $this->logger->warning('sw6oidc: provider does not request the "openid" scope; relying on userinfo alone.', [
                'providerId' => $provider->getId(),
            ]);
        }

        $userInfoClaims = $this->userInfoService->fetchClaims($provider, $tokens['access_token']);
        $mergedClaims = $this->mergeClaims($idTokenClaims, $userInfoClaims);

        if (!\is_string($mergedClaims['sub'] ?? null) || $mergedClaims['sub'] === '') {
            throw new InvalidStateException('The identity provider did not return a subject ("sub") claim.');
        }

        $this->logger->debug('sw6oidc: userinfo fetched and merged with id_token claims.', [
            'providerId' => $provider->getId(),
            'userInfoClaimKeys' => array_keys($userInfoClaims),
            'mergedClaimKeys' => array_keys($mergedClaims),
        ]);

        // Extracted from the *unflattened* claims: flattening a Zitadel-style
        // nested role object would turn its group-name keys into dotted leaf
        // paths and lose them (see ClaimsNormalizer::normalizeGroups()).
        $rawGroupsClaim = $mergedClaims[$provider->getGroupAttribute()] ?? null;
        $groups = $this->claimsNormalizer->decodeGroups(
            $this->claimsNormalizer->normalizeGroups($rawGroupsClaim),
            $provider->getGroupAttribute(),
            $provider->getBase64Claims(),
        );

        $flattenedClaims = $this->claimsNormalizer->flatten($mergedClaims, $provider->getBase64Claims());

        // Before mapping, so a denied login never reaches lookup/JIT-create/sync.
        $this->accessControlEvaluator->evaluate($provider->getId(), $flattenedClaims, $context);

        $profile = $this->attributeMapper->map($provider, $flattenedClaims, $groups, $context);

        $this->logger->debug('sw6oidc: claims mapped to profile.', [
            'providerId' => $provider->getId(),
            'groupCount' => \count($groups),
        ]);

        return new OidcCallbackResult($provider, $flow, $profile, $tokens, $idTokenClaims, $mergedClaims);
    }

    private function requestsOpenIdScope(string $scope): bool
    {
        return \in_array('openid', preg_split('/\s+/', trim($scope)) ?: [], true);
    }

    /**
     * Userinfo usually carries more claims and wins in general, but the
     * identity-defining claims come from the signed id_token (OIDC Core
     * §5.3.2): userinfo must describe the same subject, and `sub`, `email`
     * and `email_verified` are never taken from it when the id_token has them.
     *
     * @param array<string, mixed> $idTokenClaims
     * @param array<string, mixed> $userInfoClaims
     *
     * @return array<string, mixed>
     */
    private function mergeClaims(array $idTokenClaims, array $userInfoClaims): array
    {
        $idTokenSub = $idTokenClaims['sub'] ?? null;

        if ($idTokenClaims !== [] && $userInfoClaims !== [] && ($userInfoClaims['sub'] ?? null) !== $idTokenSub) {
            throw new InvalidStateException('The userinfo response describes a different subject than the id_token.');
        }

        $merged = array_merge($idTokenClaims, $userInfoClaims);

        foreach (['sub', 'email', 'email_verified'] as $claim) {
            if (\array_key_exists($claim, $idTokenClaims)) {
                $merged[$claim] = $idTokenClaims[$claim];
            }
        }

        return $merged;
    }
}
