<?php

namespace App\Platform\Access;

use Database\Seeders\RbacSeeder;

/**
 * Phase 1B-2A authorization catalog.
 *
 * The single, human-auditable source of truth for:
 *  - which panel a role belongs to and whether it is machine-only;
 *  - which permissions each role receives (docs/ROLE_PERMISSION_MATRIX.md);
 *  - the scope of every permission, which decides which assignment source may
 *    ever grant it.
 *
 * {@see RbacSeeder} materialises this catalog into the central
 * `roles`, `permissions` and `role_permissions` tables, and the authorization
 * services resolve decisions from those tables — never from this class directly —
 * so the database stays authoritative at request time.
 *
 * Separation of duties encoded here:
 *  - `lba_operator` never receives `report.approve`, so an operator cannot
 *    self-approve an LBA report (DECISIONS.md LBA-02).
 *  - `ai_service` is machine-only and receives no permissions at all.
 *  - principal/platform roles receive no clinical permissions.
 */
final class RbacCatalog
{
    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_TENANT = 'tenant';

    public const SCOPE_PATIENT = 'patient';

    public const SCOPE_MACHINE = 'machine';

    /**
     * Permission scope lookup, keyed by canonical permission key.
     *
     * @return array<string, string>
     */
    public static function permissionScopes(): array
    {
        $platform = [
            PermissionKey::PLATFORM_BRAND_MANAGE,
            PermissionKey::PLATFORM_TENANT_MANAGE,
            PermissionKey::PLATFORM_PLAN_MANAGE,
            PermissionKey::PLATFORM_MODULE_MANAGE,
            PermissionKey::PLATFORM_ENTITLEMENT_MANAGE,
            PermissionKey::PLATFORM_SUBSCRIPTION_VIEW,
            PermissionKey::PLATFORM_BILLING_MANAGE,
            PermissionKey::PLATFORM_PAYMENT_MANAGE,
            PermissionKey::PLATFORM_ANALYTICS_VIEW,
            PermissionKey::PLATFORM_AUDIT_VIEW,
            PermissionKey::PLATFORM_RBAC_MANAGE,
            PermissionKey::PLATFORM_KNOWLEDGE_MANAGE,
            PermissionKey::BRAND_METADATA_MANAGE,
        ];

        $patient = [
            PermissionKey::REPORT_READ_PUBLISHED,
            PermissionKey::BOOKING_OWN,
            PermissionKey::ORDER_OWN,
            PermissionKey::WALLET_OWN,
            PermissionKey::CONSULTATION_OWN,
        ];

        $scopes = [];

        foreach ($platform as $key) {
            $scopes[$key] = self::SCOPE_PLATFORM;
        }

        foreach ($patient as $key) {
            $scopes[$key] = self::SCOPE_PATIENT;
        }

        foreach (PermissionKey::ALL as $key) {
            $scopes[$key] ??= self::SCOPE_TENANT;
        }

        return $scopes;
    }

    public static function permissionScope(string $permissionKey): ?string
    {
        return self::permissionScopes()[$permissionKey] ?? null;
    }

    /**
     * Clinical / patient-record permissions a principal must never obtain by
     * virtue of a platform role alone.
     *
     * @return list<string>
     */
    public static function clinicalPermissionKeys(): array
    {
        return [
            PermissionKey::PATIENT_PROFILE_READ,
            PermissionKey::PATIENT_PROFILE_MANAGE,
            PermissionKey::EXAM_READ,
            PermissionKey::EXAM_CREATE,
            PermissionKey::EXAM_UPDATE,
            PermissionKey::EXAM_REVIEW,
            PermissionKey::EXAM_AI_START,
            PermissionKey::ANNOTATION_CREATE,
            PermissionKey::ANNOTATION_CORRECT,
            PermissionKey::REPORT_APPROVE,
            PermissionKey::REPORT_PUBLISH,
            PermissionKey::CONSULTATION_MANAGE,
        ];
    }

    /**
     * Role scope, keyed by canonical role key.
     *
     * @return array<string, string>
     */
    public static function roleScopes(): array
    {
        $scopes = [];

        foreach (RoleKey::PRINCIPAL_ROLES as $key) {
            $scopes[$key] = self::SCOPE_PLATFORM;
        }

        foreach (RoleKey::TENANT_ROLES as $key) {
            $scopes[$key] = self::SCOPE_TENANT;
        }

        $scopes[RoleKey::PATIENT_CUSTOMER] = self::SCOPE_PATIENT;
        $scopes[RoleKey::AI_SERVICE] = self::SCOPE_MACHINE;

        return $scopes;
    }

