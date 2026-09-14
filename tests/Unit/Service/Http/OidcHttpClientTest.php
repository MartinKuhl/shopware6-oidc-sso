<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Http;

use MartinKuhl\Sw6Oidc\Service\Http\OidcHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Locks in the client-authentication behavior that fixes token-exchange
 * failures against IdPs (Authelia, Keycloak, ...) whose default
 * `token_endpoint_auth_method` is `client_secret_basic` — see
 * TokenExchangeService for the confidential-client-vs-public-client rules.
 */
#[CoversClass(OidcHttpClient::class)]
final class OidcHttpClientTest extends TestCase
{
    public function testPostFormSendsNoAuthorizationHeaderWithoutBasicAuthCredentials(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('{}');
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('POST', 'https://idp.example/token', self::callback(
                static fn (array $options): bool => !isset($options['headers']['Authorization']),
            ))
            ->willReturn($response);

        $client = new OidcHttpClient($httpClient, $this->createMock(LoggerInterface::class));

        $client->postForm('https://idp.example/token', ['client_id' => 'public-client'], 10);
    }

    public function testPostFormSendsABasicAuthorizationHeaderWithCredentials(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('{}');
        $response->method('getStatusCode')->willReturn(200);

        $expectedHeader = 'Basic ' . base64_encode('my-client:my-secret');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('POST', 'https://idp.example/token', self::callback(
                static fn (array $options): bool => ($options['headers']['Authorization'] ?? null) === $expectedHeader,
            ))
            ->willReturn($response);

        $client = new OidcHttpClient($httpClient, $this->createMock(LoggerInterface::class));

        $client->postForm('https://idp.example/token', [], 10, 'my-client', 'my-secret');
    }
}
