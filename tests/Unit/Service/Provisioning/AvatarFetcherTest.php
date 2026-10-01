<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Provisioning;

use MartinKuhl\Sw6Oidc\Service\Provisioning\AvatarFetcher;
use MartinKuhl\Sw6Oidc\Service\Security\SsrfUrlValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(AvatarFetcher::class)]
final class AvatarFetcherTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = (string) tempnam(sys_get_temp_dir(), 'sw6oidc_avatar_test_');
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testDownloadsAnImage(): void
    {
        $file = $this->fetcher(new MockResponse('PNGDATA', ['response_headers' => ['content-type' => 'image/png; charset=binary']]))
            ->fetch('https://idp.example/a.png', $this->tempFile);

        self::assertSame('image/png', $file->getMimeType());
        self::assertSame('png', $file->getFileExtension());
        self::assertSame(7, $file->getFileSize());
        self::assertSame('PNGDATA', file_get_contents($this->tempFile));
    }

    public function testRejectsNonImages(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('content type');

        $this->fetcher(new MockResponse('<svg/>', ['response_headers' => ['content-type' => 'image/svg+xml']]))
            ->fetch('https://idp.example/a.svg', $this->tempFile);
    }

    public function testRejectsOversizedImages(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('size limit');

        $this->fetcher(new MockResponse(str_repeat('x', AvatarFetcher::MAX_BYTES + 1), ['response_headers' => ['content-type' => 'image/jpeg']]))
            ->fetch('https://idp.example/a.jpg', $this->tempFile);
    }

    public function testRejectsHttpErrors(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->fetcher(new MockResponse('', ['http_code' => 404]))->fetch('https://idp.example/a.png', $this->tempFile);
    }

    public function testRejectsBlockedUrlsWithoutARequest(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('must not be called'));
        $fetcher = new AvatarFetcher($client, new SsrfUrlValidator(false, static fn (): array => ['10.0.0.1']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not allowed');

        $fetcher->fetch('https://intranet.example/a.png', $this->tempFile);
    }

    private function fetcher(MockResponse $response): AvatarFetcher
    {
        return new AvatarFetcher(new MockHttpClient($response), new SsrfUrlValidator(false, static fn (): array => ['93.184.216.34']));
    }
}
