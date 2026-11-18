<?php

namespace App\Platform\Access;

/**
 * WS6.E — the FIVE understandable user categories shown in the user-management
 * and onboarding screens (docs/WS6_ACCESS_DEPLOY_ADDENDUM.md section B).
 *
 * This is a DISPLAY / ONBOARDING layer only. It never replaces the canonical
 * {@see RoleKey} / {@see RbacCatalog} permission architecture: every category
 * simply groups the EXISTING roles so operators choose an understandable group
 * while the underlying granular permissions stay exactly as they are. No new
 * role or permission is introduced here.
 *
 * The five categories and the safety boundary each one carries:
 *  1. Super Admin Principal — platform governance only, NOT blanket clinical
 *     patient-record access.
 *  2. Clinic Admin — the operator's own tenant and its permitted branches.
 *  3. Doctor / Medical Staff — clinical work only when the individual
 *     capabilities are separately, independently assigned (no self-approval).
 *  4. Staff (marketing / commerce / reception / …) — operational access with no
 *     implicit clinical-review, clinical-record or cross-tenant permission.
 *  5. Patient — only the patient record linked to their own account.
 *
 * `ai_service` is machine-only and is deliberately not part of any human
 * category.
 */
final class UserCategory
{
    public const SUPER_ADMIN_PRINCIPAL = 'super_admin_principal';

    public const CLINIC_ADMIN = 'clinic_admin';

    public const MEDICAL_STAFF = 'medical_staff';

    public const STAFF = 'staff';

    public const PATIENT = 'patient';

    /** @var list<string> */
    public const ALL = [
        self::SUPER_ADMIN_PRINCIPAL,
        self::CLINIC_ADMIN,
        self::MEDICAL_STAFF,
        self::STAFF,
        self::PATIENT,
    ];

    /**
     * Every human role mapped to exactly one category. Roles absent here are
     * machine-only and never appear in a user-management screen.
     *
     * @var array<string, string>
     */
    private const ROLE_CATEGORY = [
        // 1. Super Admin Principal — all platform-governance identities.
        RoleKey::PRINCIPAL_SUPER_ADMIN => self::SUPER_ADMIN_PRINCIPAL,
        RoleKey::PRINCIPAL_MODULE_ADMIN => self::SUPER_ADMIN_PRINCIPAL,
        RoleKey::PRINCIPAL_FINANCE => self::SUPER_ADMIN_PRINCIPAL,
        RoleKey::BRAND_MANAGER => self::SUPER_ADMIN_PRINCIPAL,

        // 2. Clinic Admin — the tenant operator and its administrators.
        RoleKey::TENANT_OWNER => self::CLINIC_ADMIN,
        RoleKey::TENANT_ADMIN => self::CLINIC_ADMIN,

        // 3. Doctor / Medical Staff — a profession; each clinical capability is a
        //    separate role assigned only after proper authorization.
        RoleKey::CONSULTANT => self::MEDICAL_STAFF,
        RoleKey::LBA_OPERATOR => self::MEDICAL_STAFF,
        RoleKey::REVIEWER => self::MEDICAL_STAFF,

        // 4. Staff — marketing / commerce / reception and other operational roles.
        RoleKey::BRANCH_MANAGER => self::STAFF,
        RoleKey::RECEPTIONIST => self::STAFF,
        RoleKey::SCHEDULER => self::STAFF,
        RoleKey::LOCATION_EVENT_COORDINATOR => self::STAFF,
        RoleKey::COMMERCE_MANAGER => self::STAFF,
        RoleKey::TENANT_FINANCE => self::STAFF,

        // 5. Patient — own linked patient record only.
        RoleKey::PATIENT_CUSTOMER => self::PATIENT,
    ];

    /**
     * Panel each category routes to (see {@see PanelAccessPolicy}).
     *
     * @var array<string, string>
     */
    private const CATEGORY_PANEL = [
        self::SUPER_ADMIN_PRINCIPAL => PanelAccessPolicy::PANEL_PRINCIPAL,
        self::CLINIC_ADMIN => PanelAccessPolicy::PANEL_CLINIC,
        self::MEDICAL_STAFF => PanelAccessPolicy::PANEL_CLINIC,
        self::STAFF => PanelAccessPolicy::PANEL_CLINIC,
        self::PATIENT => PanelAccessPolicy::PANEL_PATIENT,
    ];

