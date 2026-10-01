<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Subscriber;

use MartinKuhl\Sw6Oidc\Core\Content\AttributeMapping\Sw6OidcAttributeMappingDefinition;
use MartinKuhl\Sw6Oidc\Subscriber\AttributeMappingWriteGuardSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;

#[CoversClass(AttributeMappingWriteGuardSubscriber::class)]
final class AttributeMappingWriteGuardSubscriberTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function payloads(): iterable
    {
        yield 'valid pattern' => [['transform_function' => 'regex_replace', 'transform_params' => '{"pattern":"/^(.*)@corp\\\\.example$/","replacement":"$1"}'], 0];
        yield 'missing delimiters' => [['transform_function' => 'regex_replace', 'transform_params' => '{"pattern":"^(.*)$"}'], 1];
        yield 'unbalanced group' => [['transform_function' => 'regex_replace', 'transform_params' => '{"pattern":"/(abc/"}'], 1];
        yield 'empty pattern' => [['transform_function' => 'regex_replace', 'transform_params' => '{"pattern":""}'], 1];
        yield 'other function' => [['transform_function' => 'prefix', 'transform_params' => '{"value":"x"}'], 0];
        yield 'params not written' => [['transform_function' => 'regex_replace'], 0];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('payloads')]
    public function testPatternsAreValidatedOnSave(array $payload, int $expectedViolations): void
    {
        $command = $this->createMock(UpdateCommand::class);
        $command->method('getEntityName')->willReturn(Sw6OidcAttributeMappingDefinition::ENTITY_NAME);
        $command->method('getPayload')->willReturn($payload);
        $command->method('getPath')->willReturn('/0');

        $event = new PreWriteValidationEvent(WriteContext::createFromContext(Context::createDefaultContext()), [$command]);
        (new AttributeMappingWriteGuardSubscriber())->validate($event);

        self::assertCount($expectedViolations, $event->getExceptions()->getExceptions());
    }
}
