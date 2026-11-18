<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Drives the Principal Filament panel through the real Livewire HTTP surface.
 *
 * Filament 5 pages and relation managers cannot be driven reliably with
 * `Livewire::test()->call()` in this application: the follow-up re-render POST
 * fails snapshot validation, and relation managers are lazily mounted so a
 * direct POST to their snapshot renders before the table is initialized.
 *
 * These helpers therefore post to the Livewire update endpoint against the
 * snapshot embedded in the rendered page — resuming the lazy mount first when
 * the target is a relation manager. This is exactly the round-trip a browser
 * performs, so the tests exercise the genuine component lifecycle.
 */
trait DrivesPrincipalPanel
{
    private function renderPage(string $path): string
    {
        return $this->get($path)->assertOk()->getContent();
    }

    private function componentSnapshot(string $html, string $component): string
    {
        if (! preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches)) {
            $this->fail('The rendered page did not expose any Livewire snapshot.');
        }

        foreach ($matches[1] as $raw) {
            $snapshot = html_entity_decode($raw, ENT_QUOTES);

            if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
                return $snapshot;
            }
        }

        $this->fail("The rendered page did not expose a [{$component}] component.");
    }

    /**
     * Issue a single Livewire call against a component embedded in the page.
     *
     * @param  list<mixed>  $params
     * @param  array<string, mixed>  $updates
     */
    private function callLivewire(string $html, string $component, string $method, array $params = [], array $updates = []): TestResponse
    {
        return $this->postLivewireCalls($html, $component, [
            ['method' => $method, 'params' => $params],
        ], $updates);
    }

    /**
     * Issue an ordered sequence of Livewire calls against a page component.
     *
     * @param  list<array{method: string, params?: list<mixed>}>  $calls
     * @param  array<string, mixed>  $updates  property path => value, applied
     *                                         before the calls (the same payload
     *                                         a browser sends with a wire:model)
     */
    private function postLivewireCalls(string $html, string $component, array $calls, array $updates = []): TestResponse
    {
        return $this->json('POST', app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $this->componentSnapshot($html, $component),
                'calls' => array_map(
                    static fn (array $call): array => [
                        'method' => $call['method'],
                        'params' => $call['params'] ?? [],
                        'path' => '',
                    ],
                    $calls,
                ),
                'updates' => $updates,
            ]],
        ], ['X-Livewire' => 'true']);
    }

    /**
     * Open a secondary relation-manager tab.
     *
     * Filament renders each relation manager behind a tab and only mounts the
     * active one. Switching `activeRelationManager` re-renders the page, and
     * the newly active manager is returned inside the response's HTML effects.
     *
     * @return string the re-rendered page HTML, now containing the manager
     */
    private function openRelationManagerTab(string $html, string $pageComponent, int $index): string
    {
        $response = $this->json('POST', app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $this->componentSnapshot($html, $pageComponent),
                'calls' => [],
                'updates' => ['activeRelationManager' => $index],
            ]],
        ], ['X-Livewire' => 'true']);

        $response->assertOk();

        return (string) (json_decode($response->getContent(), true)['components'][0]['effects']['html'] ?? '');
    }

    /**
     * Drive a lazily-mounted relation manager.
     *
     * Filament renders relation managers lazily; the placeholder carries a
     * `$wire.__lazyLoad('{token}')` resume token. We replay that call first so
     * the manager mounts (initializing its table) before the given calls run.
     *
     * @param  list<array{method: string, params?: list<mixed>}>  $calls
     */
    private function callRelationManager(string $html, string $component, array $calls): TestResponse
    {
        return $this->postLivewireCalls($html, $component, array_merge(
            [['method' => '__lazyLoad', 'params' => [$this->lazyLoadToken($html, $component)]]],
            $calls,
        ));
    }

    private function lazyLoadToken(string $html, string $component): string
    {
        if (! preg_match_all(
            '/__lazyLoad\((?:&#039;|&apos;|\')([^\'"&]+)(?:&#039;|&apos;|\')\)/',
            $html,
            $matches,
            PREG_OFFSET_CAPTURE,
        )) {
            $this->fail("The rendered page did not expose a lazy-load token for [{$component}].");
        }

        foreach ($matches[1] as [$token, $offset]) {
            // The token lives on the same root element as the manager's own
            // snapshot, so the nearest preceding snapshot identifies it.
            if (! preg_match_all('/wire:snapshot="([^"]+)"/', substr($html, 0, $offset), $snapshots)) {
                continue;
            }

            $snapshot = html_entity_decode(end($snapshots[1]), ENT_QUOTES);

            if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
                return $token;
            }
        }

        $this->fail("The rendered page did not expose a lazy-load token for [{$component}].");
    }
}
