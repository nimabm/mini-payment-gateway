<?php

declare(strict_types=1);

namespace App\Presentation\Support;

use App\Application\Settings\Locale;

/**
 * A small translator over flat PHP arrays.
 *
 * Flat keys ("transactions.status.paid") rather than nested arrays, so a
 * missing translation is a visible, greppable string instead of a silent empty
 * cell. Falling back to English rather than to the key itself means a partially
 * translated locale still renders a usable page.
 */
final class Translator
{
    /** @var array<string, array<string, string>> */
    private array $catalogues = [];

    public function __construct(
        private readonly string $directory,
        private Locale $locale = Locale::Persian,
        private readonly Locale $fallback = Locale::English,
    ) {
    }

    public function setLocale(Locale $locale): void
    {
        $this->locale = $locale;
    }

    public function locale(): Locale
    {
        return $this->locale;
    }

    /**
     * @param array<string, string|int|float> $replacements
     */
    public function translate(string $key, array $replacements = []): string
    {
        $line = $this->catalogue($this->locale)[$key]
            ?? $this->catalogue($this->fallback)[$key]
            ?? $key;

        foreach ($replacements as $placeholder => $value) {
            $line = str_replace(':' . $placeholder, (string) $value, $line);
        }

        return $line;
    }

    /**
     * @return array<string, string>
     */
    private function catalogue(Locale $locale): array
    {
        if (isset($this->catalogues[$locale->value])) {
            return $this->catalogues[$locale->value];
        }

        $path = sprintf('%s/%s.php', rtrim($this->directory, '/'), $locale->value);

        /** @var array<string, string> $catalogue */
        $catalogue = is_file($path) ? require $path : [];

        return $this->catalogues[$locale->value] = $catalogue;
    }
}
