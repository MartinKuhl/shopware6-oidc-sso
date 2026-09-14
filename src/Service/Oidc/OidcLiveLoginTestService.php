<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Jwt\JwtVerifier;

/**
 * Runs the safe subset of OidcCallbackProcessor::process()'s pipeline for the
 * "Run live login test" admin feature: exchange the code for tokens, verify
 * the id_token, fetch userinfo, merge + flatten claims — using the exact same
 * building blocks the real login callback uses, so the test is a faithful
 * end-to-end check of the provider configuration.
 *
 * Deliberately stops *before* AttributeMapper::map() and never calls
 * AdminProvisioningService/CustomerProvisioningService, AdminLoginNonceService,
 * or the League AuthorizationServer — this is what keeps "a test never creates
 * a real account or mints a real token" a structural guarantee rather than a
 * conventionally-assumed one. Each stage is caught independently so a partial
 * failure (e.g. token exchange worked but JWT verification failed) is visible
 * in the report instead of surfacing as a single all-or-nothing exception.
 */
class OidcLiveLoginTestService
{
    public function __construct(
        private readonly TokenExchangeService $tokenExchangeService,
        private readonly UserInfoService $userInfoService,
        private readonly JwtVerifier $jwtVerifier,
        private readonly ClaimsNormalizer $claimsNormalizer,
    ) {
    }

    /**
     * @return array{status: string, steps: array<int, array{id: string, status: string, detail: string}>, claims: array<string, mixed>}
     */
    public function run(
        Sw6OidcProviderEntity $provider,
        string $code,
        string $codeVerifier,
        string $redirectUri,
        string $expectedNonce,
    ): array {
        $steps = [];

        try {
            $tokens = $this->tokenExchangeService->exchangeCodeForTokens($provider, $code, $redirectUri, $codeVerifier);
        } catch (\Throwable $exception) {
            $steps[] = ['id' => 'token_exchange', 'status' => 'fail', 'detail' => $exception->getMessage()];

            return ['status' => 'fail', 'steps' => $steps, 'claims' => []];
        }

        if (!isset($tokens['access_token']) || !\is_string($tokens['access_token'])) {
            $steps[] = ['id' => 'token_exchange', 'status' => 'fail', 'detail' => 'Token endpoint response did not include an access_token.'];

            return ['status' => 'fail', 'steps' => $steps, 'claims' => []];
        }

        $steps[] = ['id' => 'token_exchange', 'status' => 'pass', 'detail' => 'Authorization code exchanged for an access token successfully.'];

        $idTokenClaims = [];

        if (isset($tokens['id_token']) && \is_string($tokens['id_token'])) {
            try {
                $idTokenClaims = $this->jwtVerifier->verify(
                    $tokens['id_token'],
                    (string) $provider->getJwksEndpoint(),
                    (string) $provider->getIssuer(),
                    $provider->getClientId(),
                    $expectedNonce,
                    $provider->getJwksCacheTtl(),
                    $provider->getHttpTimeout(),
                );
                $steps[] = ['id' => 'id_token_verification', 'status' => 'pass', 'detail' => 'id_token signature and claims verified successfully.'];
            } catch (\Throwable $exception) {
                $steps[] = ['id' => 'id_token_verification', 'status' => 'fail', 'detail' => $exception->getMessage()];
            }
        } else {
            $steps[] = ['id' => 'id_token_verification', 'status' => 'skipped', 'detail' => 'Token response did not include an id_token.'];
        }

        $userInfoClaims = [];

        if ($provider->getUserInfoEndpoint() !== null) {
            try {
                $userInfoClaims = $this->userInfoService->fetchClaims($provider, $tokens['access_token']);
                $steps[] = ['id' => 'userinfo_fetch', 'status' => 'pass', 'detail' => 'Userinfo endpoint responded successfully.'];
            } catch (\Throwable $exception) {
                $steps[] = ['id' => 'userinfo_fetch', 'status' => 'fail', 'detail' => $exception->getMessage()];
            }
        } else {
            $steps[] = ['id' => 'userinfo_fetch', 'status' => 'skipped', 'detail' => 'No userinfo endpoint configured.'];
        }

        $merged = array_merge($idTokenClaims, $userInfoClaims);
        $claims = $this->claimsNormalizer->flatten($merged, $provider->getClaimEncoding());

        $statuses = array_column($steps, 'status');
        $status = \in_array('fail', $statuses, true) ? 'fail' : 'pass';

        return ['status' => $status, 'steps' => $steps, 'claims' => $claims];
    }
}
