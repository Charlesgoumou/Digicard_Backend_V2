<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful as SanctumMiddleware;

class EnsureFrontendRequestsAreStateful extends SanctumMiddleware
{
    /**
     * Determine if the given request is from the first-party application frontend.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    public static function fromFrontend($request)
    {
        // First, use the parent method to check explicit domains
        if (parent::fromFrontend($request)) {
            return true;
        }

        // Then check if the request comes from a Cloudflare domain using patterns
        $domain = $request->headers->get('referer') ?: $request->headers->get('origin');

        if (is_null($domain)) {
            return false;
        }

        $domain = Str::replaceFirst('https://', '', $domain);
        $domain = Str::replaceFirst('http://', '', $domain);
        $domain = Str::endsWith($domain, '/') ? $domain : "{$domain}/";

        // Patterns for Cloudflare domains (using Laravel's Str::is which supports wildcards)
        $cloudflarePatterns = [
            '*.arccenciel.com/*',
            '*.digicard.arccenciel.com/*',
            'arccenciel.com/*',
            'digicard.arccenciel.com/*',
            'admin.digicard.arccenciel.com/*',
        ];

        foreach ($cloudflarePatterns as $pattern) {
            if (Str::is($pattern, $domain)) {
                return true;
            }
        }

        return false;
    }
}

