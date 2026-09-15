<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use MartinKuhl\Sw6Oidc\Service\Provisioning\AttributeMapper;
use MartinKuhl\Sw6Oidc\Service\Security\Exception\InvalidStateException;
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
    ) {
    }

    /**
     * @throws InvalidStateException
     * @throws \MartinKuhl\Sw6Oidc\Service\Jwt\Exception\InvalidJwtException
     * @throws \MartinKuhl\Sw6Oidc\Service\Provisioning\Exception\MissingEmailClaimException
     * @throws \MartinKuhl\Sw6Oidc\Service\Provider\Exception\ProviderNotFoundException
     */
    public function process(?string $code, ?string $state, string $redirectUri, Context $context): OidcCallbackResult
    {
        // Temporary diagnostic aid: confirms the callback route was actually
        // reached at all - if a login attempt produces no log lines
        // whatsoever, this one is missing too, which points at the IdP
        // never redirecting back here (wrong redirect_uri, rejected client,
        // etc.) rather than anything failing inside this plugin.
        $this->logger->debug('sw6oidc: OIDC callback received.', [
            'hasCode' => $code !== null && $code !== '',
            'hasState' => $state !== null && $state !== '',
            'redirectUri' => $redirectUri,
        ]);

        $flow = $this->securityHelper->consumeAuthorizationFlow($state);

        $this->logger->debug('sw6oidc: authorization flow state consumed.', [
            'providerId' => $flow->providerId,
            'loginType' => $flow->loginType,
        ]);

        if ($code === null || $code === '') {
            throw new InvalidStateException('Callback is missing the "code" parameter.');
        }

        $provider = $this->providerResolver->getActiveById($flow->providerId, $context);

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
        } else {
            $this->logger->warning('sw6oidc: token response did not include an id_token; relying on userinfo alone.', [
                'providerId' => $provider->getId(),
            ]);
        }

        $this->logger->debug('sw6oidc: id_token verified (or skipped, see above).', [
            'providerId' => $provider->getId(),
            'idTokenClaimKeys' => array_keys($idTokenClaims),
        ]);

        $userInfoClaims = $this->userInfoService->fetchClaims($provider, $tokens['access_token']);
        $mergedClaims = array_merge($idTokenClaims, $userInfoClaims);

        $this->logger->debug('sw6oidc: userinfo fetched and merged with id_token claims.', [
            'providerId' => $provider->getId(),
            'userInfoClaimKeys' => array_keys($userInfoClaims),
            'mergedClaimKeys' => array_keys($mergedClaims),
        ]);

        // Extracted from the *unflattened* claims: flattening a Zitadel-style
        // nested role object would turn its group-name keys into dotted leaf
        // paths and lose them (see ClaimsNormalizer::normalizeGroups()).
        $rawGroupsClaim = $mergedClaims[$provider->getGroupAttribute()] ?? null;
        $groups = $this->claimsNormalizer->normalizeGroups($rawGroupsClaim);

        $flattenedClaims = $this->claimsNormalizer->flatten($mergedClaims, $provider->getClaimEncoding());
        $profile = $this->attributeMapper->map($provider, $flattenedClaims, $groups, $context);

        $this->logger->debug('sw6oidc: claims mapped to profile.', [
            'providerId' => $provider->getId(),
            'email' => $profile->email,
            'groups' => $groups,
        ]);

        return new OidcCallbackResult($provider, $flow, $profile, $tokens);
    }
}
