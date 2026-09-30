<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Oidc;

use MartinKuhl\Sw6Oidc\Service\Oidc\TestResultTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TestResultTranslatorTest extends TestCase
{
    private TestResultTranslator $translator;

    protected function setUp(): void
    {
        // Real snippet files: the point of this translator is sharing them with the Administration.
        $this->translator = new TestResultTranslator();
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'exact' => ['de-DE', 'de-DE'];
        yield 'case-insensitive' => ['de-de', 'de-DE'];
        yield 'underscore (Accept-Language style)' => ['de_DE', 'de-DE'];
        yield 'language only' => ['de', 'de-DE'];
        yield 'other region of known language' => ['de-AT', 'de-DE'];
        yield 'english' => ['en-US', 'en-GB'];
        yield 'unknown language' => ['fr-FR', 'en-GB'];
        yield 'empty' => ['', 'en-GB'];
        yield 'null' => [null, 'en-GB'];
    }

    #[DataProvider('localeProvider')]
    public function testNormalizeLocale(?string $input, string $expected): void
    {
        static::assertSame($expected, $this->translator->normalizeLocale($input));
    }

    public function testTranslatesStatusInBothLanguages(): void
    {
        static::assertSame('Erfolgreich', $this->translator->trans('sw6oidc.provider.detail.testStatus.pass', 'de-DE'));
        static::assertSame('Passed', $this->translator->trans('sw6oidc.provider.detail.testStatus.pass', 'en-GB'));
    }

    public function testReplacesPlaceholders(): void
    {
        static::assertSame(
            'Der JWKS-Endpunkt hat 3 Schlüssel geliefert.',
            $this->translator->trans('sw6oidc.provider.detail.testMessage.jwksPass', 'de-DE', ['count' => 3]),
        );
    }

    public function testFallsBackToEnglishForMissingLocaleFile(): void
    {
        static::assertSame('Close window', $this->translator->trans('sw6oidc.provider.detail.liveTestPopup.closeButton', 'xx-XX'));
    }

    public function testReturnsKeyWhenUnknown(): void
    {
        static::assertSame('sw6oidc.does.not.exist', $this->translator->trans('sw6oidc.does.not.exist', 'de-DE'));
    }

    public function testEveryPopupKeyExistsInEveryLocale(): void
    {
        $keys = [
            'liveTestResultTitle',
            'liveTestPopup.overallLabel',
            'liveTestPopup.claimsTitle',
            'liveTestPopup.columnClaim',
            'liveTestPopup.columnValue',
            'liveTestPopup.noClaims',
            'liveTestPopup.closeButton',
            'liveTest.step.callback',
            'liveTest.step.authorization',
            'testMessage.callbackInvalidState',
            'testMessage.callbackNotTestFlow',
            'testMessage.callbackMissingCode',
            'testMessage.authorizationError',
        ];

        foreach (['de-DE', 'en-GB'] as $locale) {
            foreach ($keys as $key) {
                $fullKey = 'sw6oidc.provider.detail.' . $key;
                static::assertNotSame($fullKey, $this->translator->trans($fullKey, $locale), sprintf('%s missing in %s', $key, $locale));
            }
        }
    }
}
