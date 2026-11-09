<?php

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Tools;

use Illuminate\Console\Command;

/**
 * Writes public/v3/i18n/<lang>.json for the v3 frontend.
 *
 * Upstream generates these files in its release pipeline (ff3:json-translations v3 in JC5/firefly-iii-dev),
 * so a git checkout has none and the v3 pages show raw keys like "firefly.never" and "(DATE ERROR)".
 * This does the same job from the local language files: for every language in config/translations.php,
 * take the keys listed under json.v3, fall back to English for missing ones, and store the result under
 * the html_language code (e.g. "en"), the dashed code ("en-US") and the underscored code ("en_US").
 */
class GenerateJsTranslations extends Command
{
    protected $description = 'Generate the v3 frontend translation files (public/v3/i18n/*.json) from resources/lang.';

    protected $signature = 'firefly:generate-js-translations';

    public function handle(): int
    {
        $config    = require config_path('translations.php');
        $wanted    = $config['json']['v3'] ?? [];
        $languages = $config['languages'] ?? [];
        $target    = public_path('v3/i18n');
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }

        $english = $this->collect('en_US', $wanted, []);
        $written = 0;
        foreach ($languages as $language) {
            if (!is_dir(resource_path('lang/'.$language))) {
                continue;
            }
            $content = $this->collect($language, $wanted, $english);
            $json    = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $codes   = array_unique([$content['config']['html_language'] ?? $language, str_replace('_', '-', $language), $language]);
            foreach ($codes as $code) {
                file_put_contents(sprintf('%s/%s.json', $target, $code), $json);
                ++$written;
            }
        }
        $this->info(sprintf('Wrote %d translation files for %d languages to %s.', $written, count($languages), $target));

        return self::SUCCESS;
    }

    /**
     * @param array<string, array<int, string>> $wanted   file => keys
     * @param array<string, array<string, string>> $fallback same shape as the result, used for missing keys
     *
     * @return array<string, array<string, string>>
     */
    private function collect(string $language, array $wanted, array $fallback): array
    {
        $result = [];
        foreach ($wanted as $file => $keys) {
            $path    = resource_path(sprintf('lang/%s/%s.php', $language, $file));
            $strings = is_file($path) ? require $path : [];
            foreach (array_unique($keys) as $key) {
                $value = $strings[$key] ?? $fallback[$file][$key] ?? sprintf('(%s.%s)', $file, $key);
                $result[$file][$key] = (string) $value;
            }
        }

        return $result;
    }
}
