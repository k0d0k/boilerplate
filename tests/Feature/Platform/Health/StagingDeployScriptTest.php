<?php

namespace Tests\Feature\Platform\Health;

use Tests\TestCase;

/**
 * WS6.G — guard rails for the staging deploy script.
 *
 * The staging login regression shipped because the deploy path could build the
 * front-end without its dev dependencies and had no post-deploy check that the
 * /login page's assets are actually SERVED. These assertions pin the two fixes
 * in place so a future edit of deploy-staging.sh cannot silently undo them.
 */
class StagingDeployScriptTest extends TestCase
{
    private function script(): string
    {
        return (string) file_get_contents(base_path('deploy-staging.sh'));
    }

    public function test_the_front_end_build_installs_dev_dependencies(): void
    {
        // vite / laravel-vite-plugin / tailwind are devDependencies; without
        // them `npm run build` cannot emit public/build and every asset 404s.
        $this->assertStringContainsString('npm ci --include=dev', $this->script());
    }

    public function test_the_deploy_wires_the_login_asset_smoke_check(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('lba:staging:login-assets', $script);
        $this->assertStringContainsString('verify_login_assets()', $script);
        $this->assertMatchesRegularExpression('/^\s*verify_login_assets\s*$/m', $script);
    }

    public function test_the_login_asset_smoke_check_runs_after_the_panel_check(): void
    {
        $script = $this->script();

        $panels = strpos($script, "\n    verify_panels\n");
        $assets = strpos($script, "\n    verify_login_assets\n");

        $this->assertNotFalse($panels, 'main() must invoke verify_panels.');
        $this->assertNotFalse($assets, 'main() must invoke verify_login_assets.');
        $this->assertGreaterThan($panels, $assets, 'The login-asset smoke check must run after the panel check.');
    }
}
