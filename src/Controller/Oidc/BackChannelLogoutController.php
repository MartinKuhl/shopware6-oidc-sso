<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Controller\Oidc;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Core\Content\SessionActivity\Sw6OidcSessionActivityDefinition;
use MartinKuhl\Sw6Oidc\Service\Jwt\Exception\InvalidJwtException;
use MartinKuhl\Sw6Oidc\Service\Jwt\JwtVerifier;
use MartinKuhl\Sw6Oidc\Service\Provider\ProviderResolver;
use MartinKuhl\Sw6Oidc\Service\Security\Sw6OidcRateLimiter;
use MartinKuhl\Sw6Oidc\Service\Session\Sw6OidcIdpLogoutHandler;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * OIDC Back-Channel Logout 1.0 receiver: the IdP POSTs a signed logout token
 * server-to-server; every local session registered for its `sid` (or `sub`)
 * is ended. Unauthenticated by design — the token signature is the only
 * trust anchor.
 *
 * Responses follow §2.8: 200 (with `Cache-Control: no-store`) on success,
 * including "nothing to log out"; 400 for any invalid request; 429 once an
 * address has produced too many invalid requests (Sw6OidcRateLimiter).
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class BackChannelLogoutController extends AbstractController
{
    private const JTI_CACHE_PREFIX = 'sw6oidc_bcl_jti_';
    /** Upper bound for remembering a jti; a token older than this is expired anyway at any sane IdP. */
    private const MAX_JTI_TTL_SECONDS = 3600;

    public function __construct(
        private readonly JwtVerifier $jwtVerifier,
        private readonly ProviderResolver $providerResolver,
        private readonly Sw6OidcIdpLogoutHandler $logoutHandler,
        private readonly Sw6OidcRateLimiter $rateLimiter,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/sw6oidc/backchannel-logout',
        name: 'frontend.sw6oidc.backchannel-logout',
        defaults: ['_loginRequired' => false, 'csrf_protected' => false, '_httpCache' => false],
        methods: ['POST'],
    )]
    public function logout(Request $request): Response
    {
        $clientIp = $request->getClientIp();

        if ($this->rateLimiter->isBlocked(Sw6OidcRateLimiter::SCOPE_BACKCHANNEL_LOGOUT, $clientIp)) {
            return $this->respond(Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $logoutToken = $this->logoutToken($request);
            $provider = $this->resolveProvider($logoutToken);
            $claims = $this->jwtVerifier->verifyLogoutToken(
                $logoutToken,
                (string) $provider->getJwksEndpoint(),
                (string) $provider->getIssuer(),
                $provider->getClientId(),
                $provider->getJwksCacheTtl(),
                $provider->getHttpTimeout(),
            );
        } catch (InvalidJwtException $exception) {
            $this->rateLimiter->recordFailure(Sw6OidcRateLimiter::SCOPE_BACKCHANNEL_LOGOUT, $clientIp);
            $this->logger->warning('sw6oidc: back-channel logout rejected.', ['reason' => $exception->getMessage()]);

            return $this->respond(Response::HTTP_BAD_REQUEST, $exception->getMessage());
        }

        if ($this->isReplay($claims)) {
            $this->logger->info('sw6oidc: back-channel logout token replayed, ignoring.', ['providerId' => $provider->getId()]);

            return $this->respond(Response::HTTP_OK);
        }

        $this->logoutHandler->logout(
            $provider->getId(),
            \is_string($claims['sub'] ?? null) ? $claims['sub'] : null,
            \is_string($claims['sid'] ?? null) ? $claims['sid'] : null,
            Sw6OidcSessionActivityDefinition::LOGOUT_REASON_BACKCHANNEL,
        );

        return $this->respond(Response::HTTP_OK);
    }

    /**
     * @throws InvalidJwtException
     */
    private function logoutToken(Request $request): string
    {
        $logoutToken = $request->request->get('logout_token');

        if (!\is_string($logoutToken) || $logoutToken === '') {
            throw new InvalidJwtException('Missing "logout_token".');
        }

        return $logoutToken;
    }

    /**
     * The unverified `iss`/`aud` only pick which provider's keys verify the
     * token; several providers may share an issuer, so the audience decides.
     *
     * @throws InvalidJwtException
     */
    private function resolveProvider(string $logoutToken): Sw6OidcProviderEntity
    {
        $unverified = $this->jwtVerifier->decodeUnverified($logoutToken) ?? [];
        $issuer = \is_string($unverified['iss'] ?? null) ? $unverified['iss'] : '';
        $audiences = (array) ($unverified['aud'] ?? []);

        foreach ($this->providerResolver->findByIssuer($issuer, Context::createDefaultContext()) as $candidate) {
            if (\in_array($candidate->getClientId(), $audiences, true)) {
                return $candidate;
            }
        }

        throw new InvalidJwtException('No active provider matches the logout token issuer/audience.');
    }

    /**
     * OIDC Back-Channel Logout §2.6 step 7: a jti seen before is a replay.
     * Remembered until the token's own `exp` (capped).
     *
     * @param array<string, mixed> $claims
     */
    private function isReplay(array $claims): bool
    {
        $jti = $claims['jti'] ?? null;

        if (!\is_string($jti) || $jti === '') {
            return false;
        }

        $item = $this->cache->getItem(self::JTI_CACHE_PREFIX . hash('sha256', ($claims['iss'] ?? '') . "\0" . $jti));

        if ($item->isHit()) {
            return true;
        }

        $ttl = max(60, min(self::MAX_JTI_TTL_SECONDS, (int) ($claims['exp'] ?? 0) - time()));
        $item->set(true);
        $item->expiresAfter($ttl);
        $this->cache->save($item);

        return false;
    }

    private function respond(int $status, ?string $error = null): Response
    {
        $response = $error === null
            ? new Response('', $status)
            : new Response((string) json_encode(['error' => 'invalid_request', 'error_description' => $error]), $status, ['Content-Type' => 'application/json']);

        $response->headers->set('Cache-Control', 'no-cache, no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
