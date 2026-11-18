<?php

namespace Tests\Feature\Public;

use Tests\TestCase;

/**
 * Public landing page (LBA Cloud v3.0 — public frontend).
 *
 * The root route replaced the default Laravel welcome page with the branded
 * LBA Cloud landing page. These tests pin the contract the task requires:
 * the page renders, the two verified entry points are linked correctly, and
 * no default framework branding survives.
 */
class HomepageTest extends TestCase
{
    public function test_the_homepage_returns_a_successful_response(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_the_homepage_shows_the_brand_and_hero_headline(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('LBA CLOUD')
            ->assertSee('Integrated Clinical Intelligence');
    }

    public function test_the_homepage_links_to_the_staff_and_patient_logins(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // Both verified entry points are linked with their exact targets.
        $this->assertStringContainsString('href="'.$this->staffLoginUrl().'"', $html);
        $this->assertStringContainsString('href="'.$this->patientLoginUrl().'"', $html);

        // The two labels are present as navigation copy.
        $this->assertStringContainsString('Login Staff', $html);
        $this->assertStringContainsString('Portal Pasien', $html);
    }

    public function test_the_default_laravel_welcome_page_is_gone(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/welcome.blade.php'));

        $this->get('/')
            ->assertOk()
            ->assertDontSee('laravel.com', false)
            ->assertDontSee('Laravel');
    }

    public function test_the_homepage_is_responsive_and_accessible_by_markup(): void
    {
        $this->get('/')
            ->assertOk()
            // Mobile-first viewport is declared.
            ->assertSee('name="viewport"', false)
            ->assertSee('width=device-width, initial-scale=1', false)
            // A mobile disclosure keeps navigation reachable on small screens.
            ->assertSee('<details', false)
            // A skip link keeps keyboard navigation accessible.
            ->assertSee('href="#main"', false);
    }

    private function staffLoginUrl(): string
    {
        return url('/login');
    }

    private function patientLoginUrl(): string
    {
        return url('/patient/login');
    }
}
