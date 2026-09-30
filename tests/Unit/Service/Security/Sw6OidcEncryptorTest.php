<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Security;

use MartinKuhl\Sw6Oidc\Service\Security\Sw6OidcEncryptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(Sw6OidcEncryptor::class)]
final class Sw6OidcEncryptorTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $encryptor = new Sw6OidcEncryptor('app-secret');
        $encrypted = $encryptor->encrypt('s3cr3t <with> "chars"');

        self::assertStringStartsWith(Sw6OidcEncryptor::PREFIX, $encrypted);
        self::assertStringNotContainsString('s3cr3t', $encrypted);
        self::assertSame('s3cr3t <with> "chars"', $encryptor->decrypt($encrypted));
        self::assertTrue($encryptor->canDecrypt($encrypted));
    }

    public function testEachEncryptionUsesAFreshNonce(): void
    {
        $encryptor = new Sw6OidcEncryptor('app-secret');

        self::assertNotSame($encryptor->encrypt('same'), $encryptor->encrypt('same'));
    }

    public function testLegacyPlaintextPassesThrough(): void
    {
        $encryptor = new Sw6OidcEncryptor('app-secret');

        self::assertSame('plain-legacy-secret', $encryptor->decrypt('plain-legacy-secret'));
        self::assertFalse($encryptor->isEncrypted('plain-legacy-secret'));
        self::assertFalse($encryptor->canDecrypt('plain-legacy-secret'));
    }

    public function testWrongKeyPassesThroughAndLogs(): void
    {
        $encrypted = (new Sw6OidcEncryptor('old-app-secret'))->encrypt('secret');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $encryptor = new Sw6OidcEncryptor('new-app-secret', $logger);

        self::assertSame($encrypted, $encryptor->decrypt($encrypted));
        self::assertFalse($encryptor->canDecrypt($encrypted));
    }

    public function testCorruptEnvelopePassesThrough(): void
    {
        $encryptor = new Sw6OidcEncryptor('app-secret');

        foreach ([Sw6OidcEncryptor::PREFIX . '!!not-base64!!', Sw6OidcEncryptor::PREFIX . base64_encode('short')] as $corrupt) {
            self::assertSame($corrupt, $encryptor->decrypt($corrupt));
            self::assertFalse($encryptor->canDecrypt($corrupt));
        }
    }

    public function testEmptyAppSecretIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Sw6OidcEncryptor('');
    }
}
