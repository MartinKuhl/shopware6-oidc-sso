<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\Provider\Field;

use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;

/**
 * A StringField whose value is encrypted at rest (Sw6OidcEncryptor) —
 * transparent to entity code: writes take plaintext, reads return plaintext.
 * The max length applies to the plaintext; the storage column must be wide
 * enough for the encrypted envelope (~1.4x + 51 bytes).
 */
class Sw6OidcEncryptedField extends StringField
{
    protected function getSerializerClass(): string
    {
        return Sw6OidcEncryptedFieldSerializer::class;
    }
}
