<?php

namespace Tests\Feature\Support;

use App\Support\RelativeUrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Regression tests for App\Support\RelativeUrlGenerator.
 *
 * The generator exists because the TRAE Cloud preview proxy forwards
 * `Host: localhost:8000` with no X-Forwarded-* headers, so Laravel cannot
 * reconstruct the browser origin. Every generated URL is therefore emitted
 * root-relative and stays same-origin behind any proxy host/scheme.
 */
class RelativeUrlGeneratorTest extends TestCase
{
    private const SIGNED_ROUTE = 'filament.principal.auth.login';

    public function test_the_relative_generator_is_bound_as_the_url_singleton(): void
    {
        $this->assertInstanceOf(RelativeUrlGenerator::class, URL::getFacadeRoot());
    }

    public function test_generated_urls_are_root_relative(): void
    {
        $this->assertSame('/', URL::to('/'));
        $this->assertSame('/foo/bar', URL::to('foo/bar'));

        $this->assertSame('/principal', route('filament.principal.pages.dashboard'));
        $this->assertSame('/clinic', route('filament.clinic.pages.dashboard'));
        $this->assertSame('/patient/login', route('filament.patient.auth.login'));
    }

    public function test_generated_urls_never_leak_a_scheme_or_host(): void
    {
        $urls = [
            URL::to('dashboard'),
            route('filament.principal.pages.dashboard'),
            asset('build/assets/theme.css'),
        ];

        foreach ($urls as $url) {
            $this->assertStringStartsWith('/', $url);
            $this->assertStringNotContainsString('http://', $url);
            $this->assertStringNotContainsString('https://', $url);
            $this->assertStringNotContainsString('localhost', $url);
        }
    }

    public function test_filament_asset_urls_are_root_relative(): void
    {
        $this->assertSame('/build/assets/theme.css', asset('build/assets/theme.css'));
        $this->assertSame('/css/filament/lba-cloud/theme.css', asset('css/filament/lba-cloud/theme.css'));
    }

    public function test_livewire_endpoint_url_is_root_relative(): void
    {
        $name = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->first(fn (?string $name) => $name !== null && str_ends_with($name, 'livewire.update'));

        if ($name === null) {
            $this->markTestSkipped('Livewire update route is not registered in this environment.');
        }

        // Livewire resolves its endpoint through route(), so it must stay
        // same-origin for XHR requests behind the preview proxy.
        $url = route($name);

        $this->assertStringStartsWith('/livewire', $url);
        $this->assertStringNotContainsString('://', $url);
    }

    public function test_signed_route_is_root_relative_and_validates_relatively(): void
    {
        $url = URL::signedRoute(self::SIGNED_ROUTE);

        $this->assertStringStartsWith('/principal/login', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString('://', $url);

        $this->assertTrue(URL::hasValidRelativeSignature(Request::create($url, 'GET')));
    }

    public function test_tampered_signed_route_is_rejected(): void
    {
        $url = URL::signedRoute(self::SIGNED_ROUTE);

        $tampered = $url.'&injected=1';

        $this->assertFalse(URL::hasValidRelativeSignature(Request::create($tampered, 'GET')));
        $this->assertFalse(URL::hasValidRelativeSignature(Request::create('/principal/login', 'GET')));
    }

    public function test_temporary_signed_route_honours_expiry(): void
    {
        $valid = URL::temporarySignedRoute(self::SIGNED_ROUTE, now()->addMinutes(5));
        $this->assertTrue(URL::hasValidRelativeSignature(Request::create($valid, 'GET')));

        $expired = URL::temporarySignedRoute(self::SIGNED_ROUTE, now()->subMinute());
        $this->assertFalse(URL::hasValidRelativeSignature(Request::create($expired, 'GET')));
    }

    /**
     * Documented limitation.
     *
     * Absolute signature validation assumes a scheme+host origin (which a
     * root-relative signed URL never carries), so it must not be used against
     * this generator. Relative validation is the supported path.
     */
    public function test_absolute_signature_validation_is_incompatible_with_relative_urls(): void
    {
        $url = URL::signedRoute(self::SIGNED_ROUTE);
        $request = Request::create($url, 'GET');

        $this->assertFalse(URL::hasValidSignature($request));
        $this->assertTrue(URL::hasValidRelativeSignature($request));
    }

    /**
     * Documented limitation for later phases (webhooks / payments / deployment).
     *
     * The generator always emits root-relative URLs, so it cannot be relied on
     * to produce an absolute URL a third party must dereference (e.g. a payment
     * provider callback or webhook endpoint). Those flows must build absolute
     * URLs explicitly. Already-absolute external URLs are passed through
     * untouched because Laravel short-circuits on valid URLs.
     */
    public function test_generator_does_not_mangle_external_absolute_urls(): void
    {
        $external = 'https://example.com/webhooks/payment';

        $this->assertSame($external, URL::to($external));
    }

    public function test_external_payment_and_webhook_callbacks_require_absolute_urls(): void
    {
        // Future payment callbacks and webhook endpoints must be absolute URLs a
        // third party can dereference. Because the generator always emits
        // root-relative URLs, those flows have to build the URL explicitly and
        // pass it through URL::to(), which Laravel short-circuits unchanged.
        foreach ([
            'https://lba.example.id/webhooks/payment/callback',
            'http://localhost:8000/webhooks/provider/notify',
        ] as $absolute) {
            $this->assertSame($absolute, URL::to($absolute));
        }

        // Internal route generation, by contrast, stays root-relative.
        $internal = route('filament.principal.pages.dashboard');

        $this->assertStringStartsWith('/', $internal);
        $this->assertStringNotContainsString('://', $internal);
    }
}
