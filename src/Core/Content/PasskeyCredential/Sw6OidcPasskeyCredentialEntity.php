<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\PasskeyCredential;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class Sw6OidcPasskeyCredentialEntity extends Entity
{
    use EntityIdTrait;

    public const USER_TYPE_ADMIN = 'admin';
    public const USER_TYPE_CUSTOMER = 'customer';

    /** 'admin'|'customer' — same polymorphic pattern as Sw6OidcUserProviderEntity */
    protected string $userType;
    protected string $userId;
    /** Base64url WebAuthn credential id */
    protected string $credentialId;
    /** Serialized web-auth/webauthn-lib PublicKeyCredentialSource (JSON) */
    protected string $publicKey;
    protected int $signCount = 0;
    protected string $userHandle;
    protected ?string $nickname = null;

    public function getUserType(): string
    {
        return $this->userType;
    }

    public function setUserType(string $userType): void
    {
        $this->userType = $userType;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function setUserId(string $userId): void
    {
        $this->userId = $userId;
    }

    public function getCredentialId(): string
    {
        return $this->credentialId;
    }

    public function setCredentialId(string $credentialId): void
    {
        $this->credentialId = $credentialId;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function setPublicKey(string $publicKey): void
    {
        $this->publicKey = $publicKey;
    }

    public function getSignCount(): int
    {
        return $this->signCount;
    }

    public function setSignCount(int $signCount): void
    {
        $this->signCount = $signCount;
    }

    public function getUserHandle(): string
    {
        return $this->userHandle;
    }

    public function setUserHandle(string $userHandle): void
    {
        $this->userHandle = $userHandle;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function setNickname(?string $nickname): void
    {
        $this->nickname = $nickname;
    }
}
