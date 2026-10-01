<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Logging;

use MartinKuhl\Sw6Oidc\Service\Logging\SensitiveDataProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SensitiveDataProcessor::class)]
final class SensitiveDataProcessorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveKeys(): iterable
    {
        foreach (['client_secret', 'clientSecret', 'idToken', 'id_token', 'logout_token', 'refreshToken', 'code_verifier', 'state', 'nonce', 'sw6oidc_nonce', 'secret', 'Authorization', 'healthAlertWebhookUrl', 'webhook_url'] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('sensitiveKeys')]
    public function testSensitiveKeyVariantsAreMasked(string $key): void
    {
        $context = $this->process('m', [$key => 'value', 'nested' => [$key => 'value']])->context;

        self::assertSame('***MASKED***', $context[$key]);
        self::assertSame('***MASKED***', $context['nested'][$key]);
    }

    public function testOrdinaryKeysAndEmptyValuesAreKept(): void
    {
        $context = $this->process('m', ['providerId' => 'p1', 'state' => ''])->context;

        self::assertSame(['providerId' => 'p1', 'state' => ''], $context);
    }

    public function testQueryParametersAreScrubbedInMessagesAndStrings(): void
    {
        $record = $this->process(
            'Redirecting to https://shop.example/callback?code=abc123&state=xyz&foo=bar',
            ['url' => 'https://idp.example/logout?id_token_hint=eyJ.hint.sig&post_logout_redirect_uri=https://shop.example/'],
        );

        self::assertStringNotContainsString('abc123', $record->message);
        self::assertStringNotContainsString('xyz', $record->message);
        self::assertStringContainsString('foo=bar', $record->message);
        self::assertStringNotContainsString('eyJ.hint.sig', $record->context['url']);
        self::assertStringContainsString('post_logout_redirect_uri=', $record->context['url']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function process(string $message, array $context): LogRecord
    {
        return (new SensitiveDataProcessor())(new LogRecord(new \DateTimeImmutable(), 'sw6oidc', Level::Warning, $message, $context));
    }
}
