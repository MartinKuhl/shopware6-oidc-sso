<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Subscriber;

use MartinKuhl\Sw6Oidc\Core\Content\AttributeMapping\Sw6OidcAttributeMappingDefinition;
use MartinKuhl\Sw6Oidc\Service\Provisioning\AttributeTransformer;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * A regex_replace transform with an invalid pattern used to fail silently at
 * every login (the transformer passes the value through, or — for identity
 * attributes — fails the login). Reject it when it is saved (F-N16).
 */
class AttributeMappingWriteGuardSubscriber implements EventSubscriberInterface
{
    public const CODE_INVALID_PATTERN = 'SW6OIDC_TRANSFORM_PATTERN_INVALID';

    public static function getSubscribedEvents(): array
    {
        return [PreWriteValidationEvent::class => 'validate'];
    }

    public function validate(PreWriteValidationEvent $event): void
    {
        foreach ($event->getCommands() as $command) {
            if (
                !$command instanceof InsertCommand && !$command instanceof UpdateCommand
                || $command->getEntityName() !== Sw6OidcAttributeMappingDefinition::ENTITY_NAME
            ) {
                continue;
            }

            $payload = $command->getPayload();

            if (($payload['transform_function'] ?? null) !== AttributeTransformer::REGEX_REPLACE || !\array_key_exists('transform_params', $payload)) {
                continue;
            }

            $params = \is_string($payload['transform_params']) ? json_decode($payload['transform_params'], true) : $payload['transform_params'];
            $pattern = \is_array($params) ? ($params['pattern'] ?? null) : null;

            if (self::isValidPattern($pattern)) {
                continue;
            }

            $message = 'The regular expression is invalid (use delimiters, e.g. /^(.*)@example\.com$/).';
            $event->getExceptions()->add(new WriteConstraintViolationException(
                new ConstraintViolationList([new ConstraintViolation($message, $message, [], null, '/transformParams/pattern', $pattern, null, self::CODE_INVALID_PATTERN)]),
                $command->getPath(),
            ));
        }
    }

    public static function isValidPattern(mixed $pattern): bool
    {
        if (!\is_string($pattern) || $pattern === '' || \strlen($pattern) > AttributeTransformer::MAX_REGEX_BYTES) {
            return false;
        }

        // A compile error makes preg_match() return false (and warn).
        return @preg_match($pattern, '') !== false;
    }
}
