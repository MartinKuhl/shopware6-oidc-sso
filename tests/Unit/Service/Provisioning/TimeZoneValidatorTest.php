<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Service\Provisioning;

use MartinKuhl\Sw6Oidc\Service\Provisioning\TimeZoneValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimeZoneValidator::class)]
final class TimeZoneValidatorTest extends TestCase
{
    public function testAcceptsAKnownIanaIdentifier(): void
    {
        self::assertTrue((new TimeZoneValidator())->isValid('Europe/Berlin'));
    }

    public function testRejectsAnUnknownIdentifier(): void
    {
        self::assertFalse((new TimeZoneValidator())->isValid('Not/AZone'));
    }

    public function testRejectsAnAbbreviationLikeCest(): void
    {
        self::assertFalse((new TimeZoneValidator())->isValid('CEST'));
    }

    public function testRejectsNull(): void
    {
        self::assertFalse((new TimeZoneValidator())->isValid(null));
    }

    public function testRejectsAnEmptyString(): void
    {
        self::assertFalse((new TimeZoneValidator())->isValid(''));
    }
}
