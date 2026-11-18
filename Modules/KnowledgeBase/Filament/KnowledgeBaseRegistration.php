<?php

namespace Modules\KnowledgeBase\Filament;

use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Filament\AbstractModuleRegistration;
use Modules\KnowledgeBase\Filament\Pages\KnowledgeConsole;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeCollectionResource;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeDocumentResource;

/**
 * Workstream 9 — Knowledge Base module-to-panel registration.
 *
 * Declares the module's Clinic-panel resources. The Principal governance
 * surface (platform reference curation) is a platform page under
 * `app/Filament/Principal`, exactly like the other governance pages, so no
 * module resource is ever registered into the Principal panel.
 *
 * Everything is gated server-side by the FeatureGate and RBAC; the manifest
 * authorises the module for the Clinic panel only, and a Clinic Admin can build
 * TENANT-scope collections only — platform-scope curation is a Principal act.
 */
final class KnowledgeBaseRegistration extends AbstractModuleRegistration
{
    public function key(): string
    {
        return 'knowledge_base';
    }

    /**
     * @return list<string>
     */
    public function panels(): array
    {
        return [PanelAccessPolicy::PANEL_CLINIC];
    }

    /**
     * @return list<class-string>
     */
    public function resources(): array
    {
        return [
            KnowledgeCollectionResource::class,
            KnowledgeDocumentResource::class,
        ];
    }

    /**
     * @return list<class-string>
     */
    public function pages(): array
    {
        return [
            KnowledgeConsole::class,
        ];
    }
}
