<?php

/*
 * I18nController.php
 * Serves v2 frontend translation JSON from Laravel lang files.
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 */

declare(strict_types=1);

namespace FireflyIII\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;

class I18nController extends Controller
{
    /**
     * Serve v2 i18n JSON for the given locale (e.g. en_US).
     * Frontend loadPath is ./v2/i18n/{{lng}}.json
     */
    public function v2(string $locale): JsonResponse
    {
        $locale = str_replace('-', '_', $locale);
        $fallback = config('app.fallback_locale', 'en');
        $supported = config('translations.languages', ['en_US']);
        if (!in_array($locale, $supported, true)) {
            $locale = $fallback === 'en' ? 'en_US' : $fallback;
        }
        App::setLocale($locale);

        $out = [];
        $v2 = config('translations.json.v2', []);
        foreach ($v2 as $namespace => $keys) {
            if (!is_array($keys)) {
                continue;
            }
            $out[$namespace] = [];
            foreach ($keys as $key) {
                $fullKey = $namespace . '.' . $key;
                $out[$namespace][$key] = (string) __($fullKey);
            }
        }

        return response()->json($out)->header('Content-Type', 'application/json');
    }
}
