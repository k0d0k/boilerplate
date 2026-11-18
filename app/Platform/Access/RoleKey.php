<?php

namespace App\Platform\Access;

/**
 * Canonical role keys (Phase 1B-2A).
 *
 * These mirror docs/ROLE_PERMISSION_MATRIX.md and the provisional
 * {@see PanelAccessPolicy} map. They are the single naming source for both the
 * legacy `users.role` string and the membership-backed catalog, so the two can
 * never drift apart in spelling (see the catalog consistency test).
 *
 * A role grants access to exactly one panel; `ai_service` is machine-only and
 * must never hold a human panel or human permission.
 */
final class RoleKey
{
    // Principal / platform governance (panel: principal).
    public const PRINCIPAL_SUPER_ADMIN = 'principal_super_admin';

    public const PRINCIPAL_MODULE_ADMIN = 'principal_module_admin';

    public const PRINCIPAL_FINANCE = 'principal_finance';

    public const BRAND_MANAGER = 'brand_manager';

    // Clinic / tenant operations (panel: clinic).
    public const TENANT_OWNER = 'tenant_owner';

    public const TENANT_ADMIN = 'tenant_admin';

    public const BRANCH_MANAGER = 'branch_manager';

    public const RECEPTIONIST = 'receptionist';

    public const SCHEDULER = 'scheduler';

    public const LOCATION_EVENT_COORDINATOR = 'location_event_coordinator';

    public const LBA_OPERATOR = 'lba_operator';

    public const REVIEWER = 'reviewer';

    public const CONSULTANT = 'consultant';

    public const COMMERCE_MANAGER = 'commerce_manager';

    public const TENANT_FINANCE = 'tenant_finance';

    // Patient / consumer self-service (panel: patient).
    public const PATIENT_CUSTOMER = 'patient_customer';

    // Machine-only; no human panel and no human permissions.
    public const AI_SERVICE = 'ai_service';

    /** @var list<string> */
    public const PRINCIPAL_ROLES = [
        self::PRINCIPAL_SUPER_ADMIN,
        self::PRINCIPAL_MODULE_ADMIN,
        self::PRINCIPAL_FINANCE,
        self::BRAND_MANAGER,
    ];

    /** @var list<string> */
    public const TENANT_ROLES = [
        self::TENANT_OWNER,
        self::TENANT_ADMIN,
        self::BRANCH_MANAGER,
        self::RECEPTIONIST,
        self::SCHEDULER,
        self::LOCATION_EVENT_COORDINATOR,
        self::LBA_OPERATOR,
        self::REVIEWER,
        self::CONSULTANT,
        self::COMMERCE_MANAGER,
        self::TENANT_FINANCE,
    ];

    /**
     * Every role key, including the machine-only identity.
     *
     * @var list<string>
     */
    public const ALL = [
        self::PRINCIPAL_SUPER_ADMIN,
        self::PRINCIPAL_MODULE_ADMIN,
        self::PRINCIPAL_FINANCE,
        self::BRAND_MANAGER,
        self::TENANT_OWNER,
        self::TENANT_ADMIN,
        self::BRANCH_MANAGER,
        self::RECEPTIONIST,
        self::SCHEDULER,
        self::LOCATION_EVENT_COORDINATOR,
        self::LBA_OPERATOR,
        self::REVIEWER,
        self::CONSULTANT,
        self::COMMERCE_MANAGER,
        self::TENANT_FINANCE,
        self::PATIENT_CUSTOMER,
        self::AI_SERVICE,
    ];
}
