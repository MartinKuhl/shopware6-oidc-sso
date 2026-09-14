<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning\Exception;

class AdminProvisioningDeniedException extends \RuntimeException
{
    public const REASON_AUTO_CREATE_DISABLED = 'auto_create_disabled';
    public const REASON_NO_ROLE = 'no_role';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function autoCreateDisabled(string $email): self
    {
        return new self(sprintf(
            'No admin account exists for "%s" and auto-creation is disabled for this provider.',
            $email,
        ), self::REASON_AUTO_CREATE_DISABLED);
    }

    public static function noRoleResolved(): self
    {
        return new self(
            "No admin role mapping (or default role) matched this user's OIDC groups; refusing to create an admin without a role.",
            self::REASON_NO_ROLE,
        );
    }
}