    /**
     * Categories a Clinic administrator manages (never platform provisioning).
     *
     * @var list<string>
     */
    public const CLINIC_CATEGORIES = [
        self::CLINIC_ADMIN,
        self::MEDICAL_STAFF,
        self::STAFF,
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return self::ALL;
    }

    /**
     * The category a role belongs to, or null for an unknown / machine role.
     */
    public static function categoryForRole(?string $roleKey): ?string
    {
        if ($roleKey === null) {
            return null;
        }

        return self::ROLE_CATEGORY[$roleKey] ?? null;
    }

    /**
     * Every role key mapped to the given category, in catalog order.
     *
     * @return list<string>
     */
    public static function roleKeys(string $category): array
    {
        return array_keys(array_filter(
            self::ROLE_CATEGORY,
            static fn (string $mapped): bool => $mapped === $category,
        ));
    }

    public static function panel(string $category): ?string
    {
        return self::CATEGORY_PANEL[$category] ?? null;
    }

    public static function label(string $category): string
    {
        return __("lba.user_categories.{$category}.label");
    }

    public static function description(string $category): string
    {
        return __("lba.user_categories.{$category}.description");
    }

    public static function safety(string $category): string
    {
        return __("lba.user_categories.{$category}.safety");
    }

    /**
     * The distinct categories a set of role keys belongs to, in catalog order.
     *
     * @param  iterable<string>  $roleKeys
     * @return list<string>
     */
    public static function categoriesForRoles(iterable $roleKeys): array
    {
        $present = [];

        foreach ($roleKeys as $roleKey) {
            $category = self::categoryForRole((string) $roleKey);

            if ($category !== null) {
                $present[$category] = true;
            }
        }

        return array_values(array_filter(
            self::ALL,
            static fn (string $category): bool => isset($present[$category]),
        ));
    }

    /**
     * A category label string for a set of roles, e.g. "Doctor / Medical Staff,
     * Staff". Used as a table cell / summary.
     *
     * @param  iterable<string>  $roleKeys
     */
    public static function summaryForRoles(iterable $roleKeys): string
    {
        $labels = array_map(
            self::label(...),
            self::categoriesForRoles($roleKeys),
        );

        return implode(', ', $labels);
    }

    /**
     * Filament grouped-option array (category label => role key => option label)
     * for the given role keys, restricted to categories that actually contain
     * one of them. Each option label names the subrole AND its granted
     * permissions, so onboarding never presents an ambiguous catchall grant.
     *
     * @param  list<string>  $roleKeys
     * @return array<string, array<string, string>>
     */
    public static function groupedRoleOptions(array $roleKeys): array
    {
        $groups = [];

        foreach (self::ALL as $category) {
            $inCategory = array_values(array_filter(
                $roleKeys,
                static fn (string $roleKey): bool => self::categoryForRole($roleKey) === $category,
            ));

            if ($inCategory === []) {
                continue;
            }

            $options = [];

            foreach ($inCategory as $roleKey) {
                $options[$roleKey] = self::roleOptionLabel($roleKey);
            }

            $groups[self::label($category)] = $options;
        }

        return $groups;
    }

    /**
     * "Consultant — Read Patient Profile, Read Examinations, Manage
     * Consultations" — the subrole plus the granular permissions it grants.
     */
    public static function roleOptionLabel(string $roleKey): string
    {
        $role = __("lba.roles.{$roleKey}");
        $permissions = array_map(
            static fn (string $permission): string => __("lba.permissions.{$permission}"),
            RbacCatalog::permissionsFor($roleKey),
        );

        if ($permissions === []) {
            return $role;
        }

        return $role.' — '.implode(', ', $permissions);
    }

    /**
     * Grouped options for staff invitations: only the clinic-side categories
     * (Clinic Admin, Doctor / Medical Staff, Staff) — a patient is never
     * onboarded through a staff invitation.
     *
     * @return array<string, array<string, string>>
     */
    public static function staffInvitationGroups(): array
    {
        return self::groupedRoleOptions(RoleKey::TENANT_ROLES);
    }
}
