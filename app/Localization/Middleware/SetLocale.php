<?php

namespace App\Localization\Middleware;

use App\Localization\Contracts\ResolvesLocale;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 1B-3 — applies the resolved locale to the current request.
 *
 * Registered on the `web` group (so it also covers Livewire's update endpoint)
 * and on every panel's middleware stack (so regular panel page loads resolve
 * the locale too). It only ever *sets* the presentation locale: the resolved
 * value is derived from the user/brand/tenant context that has already been
 * authorized elsewhere, and it never influences authorization itself.
 */
class SetLocale
{
    public function __construct(private readonly ResolvesLocale $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        App::setLocale($this->resolver->resolve($user instanceof User ? $user : null));

        return $next($request);
    }
}
