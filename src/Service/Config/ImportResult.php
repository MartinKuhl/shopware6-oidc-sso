<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Config;

/**
 * Per-provider outcome of OidcConfigTransfer::import(), keyed/listed by the
 * provider's appName (or id).
 */
final class ImportResult
{
    /** @var list<string> */
    public array $created = [];

    /** @var list<string> */
    public array $updated = [];

    /** @var list<string> existing providers left alone (no --overwrite) */
    public array $skipped = [];

    /** @var array<string, string> label => error */
    public array $failed = [];

    /** @var array<string, list<string>> label => warnings */
    public array $warnings = [];
}
