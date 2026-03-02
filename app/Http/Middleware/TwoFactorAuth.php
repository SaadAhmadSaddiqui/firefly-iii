<?php

/*
 * TwoFactorAuth.php
 * Copyright (c) 2025 james@firefly-iii.org.
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Http\Middleware;

use Closure;
use PragmaRX\Google2FALaravel\Middleware;
use PragmaRX\Google2FALaravel\Support\Authenticator;
use PragmaRX\Google2FALaravel\Support\Constants;

/**
 * Extends the upstream Google2FA middleware to guard against calling
 * withCookie() on response types that don't support it (e.g. BinaryFileResponse).
 */
class TwoFactorAuth extends Middleware
{
    public function handle($request, Closure $next)
    {
        /** @var Authenticator $authenticator */
        $authenticator = app(Authenticator::class)->boot($request);
        $cookieResult  = $authenticator->hasValidCookieToken();
        $authResult    = $authenticator->isAuthenticated();

        $response = $next($request);

        if (false === $cookieResult && true === $authResult && method_exists($response, 'withCookie')) {
            $cookieName = config('google2fa.cookie_name') ?? 'google2fa_token';
            $lifetime   = (int) (config('google2fa.cookie_lifetime') ?? 8035200);
            $lifetime   = $lifetime > 8035200 ? 8035200 : $lifetime;
            $token      = $authenticator->sessionGet(Constants::SESSION_TOKEN);
            $response->withCookie(cookie()->make($cookieName, $token, $lifetime / 60));
        }

        if (true === $cookieResult || true === $authResult) {
            return $response;
        }

        $authenticator->cleanupTokens();

        return $authenticator->makeRequestOneTimePasswordResponse();
    }
}
