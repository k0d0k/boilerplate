<?php

namespace App\Support;

use Illuminate\Routing\UrlGenerator;

/**
 * Emits scheme/host-less ("root-relative") URLs.
 *
 * Behind the TRAE Cloud preview proxy the application only ever receives
 * `Host: localhost:8000` and no `X-Forwarded-*` headers, so Laravel cannot
 * reconstruct the origin the browser is actually using. Filament and Livewire
 * publish their CSS/JS/endpoints through asset()/url(), which would otherwise
 * be prefixed with an unreachable origin (http://localhost:8000) and never load.
 *
 * Returning root-relative URLs keeps every asset and endpoint same-origin
 * regardless of the proxy host/scheme the browser resolves.
 */
class RelativeUrlGenerator extends UrlGenerator
{
    /**
     * Never append a scheme/host to generated URLs.
     *
     * @param  string  $scheme
     * @param  string|null  $root
     */
    public function formatRoot($scheme, $root = null)
    {
        return '';
    }

    /**
     * Always return the path portion, keeping its leading slash so the result is
     * a root-relative URL (e.g. "/principal/login").
     *
     * @param  string  $root
     * @param  string  $path
     * @param  mixed  $route
     */
    public function format($root, $path, $route = null)
    {
        $path = '/'.trim($path, '/');

        if ($this->formatHostUsing) {
            $root = call_user_func($this->formatHostUsing, $root, $route);
        }

        if ($this->formatPathUsing) {
            $path = call_user_func($this->formatPathUsing, $path, $route);
        }

        return $path;
    }
}
