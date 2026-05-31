<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class RestrictedAccess
{
    public function handle($request, Closure $next, $guard = null)
    {
        if (config('instance.restricted.enabled')) {
            // Token-Auth (Mobile-App/OAuth) mitprüfen — eingeloggte Token-Clients
            // sind keine Gäste; sonst werden web-Gruppen-Routen (z.B.
            // api/pixelfed/v1/*) trotz gültigem Token nach /login umgeleitet.
            if (! Auth::guard($guard)->check() && ! Auth::guard('api')->check()) {
                // Whitelist: Login-Seiten + direkte Post/Collection-Deeplinks +
                // deren Daten-API (Post-Inhalt). NUR Einzel-Ressourcen per ID —
                // KEINE Listing-Endpunkte (timelines, accounts/*/statuses, search):
                // $request->is('*') matcht auch Slashes, sonst wäre Browsing möglich.
                $p = [
                    'login', 'password*', 'loginAs*',
                    'p/*/*', 'c/*/*',
                    'api/v2/profile/*/status/*',
                    'api/v2/statuses/*/state',
                    'oauth/token',
                ];
                if (! $request->is($p)) {
                    return redirect('/login');
                }
            }
        }

        return $next($request);
    }
}
