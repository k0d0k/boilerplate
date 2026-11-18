<?php

namespace App\Filament\Auth;

use App\Platform\Access\PanelResolver;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Post-authentication redirect for the unified staff login.
 *
 * Filament's default response sends the user to the *current* panel
 * (`Filament::getUrl()`), which is wrong for a login shared by several staff
 * panels. This response instead resolves the panel from the authenticated
 * identity through {@see PanelResolver}, echoing the same server-side gate that
 * authorises the panel requests.
 *
 * `session('url.intended')` is attacker-controllable, so it is never trusted as
 * a whole URL. It is honoured only when it reduces to a plain path that:
 *
 *  - is same-origin — a root-relative path, or an absolute URL whose scheme,
 *    host and port match the current request (Laravel itself stores intended
 *    URLs as absolute URLs, so a same-origin absolute value still resumes);
 *  - is not protocol-relative (`//evil.com`), authority-like (`/\evil.com`) or
 *    otherwise malformed;
 *  - contains no dot-segment (`..`) path traversal; and
 *  - lies inside the panel this identity is authorised to enter.
 *
 * Anything else is discarded in favour of the identity's authorised panel
 * dashboard, so a crafted value can never bounce a user onto an external origin
 * or onto a panel they cannot open.
 */
class StaffLoginResponse implements LoginResponse
{
    public function toResponse($request): RedirectResponse
    {
        $panelPath = app(PanelResolver::class)->panelPathFor(Auth::user());
        $destination = '/'.($panelPath ?? '');
        $intended = session()->pull('url.intended');

        return redirect()->to($this->safeIntendedUrl($intended, $destination, $request) ?? $destination);
    }

    /**
     * The intended drill-down path, or null when the value cannot be trusted.
     */
    private function safeIntendedUrl(mixed $intended, string $destination, Request $request): ?string
    {
        $destination = '/'.trim($destination, '/');

        // With no resolved panel there is nothing to confine the redirect to,
        // so no client-supplied target may ever be honoured.
        if ($destination === '/' || ! is_string($intended)) {
            return null;
        }

        $path = $this->safePath($intended, $request);

        if ($path === null) {
            return null;
        }

        // Panel authorization: only paths inside the resolved (authorised)
        // panel are reachable; a Principal target can never be reached by a
        // Clinic identity and vice versa.
        if ($path !== $destination && ! str_starts_with($path, $destination.'/')) {
            return null;
        }

        return $path;
    }

    /**
     * Reduce a candidate target to a safe, same-origin root-relative path, or
     * null when it is anything else.
     */
    private function safePath(string $intended, Request $request): ?string
    {
        // Reject control characters and backslashes: some agents normalise a
        // backslash to a forward slash, so `/\evil.com` could otherwise be read
        // as `//evil.com`.
        if ($intended === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $intended) === 1) {
            return null;
        }

        // Reject protocol-relative targets: without a scheme there is no
        // trustworthy origin to compare against.
        if (str_starts_with($intended, '//')) {
            return null;
        }

        $parts = parse_url($intended);

        if ($parts === false) {
            return null;
        }

        // When the value carries an origin (scheme/host/port/userinfo) it must
        // be the very same origin as this request; an external or downgraded
        // origin is rejected outright.
        $hasOrigin = isset($parts['scheme']) || isset($parts['host']) || isset($parts['port'])
            || isset($parts['user']) || isset($parts['pass']);

        if ($hasOrigin && ! $this->isSameOrigin($parts, $request)) {
            return null;
        }

        $path = $parts['path'] ?? '';

        return $this->normalizePath($path);
    }

    /**
     * Whether a parsed absolute URL points at the current request's origin.
     *
     * @param  array<string, int|string>  $parts
     */
    private function isSameOrigin(array $parts, Request $request): bool
    {
        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $scheme !== $request->getScheme()) {
            return false;
        }

        if (strtolower($parts['host'] ?? '') !== strtolower($request->getHost())) {
            return false;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $port === $request->getPort();
    }

    /**
     * Normalise a path to `/<segments>`, rejecting traversal and non-absolute
     * input. `%2e%2e` and `..` both resolve to a parent directory in browsers,
     * so both are rejected.
     */
    private function normalizePath(string $path): ?string
    {
        if ($path === '' || $path[0] !== '/') {
            return null;
        }

        $decoded = rawurldecode($path);

        if (str_contains($decoded, '\\')) {
            return null;
        }

        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return null;
            }
        }

        return '/'.trim($decoded, '/');
    }
}
