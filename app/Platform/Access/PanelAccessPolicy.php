<?php

namespace App\Platform\Access;

/**
 * PROVISIONAL panel-access policy — Phase 1A-2 only.
 *
 * This is a deliberately minimal, explicit, DENY-BY-DEFAULT mapping used solely
 * to demonstrate and test panel isolation with synthetic identities. It is NOT
 * the final membership/entitlement authorization model, which arrives in
 * Phase 1B (identity) and Phase 2 (tenancy).
 *
 * Rules it enforces now:
 *  - Unknown / absent role => denied on every panel.
 *  - A role grants access to exactly one panel ID (the v3.0 navigation contract).
 *  - Suspended identities are denied (handled in App\Models\User::canAccessPanel).
 */
final class PanelAccessPolicy
{
    /** Panel IDs fixed by the v3.0 navigation contract. */
    public const PANEL_PRINCIPAL = 'principal';

    public const PANEL_CLINIC = 'clinic';

    public const PANEL_PATIENT = 'patient';

    public const PANELS = [
        self::PANEL_PRINCIPAL,
        self::PANEL_CLINIC,
        self::PANEL_PATIENT,
    ];

    /**
     * Explicit role => allowed panel map (see docs/ROLE_PERMISSION_MATRIX.md).
     * A role missing from this map is treated as unknown and denied everywhere.
     *
     * @var array<string, string>
     */
    private const ROLE_PANEL = [
        // Principal / platform governance
        'principal_super_admin' => self::PANEL_PRINCIPAL,
        'principal_module_admin' => self::PANEL_PRINCIPAL,
        'principal_finance' => self::PANEL_PRINCIPAL,
        'brand_manager' => self::PANEL_PRINCIPAL,
        // Clinic / tenant operations
        'tenant_owner' => self::PANEL_CLINIC,
        'tenant_admin' => self::PANEL_CLINIC,
        'branch_manager' => self::PANEL_CLINIC,
        'receptionist' => self::PANEL_CLINIC,
        'scheduler' => self::PANEL_CLINIC,
        'location_event_coordinator' => self::PANEL_CLINIC,
        'lba_operator' => self::PANEL_CLINIC,
        'reviewer' => self::PANEL_CLINIC,
        'consultant' => self::PANEL_CLINIC,
        'commerce_manager' => self::PANEL_CLINIC,
        'tenant_finance' => self::PANEL_CLINIC,
        // Patient / consumer self-service
        'patient_customer' => self::PANEL_PATIENT,
    ];

    /**
     * Fail-closed decision: only an explicitly mapped role may reach its panel.
     */
    public static function roleCanAccessPanel(?string $role, string $panelId): bool
    {
        if ($role === null || $role === '') {
            return false;
        }

        $allowedPanel = self::ROLE_PANEL[$role] ?? null;

        return $allowedPanel !== null && $allowedPanel === $panelId;
    }

    /**
     * @return array<string, string>
     */
    public static function roleMap(): array
    {
        return self::ROLE_PANEL;
    }
}
