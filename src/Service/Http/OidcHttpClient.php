<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Http;

use MartinKuhl\Sw6Oidc\Service\Http\Exception\OidcHttpException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin wrapper around Symfony's HttpClientInterface for the token/userinfo/
 * discovery/webhook calls the OIDC flow needs — replaces the Magento module's
 * Helper/Curl.php. One retry on an empty/network-error response, matching the
 * Magento module's single 500ms-later retry for token/JWKS calls.
 */
class OidcHttpClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $formParams
     *
     * @return array<string, mixed>
     */
    public function postForm(
        string $url,
        array $formParams,
        int $timeoutSeconds,
        ?string $basicAuthUsername = null,
        ?string $basicAuthPassword = null,
    ): array {
        $headers = ['Accept' => 'application/json'];

        if ($basicAuthUsername !== null) {
            $headers['Authorization'] = 'Basic ' . base64_encode($basicAuthUsername . ':' . ($basicAuthPassword ?? ''));
        }

        return $this->requestJson('POST', $url, [
            'body' => $formParams,
            'timeout' => $timeoutSeconds,
            'headers' => $headers,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getWithBearerToken(string $url, string $bearerToken, int $timeoutSeconds): array
    {
        return $this->requestJson('GET', $url, [
            'timeout' => $timeoutSeconds,
            'headers' => [
                'Authorization' => 'Bearer ' . $bearerToken,
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getJson(string $url, int $timeoutSeconds): array
    {
        return $this->requestJson('GET', $url, [
            'timeout' => $timeoutSeconds,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, array $options): array
    {
        // Only ever logs field *names*, never values - a userinfo response in
        // particular is arbitrary IdP-supplied PII (email, address, phone,
        // birthdate, ...) with no fixed set of key names to denylist the way
        // SensitiveDataProcessor does for known credential fields
        // (client_secret/code/code_verifier/*_token). $options['headers'] is
        // deliberately never logged at all - that's where a Basic-auth
        // Authorization header lives for a confidential client.
        $this->logger->debug('sw6oidc: sending IdP HTTP request.', [
            'method' => $method,
            'url' => $url,
            'formParamKeys' => array_keys($options['body'] ?? []),
        ]);

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $content = $response->getContent(false);
            $statusCode = $response->getStatusCode();
        } catch (HttpClientExceptionInterface $exception) {
            $this->logger->warning('sw6oidc: retrying HTTP request after transport error.', [
                'url' => $url,
                'exception' => $exception->getMessage(),
            ]);

            [$content, $statusCode] = $this->retryOnce($method, $url, $options);
        }

        $decoded = json_decode($content, true);

        $this->logger->log($statusCode >= 400 ? 'warning' : 'debug', 'sw6oidc: received IdP HTTP response.', [
            'method' => $method,
            'url' => $url,
            'statusCode' => $statusCode,
            'bodyKeys' => \is_array($decoded) ? array_keys($decoded) : null,
        ]);

        if ($statusCode >= 400) {
            throw new OidcHttpException(sprintf('OIDC HTTP request to "%s" failed with status %d.', $url, $statusCode));
        }

        if (!\is_array($decoded)) {
            throw new OidcHttpException(sprintf('OIDC HTTP response from "%s" was not valid JSON.', $url));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{0: string, 1: int}
     */
    private function retryOnce(string $method, string $url, array $options): array
    {
        usleep(500_000);

        try {
            $response = $this->httpClient->request($method, $url, $options);

            return [$response->getContent(false), $response->getStatusCode()];
        } catch (HttpClientExceptionInterface $exception) {
            throw new OidcHttpException(sprintf('OIDC HTTP request to "%s" failed: %s', $url, $exception->getMessage()), 0, $exception);
        }
    }
}
