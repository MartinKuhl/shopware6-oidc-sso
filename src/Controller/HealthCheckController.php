<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Controller;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Health\HealthAlertState;
use MartinKuhl\Sw6Oidc\Service\Health\ProviderConfigInspector;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Unauthenticated health endpoint for uptime monitors. Configuration
 * completeness plus the last result of the scheduled reachability probe —
 * it makes **no outbound HTTP calls** itself, so it can't be used to make
 * the shop hammer the IdPs, and reveals only counts, never provider names,
 * ids or URLs.
 *
 * 200 `ok` when every active provider is complete and none failed its last
 * scheduled probe, else 503 `degraded`; 200 `unconfigured` without active
 * providers.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class HealthCheckController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $providerRepository,
        private readonly ProviderConfigInspector $configInspector,
    ) {
    }

    #[Route(
        path: '/sw6oidc/health',
        name: 'frontend.sw6oidc.health',
        defaults: ['_loginRequired' => false, '_httpCache' => false],
        methods: ['GET'],
    )]
    public function health(): JsonResponse
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('isActive', true));
        $active = 0;
        $incomplete = 0;
        $unreachable = 0;

        foreach ($this->providerRepository->search($criteria, Context::createDefaultContext())->getEntities() as $provider) {
            if (!$provider instanceof Sw6OidcProviderEntity) {
                continue;
            }

            ++$active;

            if ($this->configInspector->problems($provider) !== []) {
                ++$incomplete;
            }

            if ($provider->getHealthAlertLastStatus() === HealthAlertState::STATUS_FAIL) {
                ++$unreachable;
            }
        }

        $status = match (true) {
            $active === 0 => 'unconfigured',
            $incomplete > 0 || $unreachable > 0 => 'degraded',
            default => 'ok',
        };

        $response = new JsonResponse([
            'status' => $status,
            'activeProviders' => $active,
            'incompleteProviders' => $incomplete,
            'unreachableProviders' => $unreachable,
        ], $status === 'degraded' ? 503 : 200);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
