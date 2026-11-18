<?php

namespace App\Platform\Access;

/**
 * Canonical permission keys (Phase 1B-2A).
 *
 * Derived from the "Resource permissions" table in
 * docs/ROLE_PERMISSION_MATRIX.md. Keys are grouped by scope:
 *  - `platform.*` / `brand.*` are central governance capabilities;
 *  - the remaining tenant-scoped keys cover clinic operations and clinical
 *    records (see {@see RbacCatalog::clinicalPermissionKeys()});
 *  - patient-scoped keys are self-service only.
 *
 * Authorization is deny-by-default: a key that is not explicitly granted to an
 * active membership role resolves to "denied".
 */
final class PermissionKey
{
    // Platform governance (central).
    public const PLATFORM_BRAND_MANAGE = 'platform.brand.manage';

    public const PLATFORM_TENANT_MANAGE = 'platform.tenant.manage';

    public const PLATFORM_PLAN_MANAGE = 'platform.plan.manage';

    public const PLATFORM_MODULE_MANAGE = 'platform.module.manage';

    public const PLATFORM_ENTITLEMENT_MANAGE = 'platform.entitlement.manage';

    public const PLATFORM_SUBSCRIPTION_VIEW = 'platform.subscription.view';

    public const PLATFORM_BILLING_MANAGE = 'platform.billing.manage';

    public const PLATFORM_PAYMENT_MANAGE = 'platform.payment.manage';

    public const PLATFORM_ANALYTICS_VIEW = 'platform.analytics.view';

    public const PLATFORM_AUDIT_VIEW = 'platform.audit.view';

    public const PLATFORM_RBAC_MANAGE = 'platform.rbac.manage';

    // WS9.D — Principal curation of platform-scope (non-patient) reference
    // knowledge. Deliberately NOT a clinical permission: it grants no access to
    // any patient record, only to general educational reference sources.
    public const PLATFORM_KNOWLEDGE_MANAGE = 'platform.knowledge.manage';

    public const BRAND_METADATA_MANAGE = 'brand.metadata.manage';

    // Tenant operations & clinical records.
    public const TENANT_PROFILE_MANAGE = 'tenant.profile.manage';

    public const TENANT_BILLING_MANAGE = 'tenant.billing.manage';

    public const BRANCH_OPERATIONS_MANAGE = 'branch.operations.manage';

    public const PATIENT_PROFILE_READ = 'patient.profile.read';

    public const PATIENT_PROFILE_MANAGE = 'patient.profile.manage';

    public const EXAM_READ = 'exam.read';

    public const EXAM_CREATE = 'exam.create';

    public const EXAM_UPDATE = 'exam.update';

    public const EXAM_REVIEW = 'exam.review';

    public const EXAM_AI_START = 'exam.ai.start';

    public const ANNOTATION_CREATE = 'annotation.create';

    public const ANNOTATION_CORRECT = 'annotation.correct';

    public const REPORT_APPROVE = 'report.approve';

    public const REPORT_PUBLISH = 'report.publish';

    public const BOOKING_READ = 'booking.read';

    public const BOOKING_MANAGE = 'booking.manage';

    // Practitioner registry & professional authorization (WS7.A).
    public const PRACTITIONER_PROFILE_READ = 'practitioner.profile.read';

    public const PRACTITIONER_PROFILE_MANAGE = 'practitioner.profile.manage';

    public const PRACTITIONER_CREDENTIAL_VERIFY = 'practitioner.credential.verify';

    public const CONSULTATION_MANAGE = 'consultation.manage';

    // WS9.A — manage the tenant's own reference knowledge collections and
    // documents (upload, verify, publish, archive, revoke). Tenant-scoped only:
    // it can never create or touch platform-global content.
    public const KNOWLEDGE_MANAGE = 'knowledge.manage';

    public const COMMERCE_MANAGE = 'commerce.manage';

    public const ORDER_MANAGE = 'order.manage';

    public const WALLET_MANAGE = 'wallet.manage';

    // Patient self-service.
    public const REPORT_READ_PUBLISHED = 'report.read.published';

    public const BOOKING_OWN = 'booking.own';

    public const ORDER_OWN = 'order.own';

    public const WALLET_OWN = 'wallet.own';

    public const CONSULTATION_OWN = 'consultation.own';

    /** @var list<string> */
    public const ALL = [
        self::PLATFORM_BRAND_MANAGE,
        self::PLATFORM_TENANT_MANAGE,
        self::PLATFORM_PLAN_MANAGE,
        self::PLATFORM_MODULE_MANAGE,
        self::PLATFORM_ENTITLEMENT_MANAGE,
        self::PLATFORM_SUBSCRIPTION_VIEW,
        self::PLATFORM_BILLING_MANAGE,
        self::PLATFORM_PAYMENT_MANAGE,
        self::PLATFORM_ANALYTICS_VIEW,
        self::PLATFORM_AUDIT_VIEW,
        self::PLATFORM_RBAC_MANAGE,
        self::PLATFORM_KNOWLEDGE_MANAGE,
        self::BRAND_METADATA_MANAGE,
        self::TENANT_PROFILE_MANAGE,
        self::TENANT_BILLING_MANAGE,
        self::BRANCH_OPERATIONS_MANAGE,
        self::PATIENT_PROFILE_READ,
        self::PATIENT_PROFILE_MANAGE,
        self::EXAM_READ,
        self::EXAM_CREATE,
        self::EXAM_UPDATE,
        self::EXAM_REVIEW,
        self::EXAM_AI_START,
        self::ANNOTATION_CREATE,
        self::ANNOTATION_CORRECT,
        self::REPORT_APPROVE,
        self::REPORT_PUBLISH,
        self::BOOKING_READ,
        self::BOOKING_MANAGE,
        self::PRACTITIONER_PROFILE_READ,
        self::PRACTITIONER_PROFILE_MANAGE,
        self::PRACTITIONER_CREDENTIAL_VERIFY,
        self::CONSULTATION_MANAGE,
        self::KNOWLEDGE_MANAGE,
        self::COMMERCE_MANAGE,
        self::ORDER_MANAGE,
        self::WALLET_MANAGE,
        self::REPORT_READ_PUBLISHED,
        self::BOOKING_OWN,
        self::ORDER_OWN,
        self::WALLET_OWN,
        self::CONSULTATION_OWN,
    ];
}
