<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Oidc;

/**
 * Translates the server-rendered live-login-test popup from the plugin's own
 * Administration snippet files, so the popup and the provider detail page
 * share a single source of wording (status labels, step names, messages).
 *
 * The popup is an anonymous, non-SPA page, so vue-i18n isn't available; this
 * resolves the same dot-notation keys and `{param}` placeholders directly.
 */
class TestResultTranslator
{
    public const DEFAULT_LOCALE = 'en-GB';

    /** @var array<string, array<string, mixed>> */
    private array $loaded = [];

    public function __construct(
        private readonly string $snippetDirectory = __DIR__ . '/../../Resources/app/administration/src/snippet',
    ) {
    }

    /**
     * Maps an Administration or Accept-Language locale ("de-DE", "de_DE",
     * "de") onto an available snippet file, falling back to en-GB.
     */
    public function normalizeLocale(?string $locale): string
    {
        $locale = str_replace('_', '-', trim((string) $locale));

        if ($locale === '') {
            return self::DEFAULT_LOCALE;
        }

        $available = $this->availableLocales();

        foreach ($available as $candidate) {
            if (strcasecmp($candidate, $locale) === 0) {
                return $candidate;
            }
        }

        $language = strtolower(explode('-', $locale)[0]);

        foreach ($available as $candidate) {
            if (strtolower(explode('-', $candidate)[0]) === $language) {
                return $candidate;
            }
        }

        return self::DEFAULT_LOCALE;
    }

    /**
     * @param array<string, string|int> $params
     */
    public function trans(string $key, string $locale, array $params = []): string
    {
        $message = $this->lookup($key, $locale) ?? $this->lookup($key, self::DEFAULT_LOCALE) ?? $key;

        foreach ($params as $name => $value) {
            $message = str_replace('{' . $name . '}', (string) $value, $message);
        }

        return $message;
    }

    /**
     * @return list<string>
     */
    private function availableLocales(): array
    {
        $files = glob($this->snippetDirectory . '/*.json') ?: [];

        return array_map(static fn (string $file): string => basename($file, '.json'), $files);
    }

    private function lookup(string $key, string $locale): ?string
    {
        $node = $this->load($locale);

        foreach (explode('.', $key) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return \is_string($node) ? $node : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $locale): array
    {
        if (isset($this->loaded[$locale])) {
            return $this->loaded[$locale];
        }

        $file = $this->snippetDirectory . '/' . basename($locale) . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return $this->loaded[$locale] = \is_array($data) ? $data : [];
    }
}
