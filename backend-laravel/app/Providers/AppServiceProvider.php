<?php

namespace App\Providers;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Return no redirect for unauthenticated API requests so they get a
        // clean JSON 401 response instead of a redirect to a named route.
        Authenticate::redirectUsing(function ($request) {
            // For API routes, return null → AuthenticationException renders JSON 401.
            if ($request->is('api/*')) return null;

            // For non-API routes, you could return a named route or URL here.
            return null;
        });

        // Named login rate limiter: 5 attempts/min keyed by username + IP so an
        // attacker can't brute-force one account from many IPs, nor many
        // accounts from one IP, without hitting the limit.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)
                ->by(Str::lower((string) $request->input('username')).'|'.$request->ip())
                ->response(function (Request $request, array $headers) {
                    $retry = $headers['Retry-After'] ?? 60;

                    return response()->json([
                        'message' => "Too many login attempts. Please try again in {$retry} seconds.",
                        'errors'  => ['username' => ['Too many login attempts. Please try again shortly.']],
                    ], 429, $headers);
                });
        });
    }
}
