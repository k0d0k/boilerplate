<?php

use App\Providers\AccessServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BranchServiceProvider;
use App\Providers\FeatureGateServiceProvider;
use App\Providers\Filament\ClinicPanelProvider;
use App\Providers\Filament\PatientPanelProvider;
use App\Providers\Filament\PrincipalPanelProvider;
use App\Providers\LocalizationServiceProvider;
use App\Providers\ModuleRegistryServiceProvider;
use App\Providers\PatientApiServiceProvider;
use App\Providers\PlatformSettingsServiceProvider;
use App\Providers\TenancyServiceProvider;
use App\Providers\TenantContextServiceProvider;

return [
    AppServiceProvider::class,
    AccessServiceProvider::class,
    TenantContextServiceProvider::class,
    LocalizationServiceProvider::class,
    ModuleRegistryServiceProvider::class,
    FeatureGateServiceProvider::class,
    PlatformSettingsServiceProvider::class,
    TenancyServiceProvider::class,
    BranchServiceProvider::class,
    PatientApiServiceProvider::class,
    ClinicPanelProvider::class,
    PatientPanelProvider::class,
    PrincipalPanelProvider::class,
];
