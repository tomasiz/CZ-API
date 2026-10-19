<?php

declare(strict_types=1);

namespace Casamiza\Domain\Audit;

/** Actions journalisées. Les valeurs sont des codes `snake_case` : le front les traduit. */
enum AuditAction: string
{
    // Connexions
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case RefreshReuseDetected = 'refresh_reuse_detected';
    case PasswordResetCompleted = 'password_reset_completed';
    case PasswordResetByCli = 'password_reset_by_cli';

    // Équipe
    case MemberInvited = 'member_invited';
    case InvitationResent = 'invitation_resent';
    case MemberActivated = 'member_activated';
    case MemberRoleChanged = 'member_role_changed';
    case MemberRemoved = 'member_removed';

    // Secrets et réglages
    case OwnerStripeKeySet = 'owner_stripe_key_set';
    case OwnerStripeKeyRemoved = 'owner_stripe_key_removed';
    case IntegrationSaved = 'integration_saved';
    case IntegrationRemoved = 'integration_removed';
    case CompanySettingsUpdated = 'company_settings_updated';
    case PropertyOwnerChanged = 'property_owner_changed';

    // Factures
    case InvoiceCreated = 'invoice_created';
    case InvoicePaymentUpdated = 'invoice_payment_updated';
    case InvoiceEmailed = 'invoice_emailed';
    case InvoicesImported = 'invoices_imported';

    public function family(): AuditFamily
    {
        return match ($this) {
            self::LoginSucceeded, self::LoginFailed, self::Logout, self::RefreshReuseDetected,
            self::PasswordResetCompleted, self::PasswordResetByCli => AuditFamily::Access,
            self::MemberInvited, self::InvitationResent, self::MemberActivated,
            self::MemberRoleChanged, self::MemberRemoved => AuditFamily::Team,
            self::OwnerStripeKeySet, self::OwnerStripeKeyRemoved, self::IntegrationSaved,
            self::IntegrationRemoved, self::CompanySettingsUpdated, self::PropertyOwnerChanged => AuditFamily::Settings,
            self::InvoiceCreated, self::InvoicePaymentUpdated, self::InvoiceEmailed,
            self::InvoicesImported => AuditFamily::Invoices,
        };
    }
}
