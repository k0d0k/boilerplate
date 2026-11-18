<?php

namespace App\Platform\Health\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WS6.G — post-deploy smoke check for the login page's static assets.
 *
 * The regression this guards against: a deploy that ran `npm run build` and
 * `php artisan filament:assets`, but whose generated files are not actually
 * served by the web server (missing/unreadable files, a document root that
 * points somewhere else, or a LiteSpeed/cPanel rewrite). The page itself keeps
 * returning 200 HTML — with correct, hashed asset URLs — while every CSS/JS/font
 * it references 404s, so the login form renders unstyled and its Livewire
 * JavaScript never loads. Nothing in the old pipeline noticed; it passed.
 *
 * This command fetches `/login`, extracts the LOCAL asset URLs the page really
 * references (link/script tags — never a hardcoded Vite filename), then asserts
 * each one is served with a 2xx status and a non-HTML Content-Type. A 404 (or
 * any HTML response at an asset URL, which is what a front-controller rewrite
 * yields when the file is absent) is a hard failure that names the offending URL.
 *
 * Read-only: it only issues GET requests and prints no credential or PII.
 */
class StagingLoginAssetsCommand extends Command
{
    protected $signature = 'lba:staging:login-assets
        {--url= : Base URL to probe (defaults to config(app.url))}
        {--timeout=15 : Per-request timeout, in seconds}
        {--json : Emit the report as machine-readable JSON}';

    protected $description = 'Post-deploy smoke check: every local asset referenced by /login must be served with the right status and Content-Type.';

    /** Local assets the login page MUST reference (never a hardcoded hash). */
    private const REQUIRED_FAMILIES = [
        'theme_css' => [
            'label' => 'Vite theme/bundle CSS',
            'match' => '~^/build/assets/.+\.css$~',
        ],
        'filament_js' => [
            'label' => 'Filament JavaScript',
            'match' => '~^/js/filament/.+\.js$~',
        ],
        'livewire_js' => [
            'label' => 'Livewire runtime JavaScript',
            'match' => '~^/livewire[^/]*/livewire\.js$~',
        ],
    ];

