<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Keep the leading slash on prepared request URIs.
     *
     * The application emits root-relative URLs (App\Support\RelativeUrlGenerator),
     * so Laravel's default helper returns a path without a leading slash and
     * REQUEST_URI ends up non-absolute for the test request. Real HTTP requests
     * always carry the leading slash, so restoring it here keeps the harness
     * faithful to production and lets Livewire's persistent-middleware replay
     * resolve the originating route (it compares REQUEST_URI against
     * request()->path(), which is always slash-prefixed).
     */
    protected function prepareUrlForRequest($uri)
    {
        $prepared = parent::prepareUrlForRequest($uri);

        return str_contains($prepared, '://') ? $prepared : '/'.ltrim($prepared, '/');
    }
}
