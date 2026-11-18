<?php

namespace App\Livewire;

use App\Localization\Contracts\ResolvesLocale;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Livewire;

/**
 * Phase 1B-3 — authenticated language selector (topbar).
 *
 * The single shared language selector for all three panels. It persists the
 * user's choice on their central record and reloads the current page so the
 * whole shell re-renders in the new locale.
 *
 * Security: only the three canonical locale codes are accepted, the choice is
 * written to the user's presentation preference only, and it never touches the
 * role, memberships, tenant context or panel access.
 */
class LanguageSwitcher extends Component
{
    public bool $open = false;

    #[Locked]
    public bool $tailAdmin = false;

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function setLocale(string $locale): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        // Reject anything that is not one of the canonical supported codes: an
        // arbitrary or crafted value is never persisted.
        abort_unless(app(ResolvesLocale::class)->isSupported($locale), 422);

        // Presentation-only preference — authorization is untouched.
        $user->forceFill(['locale' => $locale])->save();

        $this->open = false;

        app()->setLocale($locale);

        // Reload the page the user is actually on: during a Livewire update the
        // current URL is the update endpoint, so the original page URL is used
        // to reload the whole shell in the new locale.
        $this->redirect(Livewire::originalUrl());
    }

    public function render(): View
    {
        return view($this->tailAdmin ? 'principal.layouts.language-switcher' : 'livewire.language-switcher', [
            'current' => app()->getLocale(),
            'languages' => $this->languages(),
        ]);
    }

    /**
     * @return array<string, string> supported code => endonym label
     */
    private function languages(): array
    {
        /** @var array<string, string> $labels */
        $labels = config('localization.labels', []);

        $languages = [];

        foreach (app(ResolvesLocale::class)->supported() as $code) {
            $languages[$code] = $labels[$code] ?? $code;
        }

        return $languages;
    }
}