    public function handle(): int
    {
        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $timeout = max(1, (int) $this->option('timeout'));

        if ($base === '') {
            $this->error('No base URL given: pass --url or set APP_URL.');

            return self::FAILURE;
        }

        $checks = $this->inspect($base, $timeout);

        $failures = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] === 'fail',
        ));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $failures === [],
                'url' => $base,
                'failures' => count($failures),
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Asset', 'HTTP', 'Content-Type', 'Status'],
                array_map(
                    static fn (array $check): array => [
                        $check['url'],
                        $check['http'] ?? '-',
                        $check['content_type'] ?? '-',
                        strtoupper($check['status']),
                    ],
                    $checks,
                ),
            );

            $this->newLine();

            if ($failures === []) {
                $this->info('Login assets: OK — every asset referenced by /login was served correctly.');
            } else {
                $this->error('Login assets: '.count($failures).' failing asset(s):');
                foreach ($failures as $failure) {
                    $this->error(sprintf('  - %s (%s)', $failure['url'], $failure['detail']));
                }
            }
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array{url: string, status: string, detail: string, http?: int|string, content_type?: string}>
     */
    private function inspect(string $base, int $timeout): array
    {
        $loginUrl = $base.'/login';
        $login = $this->fetch($loginUrl, $timeout);

        if ($login === null) {
            return [$this->check($loginUrl, 'fail', 'no response (connection failed)')];
        }

        $status = $login->status();
        $contentType = $this->contentType($login);

        if ($status !== 200 || ! str_contains($contentType, 'text/html')) {
            return [$this->check(
                $loginUrl,
                'fail',
                "expected 200 text/html, got {$status} {$contentType}",
                $status,
                $contentType,
            )];
        }

        $checks = [$this->check($loginUrl, 'ok', 'login page rendered', $status, $contentType)];

        $assets = $this->extractLocalAssets($login->body());

        if ($assets === []) {
            $checks[] = $this->check($loginUrl, 'fail', 'the login page referenced no local asset URL');

            return $checks;
        }

        foreach (self::REQUIRED_FAMILIES as $family) {
            if (! $this->anyMatches($assets, $family['match'])) {
                $checks[] = $this->check($loginUrl, 'fail', 'no '.$family['label'].' referenced by /login');
            }
        }

        foreach ($assets as $asset) {
            $checks[] = $this->inspectAsset($base, $asset, $timeout);
        }

        return $checks;
    }

    /**
     * @return array{url: string, status: string, detail: string, http?: int|string, content_type?: string}
     */
    private function inspectAsset(string $base, string $asset, int $timeout): array
    {
        $url = $base.$asset;
        $response = $this->fetch($url, $timeout);

        if ($response === null) {
            return $this->check($url, 'fail', 'no response (connection failed)');
        }

        $status = $response->status();
        $contentType = $this->contentType($response);

        if ($status < 200 || $status >= 300) {
            return $this->check($url, 'fail', "expected 2xx, got {$status}{$this->htmlHint($contentType)}", $status, $contentType);
        }

        // An asset answered with an HTML page: the request fell through to the
        // front controller (file absent / not served) or hit an error page.
        if (str_contains($contentType, 'text/html')) {
            return $this->check($url, 'fail', 'served an HTML page instead of the asset', $status, $contentType);
        }

        $expected = $this->expectedMimeTypes($asset);
        if ($expected !== [] && ! $this->anyMimeTypeMatches($contentType, $expected)) {
            return $this->check(
                $url,
                'fail',
                'unexpected Content-Type (expected '.implode(' | ', $expected).')',
                $status,
                $contentType,
            );
        }

        return $this->check($url, 'ok', 'served', $status, $contentType);
    }

    /**
     * Every root-relative URL referenced by a <link href> or <script src>.
     *
     * @return list<string>
     */
    private function extractLocalAssets(string $html): array
    {
        if (! preg_match_all('/<(?:link|script)\b[^>]*?(?:href|src)\s*=\s*"([^"]*)"/i', $html, $matches)) {
            return [];
        }

        $assets = [];

        foreach ($matches[1] as $reference) {
            $reference = html_entity_decode(trim($reference), ENT_QUOTES);

            // Root-relative only (never //host, http(s):// or a data: URI).
            if (! str_starts_with($reference, '/') || str_starts_with($reference, '//')) {
                continue;
            }

            $assets[$reference] = true;
        }

        return array_keys($assets);
    }

    /**
     * @param  list<string>  $assets
     */
    private function anyMatches(array $assets, string $pattern): bool
    {
        foreach ($assets as $asset) {
            if (preg_match($pattern, $this->path($asset)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function expectedMimeTypes(string $asset): array
    {
        return match (strtolower(pathinfo($this->path($asset), PATHINFO_EXTENSION))) {
            'css' => ['text/css'],
            'js', 'mjs' => ['application/javascript', 'text/javascript', 'application/x-javascript'],
            'woff2' => ['font/woff2', 'application/octet-stream', 'application/font-woff2'],
            'woff' => ['font/woff', 'application/font-woff', 'application/octet-stream'],
            'ttf' => ['font/ttf', 'application/x-font-ttf', 'application/octet-stream'],
            'otf' => ['font/otf', 'application/x-font-opentype', 'application/octet-stream'],
            'eot' => ['application/vnd.ms-fontobject', 'application/octet-stream'],
            default => [],
        };
    }

    /**
     * @param  list<string>  $expected
     */
    private function anyMimeTypeMatches(string $contentType, array $expected): bool
    {
        foreach ($expected as $mime) {
            if (str_contains($contentType, $mime)) {
                return true;
            }
        }

        return false;
    }

    private function path(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_PATH) ?: $url);
    }

    private function htmlHint(string $contentType): string
    {
        return str_contains($contentType, 'text/html')
            ? ' (an HTML error/404 page — the asset was not served)'
            : '';
    }

    private function contentType(Response $response): string
    {
        return strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
    }

    private function fetch(string $url, int $timeout): ?Response
    {
        try {
            return Http::timeout($timeout)->withoutRedirecting()->get($url);
        } catch (ConnectionException|Throwable) {
            return null;
        }
    }

    /**
     * @return array{url: string, status: string, detail: string, http?: int|string, content_type?: string}
     */
    private function check(string $url, string $status, string $detail, int|string|null $http = null, ?string $contentType = null): array
    {
        $check = ['url' => $url, 'status' => $status, 'detail' => $detail];

        if ($http !== null) {
            $check['http'] = $http;
        }

        if ($contentType !== null) {
            $check['content_type'] = $contentType;
        }

        return $check;
    }
}
