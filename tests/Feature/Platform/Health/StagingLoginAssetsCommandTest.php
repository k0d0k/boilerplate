<?php

namespace Tests\Feature\Platform\Health;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WS6.G — the post-deploy login-asset smoke check.
 *
 * Reproduces the staging regression: `/login` returns 200 HTML whose asset URLs
 * are correct, while every CSS/JS/font it references 404s as an HTML page. The
 * command must treat that as a hard failure and name the offending URL, and it
 * must pass only when the page and every local asset it references are served
 * with the right status and Content-Type.
 */
class StagingLoginAssetsCommandTest extends TestCase
{
    private const BASE = 'http://staging.test';

    /** Assets exactly as Filament + Vite reference them (never asserted by filename). */
    private const ASSETS = [
        '/build/assets/theme-abc12345.css',
        '/fonts/filament/filament/inter/index.css?v=5.11.1.0',
        '/js/filament/filament/app.js?v=5.11.1.0',
        '/livewire-abc123/livewire.js?id=deadbeef',
    ];

    private function html(array $assets): string
    {
        $tags = '';

        foreach ($assets as $asset) {
            $tags .= str_ends_with((string) parse_url($asset, PHP_URL_PATH), '.js')
                ? "<script src=\"{$asset}\"></script>"
                : "<link href=\"{$asset}\" rel=\"stylesheet\">";
        }

        return "<!doctype html><html><head>{$tags}</head><body>Masuk ke akun Anda</body></html>";
    }

    private function assetResponse(string $asset): PromiseInterface
    {
        $isScript = str_ends_with((string) parse_url($asset, PHP_URL_PATH), '.js');

        return $isScript
            ? Http::response('console.log(1)', 200, ['Content-Type' => 'application/javascript'])
            : Http::response('body{color:red}', 200, ['Content-Type' => 'text/css']);
    }

    /**
     * Fake a healthy origin, with optional per-asset overrides keyed by URL.
     *
     * Stubs are registered per exact asset URL (never by wildcard) so an
     * override can never collide with — or be shadowed by — another stub.
     */
    private function fakeOrigin(?array $assets = null, array $overrides = []): void
    {
        $assets ??= self::ASSETS;

        $stubs = [
            self::BASE.'/login' => Http::response($this->html($assets), 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]),
        ];

        foreach ($assets as $asset) {
            $stubs[self::BASE.$asset] = $overrides[self::BASE.$asset] ?? $this->assetResponse($asset);
        }

        Http::fake($stubs);
    }

    public function test_it_passes_when_the_login_page_and_every_asset_it_references_are_served(): void
    {
        $this->fakeOrigin();

        $this->artisan('lba:staging:login-assets', ['--url' => self::BASE])
            ->expectsOutputToContain('Login assets: OK')
            ->assertSuccessful();
    }

    public function test_it_fails_and_names_the_url_when_a_css_asset_returns_the_html_404_page(): void
    {
        $this->fakeOrigin(overrides: [
            self::BASE.'/build/assets/theme-abc12345.css' => Http::response(
                '<html><title>Not Found</title></html>', 404, ['Content-Type' => 'text/html; charset=utf-8'],
            ),
        ]);

        $this->artisan('lba:staging:login-assets', ['--url' => self::BASE])
            ->expectsOutputToContain('http://staging.test/build/assets/theme-abc12345.css')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_a_js_asset_is_served_with_an_html_content_type(): void
    {
        $this->fakeOrigin(overrides: [
            self::BASE.'/js/filament/filament/app.js?v=5.11.1.0' => Http::response(
                '<html></html>', 200, ['Content-Type' => 'text/html; charset=utf-8'],
            ),
        ]);

        $this->artisan('lba:staging:login-assets', ['--url' => self::BASE])
            ->expectsOutputToContain('http://staging.test/js/filament/filament/app.js?v=5.11.1.0')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_a_required_asset_family_is_not_referenced(): void
    {
        // The page references no Filament JavaScript at all.
        $this->fakeOrigin(['/build/assets/theme-abc12345.css', '/livewire-abc123/livewire.js?id=deadbeef']);

        $this->artisan('lba:staging:login-assets', ['--url' => self::BASE])
            ->expectsOutputToContain('Filament JavaScript')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_the_login_page_is_not_an_html_document(): void
    {
        Http::fake([
            self::BASE.'/login' => Http::response('', 500, ['Content-Type' => 'text/html']),
        ]);

        $this->artisan('lba:staging:login-assets', ['--url' => self::BASE])
            ->assertExitCode(1);
    }

    public function test_it_fails_when_the_login_page_references_no_local_assets(): void
    {
        Http::fake([
            self::BASE.'/login' => Http::response(
                '<!doctype html><html><body><a href="/staff/password/forgot">Lupa password</a></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
            ),
        ]);

        $this->artisan('lba:staging:login-assets', ['--url' => self::BASE])
            ->expectsOutputToContain('no local asset URL')
            ->assertExitCode(1);
    }

    public function test_it_emits_machine_readable_json(): void
    {
        $this->fakeOrigin();

        $this->artisan('lba:staging:login-assets', ['--url' => self::BASE, '--json' => true])
            ->expectsOutputToContain('"ok": true')
            ->assertSuccessful();
    }
}