    public static function roleScope(string $roleKey): ?string
    {
        return self::roleScopes()[$roleKey] ?? null;
    }

    public static function isMachineRole(string $roleKey): bool
    {
        return self::roleScope($roleKey) === self::SCOPE_MACHINE;
    }

    /**
     * Roles that may be assigned through a tenant membership.
     *
     * @return list<string>
     */
    public static function membershipAssignableRoles(): array
    {
        return [
            ...RoleKey::TENANT_ROLES,
            RoleKey::PATIENT_CUSTOMER,
        ];
    }

    /**
     * Roles that may be assigned through a principal/platform assignment.
     *
     * @return list<string>
     */
    public static function principalAssignableRoles(): array
    {
        return RoleKey::PRINCIPAL_ROLES;
    }

    /**
     * True only for platform roles that may back a principal assignment.
     */
    public static function isPrincipalAssignableRole(string $roleKey): bool
    {
        return in_array($roleKey, self::principalAssignableRoles(), true);
    }

    /**
     * True only for tenant/patient roles that may back a tenant membership.
     * Machine-only and platform roles are excluded.
     */
    public static function isMembershipAssignableRole(string $roleKey): bool
    {
        return in_array($roleKey, self::membershipAssignableRoles(), true);
    }

    /**
     * Role -> permission keys, keyed by canonical role key.
     *
     * @return array<string, list<string>>
     */
    public static function rolePermissions(): array
    {
        return [
            RoleKey::PRINCIPAL_SUPER_ADMIN => [
                PermissionKey::PLATFORM_BRAND_MANAGE,
                PermissionKey::PLATFORM_TENANT_MANAGE,
                PermissionKey::PLATFORM_PLAN_MANAGE,
                PermissionKey::PLATFORM_MODULE_MANAGE,
                PermissionKey::PLATFORM_ENTITLEMENT_MANAGE,
                PermissionKey::PLATFORM_SUBSCRIPTION_VIEW,
                PermissionKey::PLATFORM_BILLING_MANAGE,
                PermissionKey::PLATFORM_PAYMENT_MANAGE,
                PermissionKey::PLATFORM_ANALYTICS_VIEW,
                PermissionKey::PLATFORM_AUDIT_VIEW,
                PermissionKey::PLATFORM_RBAC_MANAGE,
                PermissionKey::PLATFORM_KNOWLEDGE_MANAGE,
                PermissionKey::BRAND_METADATA_MANAGE,
            ],
            RoleKey::PRINCIPAL_MODULE_ADMIN => [
                PermissionKey::PLATFORM_MODULE_MANAGE,
                PermissionKey::PLATFORM_ENTITLEMENT_MANAGE,
                PermissionKey::PLATFORM_ANALYTICS_VIEW,
                PermissionKey::PLATFORM_AUDIT_VIEW,
                PermissionKey::PLATFORM_KNOWLEDGE_MANAGE,
            ],
            RoleKey::PRINCIPAL_FINANCE => [
                PermissionKey::PLATFORM_SUBSCRIPTION_VIEW,
                PermissionKey::PLATFORM_BILLING_MANAGE,
                PermissionKey::PLATFORM_PAYMENT_MANAGE,
                PermissionKey::PLATFORM_ANALYTICS_VIEW,
            ],
            RoleKey::BRAND_MANAGER => [
                PermissionKey::BRAND_METADATA_MANAGE,
            ],

            RoleKey::TENANT_OWNER => [
                PermissionKey::TENANT_PROFILE_MANAGE,
                PermissionKey::TENANT_BILLING_MANAGE,
                PermissionKey::BRANCH_OPERATIONS_MANAGE,
                PermissionKey::PATIENT_PROFILE_READ,
                PermissionKey::PATIENT_PROFILE_MANAGE,
                PermissionKey::EXAM_READ,
                PermissionKey::EXAM_CREATE,
                PermissionKey::EXAM_UPDATE,
                PermissionKey::EXAM_AI_START,
                PermissionKey::ANNOTATION_CREATE,
                PermissionKey::ANNOTATION_CORRECT,
                PermissionKey::BOOKING_READ,
                PermissionKey::BOOKING_MANAGE,
                PermissionKey::PRACTITIONER_PROFILE_READ,
                PermissionKey::PRACTITIONER_PROFILE_MANAGE,
                PermissionKey::PRACTITIONER_CREDENTIAL_VERIFY,
                PermissionKey::CONSULTATION_MANAGE,
                PermissionKey::KNOWLEDGE_MANAGE,
                PermissionKey::COMMERCE_MANAGE,
                PermissionKey::ORDER_MANAGE,
            ],
            RoleKey::TENANT_ADMIN => [
                PermissionKey::TENANT_PROFILE_MANAGE,
                PermissionKey::BRANCH_OPERATIONS_MANAGE,
                PermissionKey::PATIENT_PROFILE_READ,
                PermissionKey::PATIENT_PROFILE_MANAGE,
                PermissionKey::EXAM_READ,
                PermissionKey::EXAM_CREATE,
                PermissionKey::EXAM_UPDATE,
                PermissionKey::EXAM_AI_START,
                PermissionKey::ANNOTATION_CREATE,
                PermissionKey::ANNOTATION_CORRECT,
                PermissionKey::BOOKING_READ,
                PermissionKey::BOOKING_MANAGE,
                PermissionKey::PRACTITIONER_PROFILE_READ,
                PermissionKey::PRACTITIONER_PROFILE_MANAGE,
                PermissionKey::PRACTITIONER_CREDENTIAL_VERIFY,
                PermissionKey::CONSULTATION_MANAGE,
                PermissionKey::KNOWLEDGE_MANAGE,
                PermissionKey::COMMERCE_MANAGE,
                PermissionKey::ORDER_MANAGE,
            ],
            RoleKey::BRANCH_MANAGER => [
                PermissionKey::BRANCH_OPERATIONS_MANAGE,
                PermissionKey::PATIENT_PROFILE_READ,
                PermissionKey::EXAM_READ,
                PermissionKey::EXAM_CREATE,
                PermissionKey::EXAM_UPDATE,
                PermissionKey::ANNOTATION_CREATE,
                PermissionKey::BOOKING_READ,
                PermissionKey::BOOKING_MANAGE,
                PermissionKey::PRACTITIONER_PROFILE_READ,
            ],
            RoleKey::RECEPTIONIST => [
                PermissionKey::PATIENT_PROFILE_READ,
                PermissionKey::PATIENT_PROFILE_MANAGE,
                PermissionKey::BOOKING_READ,
                PermissionKey::BOOKING_MANAGE,
                PermissionKey::ORDER_MANAGE,
            ],
            RoleKey::SCHEDULER => [
                PermissionKey::PATIENT_PROFILE_READ,
                PermissionKey::BOOKING_READ,
                PermissionKey::BOOKING_MANAGE,
            ],
            RoleKey::LOCATION_EVENT_COORDINATOR => [
                PermissionKey::BOOKING_READ,
                PermissionKey::BOOKING_MANAGE,
            ],
            RoleKey::LBA_OPERATOR => [
                PermissionKey::PATIENT_PROFILE_READ,
                PermissionKey::EXAM_READ,
                PermissionKey::EXAM_CREATE,
                PermissionKey::EXAM_UPDATE,
                PermissionKey::EXAM_AI_START,
                PermissionKey::ANNOTATION_CREATE,
            ],
            RoleKey::REVIEWER => [
                PermissionKey::PATIENT_PROFILE_READ,
                PermissionKey::EXAM_READ,
                PermissionKey::EXAM_REVIEW,
                PermissionKey::ANNOTATION_CORRECT,
                PermissionKey::REPORT_APPROVE,
                PermissionKey::REPORT_PUBLISH,
                PermissionKey::KNOWLEDGE_MANAGE,
            ],
            RoleKey::CONSULTANT => [
                PermissionKey::PATIENT_PROFILE_READ,
                PermissionKey::EXAM_READ,
                PermissionKey::CONSULTATION_MANAGE,
                PermissionKey::KNOWLEDGE_MANAGE,
            ],
            RoleKey::COMMERCE_MANAGER => [
                PermissionKey::BOOKING_READ,
                PermissionKey::COMMERCE_MANAGE,
                PermissionKey::ORDER_MANAGE,
            ],
            RoleKey::TENANT_FINANCE => [
                PermissionKey::TENANT_BILLING_MANAGE,
                PermissionKey::WALLET_MANAGE,
                PermissionKey::ORDER_MANAGE,
                PermissionKey::BOOKING_READ,
            ],

            RoleKey::PATIENT_CUSTOMER => [
                PermissionKey::REPORT_READ_PUBLISHED,
                PermissionKey::BOOKING_OWN,
                PermissionKey::ORDER_OWN,
                PermissionKey::WALLET_OWN,
                PermissionKey::CONSULTATION_OWN,
            ],

            // Machine-only identity: no human permissions.
            RoleKey::AI_SERVICE => [],
        ];
    }

    /**
     * @return list<string>
     */
    public static function permissionsFor(string $roleKey): array
    {
        return self::rolePermissions()[$roleKey] ?? [];
    }

    public static function roleName(string $roleKey): string
    {
        return ucwords(str_replace('_', ' ', $roleKey));
    }
}
