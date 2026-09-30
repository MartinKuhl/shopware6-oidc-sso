<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Controller;

use MartinKuhl\Sw6Oidc\Controller\HealthCheckController;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderCollection;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderDefinition;
use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Health\ProviderConfigInspector;
use MartinKuhl\Sw6Oidc\Service\Security\Sw6OidcEncryptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(HealthCheckController::class)]
final class HealthCheckControllerTest extends TestCase
{
    public function testHasNoWayToMakeOutboundCalls(): void
    {
        foreach ((new \ReflectionClass(HealthCheckController::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = (string) $parameter->getType();
            self::assertNotSame(HttpClientInterface::class, $type);
            self::assertStringNotContainsString('Reachability', $type);
        }
    }

    public function testOkWhenAllActiveProvidersAreCompleteAndReachable(): void
    {
        [$status, $body] = $this->health([$this->provider([]), $this->provider(['healthAlertLastStatus' => 'ok'])]);

        self::assertSame(200, $status);
        self::assertSame(['status' => 'ok', 'activeProviders' => 2, 'incompleteProviders' => 0, 'unreachableProviders' => 0], $body);
    }

    public function testDegradedWhenIncompleteOrLastProbeFailed(): void
    {
        [$status, $body] = $this->health([$this->provider(['jwksEndpoint' => null]), $this->provider(['healthAlertLastStatus' => 'fail'])]);

        self::assertSame(503, $status);
        self::assertSame(['status' => 'degraded', 'activeProviders' => 2, 'incompleteProviders' => 1, 'unreachableProviders' => 1], $body);
        self::assertStringNotContainsString('idp.example', (string) json_encode($body), 'no URLs or names leak');
    }

    public function testUnconfiguredWithoutActiveProviders(): void
    {
        self::assertSame([200, ['status' => 'unconfigured', 'activeProviders' => 0, 'incompleteProviders' => 0, 'unreachableProviders' => 0]], $this->health([]));
    }

    /**
     * @param list<Sw6OidcProviderEntity> $providers
     *
     * @return array{int, array<string, mixed>}
     */
    private function health(array $providers): array
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('search')->willReturnCallback(static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
            Sw6OidcProviderDefinition::ENTITY_NAME,
            \count($providers),
            new Sw6OidcProviderCollection($providers),
            null,
            $criteria,
            $context,
        ));

        $response = (new HealthCheckController($repository, new ProviderConfigInspector(new Sw6OidcEncryptor('app-secret'))))->health();

        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function provider(array $overrides): Sw6OidcProviderEntity
    {
        static $n = 0;
        $provider = new Sw6OidcProviderEntity();
        $provider->assign(array_merge([
            'id' => 'p' . ++$n,
            'authorizeEndpoint' => 'https://idp.example/authorize',
            'accessTokenEndpoint' => 'https://idp.example/token',
            'jwksEndpoint' => 'https://idp.example/jwks',
            'issuer' => 'https://idp.example',
            'clientId' => 'shop',
            'clientSecret' => 'secret',
        ], $overrides));

        return $provider;
    }
}
