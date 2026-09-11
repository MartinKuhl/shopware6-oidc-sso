<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Passkey;

use Cose\Algorithm\Manager as CoseAlgorithmManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\ECDSA\ES512;
use Cose\Algorithm\Signature\RSA\RS256;
use Cose\Algorithm\Signature\RSA\RS512;
use Webauthn\AttestationStatement\AttestationObjectLoader;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticationExtensions\ExtensionOutputCheckerHandler;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialLoader;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\TokenBinding\TokenBindingNotSupportedHandler;

/**
 * The single seam constructing every web-auth/webauthn-lib object this plugin
 * needs — mirrors the Magento module's Model/Passkey/WebauthnCeremonyFactory.php.
 * Attestation conveyance is always 'none' (same deliberate trade-off as the
 * Magento module: broad authenticator compatibility over hardware provenance).
 *
 * web-auth/webauthn-lib is pinned to ^4.7 in composer.json deliberately: 5.x
 * removed the PublicKeyCredentialSourceRepository-based validator
 * constructors and the repository interface itself entirely (replaced by a
 * CredentialRecord-based design with a different check() signature), which
 * PasskeyCredentialRepository and the constructors below depend on.
 */
class WebauthnCeremonyFactory
{
    public function __construct(private readonly PasskeyCredentialRepository $credentialRepository)
    {
    }

    public function rpEntity(string $rpId, string $rpName): PublicKeyCredentialRpEntity
    {
        return new PublicKeyCredentialRpEntity($rpName, $rpId);
    }

    /**
     * @return PublicKeyCredentialParameters[]
     */
    public function credentialParameters(): array
    {
        return [
            new PublicKeyCredentialParameters('public-key', ES256::ID),
            new PublicKeyCredentialParameters('public-key', RS256::ID),
        ];
    }

    public function residentKeyAuthenticatorSelection(): AuthenticatorSelectionCriteria
    {
        return new AuthenticatorSelectionCriteria(
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
        );
    }

    private function attestationStatementSupportManager(): AttestationStatementSupportManager
    {
        $manager = new AttestationStatementSupportManager();
        $manager->add(new NoneAttestationStatementSupport());

        return $manager;
    }

    public function credentialLoader(): PublicKeyCredentialLoader
    {
        return PublicKeyCredentialLoader::create(
            AttestationObjectLoader::create($this->attestationStatementSupportManager()),
        );
    }

    private function coseAlgorithmManager(): CoseAlgorithmManager
    {
        $manager = new CoseAlgorithmManager();
        $manager->add(new ES256());
        $manager->add(new ES512());
        $manager->add(new RS256());
        $manager->add(new RS512());

        return $manager;
    }

    public function attestationResponseValidator(): AuthenticatorAttestationResponseValidator
    {
        return new AuthenticatorAttestationResponseValidator(
            $this->attestationStatementSupportManager(),
            $this->credentialRepository,
            new TokenBindingNotSupportedHandler(),
            new ExtensionOutputCheckerHandler(),
        );
    }

    public function assertionResponseValidator(): AuthenticatorAssertionResponseValidator
    {
        return new AuthenticatorAssertionResponseValidator(
            $this->credentialRepository,
            new TokenBindingNotSupportedHandler(),
            new ExtensionOutputCheckerHandler(),
            $this->coseAlgorithmManager(),
        );
    }
}
