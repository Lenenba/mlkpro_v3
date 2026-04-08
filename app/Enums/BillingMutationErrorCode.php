<?php

namespace App\Enums;

enum BillingMutationErrorCode: string
{
    case ProviderNotStripe = 'billing_provider_not_stripe';
    case ProviderNotPaddle = 'billing_provider_not_paddle';
    case ProviderNotConfigured = 'billing_provider_not_configured';
    case PortalUnavailable = 'billing_portal_unavailable';
    case SubscriptionRequired = 'billing_subscription_required';
    case PlansNotConfigured = 'billing_plans_not_configured';
    case InvalidPlanSelection = 'billing_invalid_plan_selection';
    case PlanUnchanged = 'billing_plan_unchanged';
    case PlanRestricted = 'billing_plan_restricted';
    case MutationFailed = 'billing_mutation_failed';
    case PaymentMethodUpdateFailed = 'billing_payment_method_update_failed';
    case InvalidProviderResponse = 'billing_invalid_provider_response';
    case AssistantUnavailableForProvider = 'assistant_unavailable_for_provider';
    case AssistantAlreadyIncluded = 'assistant_already_included';
    case AssistantNotConfigured = 'assistant_not_configured';
    case AssistantActivationRequired = 'assistant_activation_required';
    case AssistantCreditPriceMissing = 'assistant_credit_price_missing';
    case AssistantCreditPackMissing = 'assistant_credit_pack_missing';
    case AssistantCheckoutFailed = 'assistant_checkout_failed';
    case AssistantAddonUpdateFailed = 'assistant_addon_update_failed';
    case StripeNotConfigured = 'stripe_not_configured';
    case StripeConnectNotConfigured = 'stripe_connect_not_configured';
    case StripeConnectOnboardingFailed = 'stripe_connect_onboarding_failed';
}
