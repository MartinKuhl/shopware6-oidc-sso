<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Health;

use MartinKuhl\Sw6Oidc\Service\Health\WebhookNotifier;
use MartinKuhl\Sw6Oidc\Service\Security\SsrfUrlValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(WebhookNotifier::class)]
final class WebhookNotifierTest extends TestCase
{
    public function testPostsJsonAndReportsDelivery(): void
    {
        $sent = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = [$method, $url, $options['body'] ?? null];

            return new MockResponse('ok');
        });

        self::assertTrue($this->notifier($client)->send('https://hooks.example/abc', ['text' => 'down']));
        self::assertSame(['POST', 'https://hooks.example/abc', '{"text":"down"}'], $sent);
    }

    public function testErrorStatusAndBlockedUrlAreNotDelivered(): void
    {
        self::assertFalse($this->notifier(new MockHttpClient(new MockResponse('', ['http_code' => 500])))->send('https://hooks.example/abc', []));

        $client = new MockHttpClient(new MockResponse('ok'));
        self::assertFalse((new WebhookNotifier($client, new SsrfUrlValidator(false, static fn (): array => ['127.0.0.1']), new NullLogger()))->send('https://hooks.example/abc', []));
        self::assertSame(0, $client->getRequestsCount());
    }

    private function notifier(MockHttpClient $client): WebhookNotifier
    {
        return new WebhookNotifier($client, new SsrfUrlValidator(false, static fn (): array => ['93.184.215.14']), new NullLogger());
    }
}
