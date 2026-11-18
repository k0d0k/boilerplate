<?php

namespace Modules\DemoShowcase\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * Phase 1B-4 demonstration module service provider.
 *
 * The base class loads the module's translations (`demomodule::…`), views
 * (`demomodule::…`), optional config and optional migrations. The module ships
 * none of the latter two: it is deliberately non-clinical and stores nothing.
 */
class DemoShowcaseServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'DemoShowcase';

    protected string $nameLower = 'demomodule';
}
