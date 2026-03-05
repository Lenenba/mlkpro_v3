<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TemplateManager from '@/Pages/Campaigns/Components/TemplateManager.vue';
import SegmentManager from '@/Pages/Campaigns/Components/SegmentManager.vue';
import MailingListManager from '@/Pages/Campaigns/Components/MailingListManager.vue';
import VipManager from '@/Pages/Campaigns/Components/VipManager.vue';

const props = defineProps({
    marketingSettings: {
        type: Object,
        default: () => ({}),
    },
    enums: {
        type: Object,
        default: () => ({}),
    },
});

const form = useForm({
    channels: {
        enabled: {
            EMAIL: Boolean(props.marketingSettings?.channels?.enabled?.EMAIL ?? true),
            SMS: Boolean(props.marketingSettings?.channels?.enabled?.SMS ?? true),
            IN_APP: Boolean(props.marketingSettings?.channels?.enabled?.IN_APP ?? true),
        },
        provider: {
            sms_provider: props.marketingSettings?.channels?.provider?.sms_provider || 'twilio',
            sender_id: props.marketingSettings?.channels?.provider?.sender_id || '',
            email_from_name: props.marketingSettings?.channels?.provider?.email_from_name || '',
        },
        quiet_hours: {
            timezone: props.marketingSettings?.channels?.quiet_hours?.timezone || '',
            start: props.marketingSettings?.channels?.quiet_hours?.start || '21:00',
            end: props.marketingSettings?.channels?.quiet_hours?.end || '08:00',
        },
        anti_fatigue: {
            max_messages_per_window: Number(props.marketingSettings?.channels?.anti_fatigue?.max_messages_per_window ?? 2),
            window_days: Number(props.marketingSettings?.channels?.anti_fatigue?.window_days ?? 7),
            same_campaign_cooldown_hours: Number(props.marketingSettings?.channels?.anti_fatigue?.same_campaign_cooldown_hours ?? 48),
            vip_max_messages_per_window: props.marketingSettings?.channels?.anti_fatigue?.vip_max_messages_per_window ?? null,
            vip_window_days: props.marketingSettings?.channels?.anti_fatigue?.vip_window_days ?? null,
        },
    },
    consent: {
        require_explicit: Boolean(props.marketingSettings?.consent?.require_explicit ?? true),
        default_behavior: props.marketingSettings?.consent?.default_behavior || 'deny_without_explicit',
        stop_keywords: Array.isArray(props.marketingSettings?.consent?.stop_keywords)
            ? props.marketingSettings.consent.stop_keywords.join(', ')
            : 'STOP,UNSUBSCRIBE',
    },
    audience: {
        default_exclusions: {
            exclude_contacted_last_days: Number(props.marketingSettings?.audience?.default_exclusions?.exclude_contacted_last_days ?? 0),
        },
    },
    templates: {
        allow_campaign_override: Boolean(props.marketingSettings?.templates?.allow_campaign_override ?? true),
    },
    tracking: {
        click_tracking_enabled: Boolean(props.marketingSettings?.tracking?.click_tracking_enabled ?? true),
        conversion_events: {
            reservation_created: Boolean(props.marketingSettings?.tracking?.conversion_events?.reservation_created ?? true),
            invoice_paid: Boolean(props.marketingSettings?.tracking?.conversion_events?.invoice_paid ?? true),
            quote_accepted: Boolean(props.marketingSettings?.tracking?.conversion_events?.quote_accepted ?? true),
            product_purchase: Boolean(props.marketingSettings?.tracking?.conversion_events?.product_purchase ?? true),
        },
    },
    offers: {
        allowed_modes: Array.isArray(props.marketingSettings?.offers?.allowed_modes)
            ? props.marketingSettings.offers.allowed_modes
            : ['PRODUCTS', 'SERVICES', 'MIXED'],
        default_search_filters: {
            status: props.marketingSettings?.offers?.default_search_filters?.status || 'active',
        },
        selection_strategy: props.marketingSettings?.offers?.selection_strategy || 'snapshot_on_save',
    },
    vip: {
        automation: {
            enabled: Boolean(props.marketingSettings?.vip?.automation?.enabled ?? false),
            evaluation_window_days: String(props.marketingSettings?.vip?.automation?.evaluation_window_days ?? 365),
            minimum_total_spend: props.marketingSettings?.vip?.automation?.minimum_total_spend ?? '',
            minimum_paid_orders: props.marketingSettings?.vip?.automation?.minimum_paid_orders ?? '',
            default_tier_code: props.marketingSettings?.vip?.automation?.default_tier_code || '',
            preserve_existing_tier: Boolean(props.marketingSettings?.vip?.automation?.preserve_existing_tier ?? true),
            downgrade_when_not_eligible: Boolean(props.marketingSettings?.vip?.automation?.downgrade_when_not_eligible ?? false),
            excluded_customer_ids: Array.isArray(props.marketingSettings?.vip?.automation?.excluded_customer_ids)
                ? props.marketingSettings.vip.automation.excluded_customer_ids.join(', ')
                : '',
        },
    },
});

const offerModeOptions = computed(() => {
    return Array.isArray(props.enums?.offer_modes) ? props.enums.offer_modes : ['PRODUCTS', 'SERVICES', 'MIXED'];
});

const consentBehaviorOptions = [
    { value: 'deny_without_explicit', label: 'deny_without_explicit' },
    { value: 'allow_without_explicit', label: 'allow_without_explicit' },
];

const defaultSearchStatusOptions = [
    { value: 'active', label: 'Default search status: active' },
    { value: 'all', label: 'Default search status: all' },
];

const selectionStrategyOptions = [
    { value: 'snapshot_on_save', label: 'snapshot_on_save' },
];

const toNullableNumber = (value) => {
    if (value === '' || value === null || typeof value === 'undefined') {
        return null;
    }

    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
};

const submit = () => {
    form
        .transform((payload) => {
            const evaluationWindowDays = toNullableNumber(payload.vip?.automation?.evaluation_window_days);
            const minimumPaidOrders = toNullableNumber(payload.vip?.automation?.minimum_paid_orders);

            return {
                ...payload,
                consent: {
                    ...payload.consent,
                    stop_keywords: String(payload.consent?.stop_keywords || '')
                        .split(/[,\n;]+/)
                        .map((value) => value.trim())
                        .filter((value) => value !== ''),
                },
                offers: {
                    ...payload.offers,
                    allowed_modes: Array.isArray(payload.offers?.allowed_modes)
                        ? payload.offers.allowed_modes
                        : [],
                },
                vip: {
                    ...payload.vip,
                    automation: {
                        ...payload.vip?.automation,
                        evaluation_window_days: Math.max(1, evaluationWindowDays === null ? 365 : evaluationWindowDays),
                        minimum_total_spend: toNullableNumber(payload.vip?.automation?.minimum_total_spend),
                        minimum_paid_orders: minimumPaidOrders === null ? null : Math.max(0, minimumPaidOrders),
                        default_tier_code: String(payload.vip?.automation?.default_tier_code || '').trim().toUpperCase() || null,
                        excluded_customer_ids: String(payload.vip?.automation?.excluded_customer_ids || '')
                            .split(/[,\n;]+/)
                            .map((value) => Number(value.trim()))
                            .filter((value) => Number.isFinite(value) && value > 0),
                    },
                },
            };
        })
        .put(route('settings.marketing.update'), { preserveScroll: true });
};

const toggleAllowedMode = (mode) => {
    const current = Array.isArray(form.offers.allowed_modes) ? [...form.offers.allowed_modes] : [];
    if (current.includes(mode)) {
        form.offers.allowed_modes = current.filter((item) => item !== mode);
        return;
    }
    form.offers.allowed_modes = [...current, mode];
};
</script>

<template>
    <Head title="Marketing settings" />

    <SettingsLayout active="marketing">
        <section class="space-y-4">
            <div class="rounded-sm border border-stone-200 border-t-4 border-t-green-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="inline-flex items-center gap-2 text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            <svg class="size-5 text-green-600 dark:text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M4 4h16v16H4z" />
                                <path d="M8 9h8" />
                                <path d="M8 13h8" />
                                <path d="M8 17h5" />
                            </svg>
                            <span>Marketing configuration</span>
                        </h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            Configure channels, consent, templates, tracking, and offer strategy for campaigns.
                        </p>
                    </div>
                    <PrimaryButton type="button" :disabled="form.processing" @click="submit">
                        Save configuration
                    </PrimaryButton>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Channels settings</h2>
                    <div class="mt-3 grid grid-cols-1 gap-2">
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.channels.enabled.EMAIL" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                            <span>Enable EMAIL</span>
                        </label>
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.channels.enabled.SMS" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                            <span>Enable SMS</span>
                        </label>
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.channels.enabled.IN_APP" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                            <span>Enable IN_APP</span>
                        </label>
                        <FloatingInput v-model="form.channels.provider.sms_provider" label="SMS provider" />
                        <FloatingInput v-model="form.channels.provider.sender_id" label="SMS sender ID" />
                        <FloatingInput v-model="form.channels.provider.email_from_name" label="Email from name" />
                        <FloatingInput v-model="form.channels.quiet_hours.timezone" label="Quiet hours timezone" />
                        <div class="grid grid-cols-2 gap-2">
                            <FloatingInput v-model="form.channels.quiet_hours.start" type="time" label="Quiet start" />
                            <FloatingInput v-model="form.channels.quiet_hours.end" type="time" label="Quiet end" />
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <FloatingInput v-model.number="form.channels.anti_fatigue.max_messages_per_window" type="number" label="Max messages" />
                            <FloatingInput v-model.number="form.channels.anti_fatigue.window_days" type="number" label="Window days" />
                            <FloatingInput v-model.number="form.channels.anti_fatigue.same_campaign_cooldown_hours" type="number" label="Cooldown h" />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <FloatingInput v-model.number="form.channels.anti_fatigue.vip_max_messages_per_window" type="number" label="VIP max messages (optional)" />
                            <FloatingInput v-model.number="form.channels.anti_fatigue.vip_window_days" type="number" label="VIP window days (optional)" />
                        </div>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Consent settings</h2>
                    <div class="mt-3 grid grid-cols-1 gap-2">
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.consent.require_explicit" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                            <span>Require explicit consent</span>
                        </label>
                        <FloatingSelect
                            v-model="form.consent.default_behavior"
                            label="Default behavior"
                            :options="consentBehaviorOptions"
                            option-value="value"
                            option-label="label"
                        />
                        <FloatingInput v-model="form.consent.stop_keywords" label="STOP keywords (comma separated)" />
                    </div>

                    <h2 class="mt-4 text-sm font-semibold text-stone-800 dark:text-neutral-100">Audience defaults</h2>
                    <div class="mt-2">
                        <FloatingInput
                            v-model.number="form.audience.default_exclusions.exclude_contacted_last_days"
                            type="number"
                            label="Exclude contacted last N days"
                        />
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Tracking settings</h2>
                    <div class="mt-3 space-y-2">
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.tracking.click_tracking_enabled" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                            <span>Enable click tracking</span>
                        </label>
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.tracking.conversion_events.reservation_created" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                            <span>reservation_created</span>
                        </label>
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.tracking.conversion_events.invoice_paid" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                            <span>invoice_paid</span>
                        </label>
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.tracking.conversion_events.quote_accepted" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                            <span>quote_accepted</span>
                        </label>
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.tracking.conversion_events.product_purchase" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                            <span>product_purchase</span>
                        </label>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Offer settings</h2>
                    <div class="mt-3 space-y-2">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">Allowed offer modes</div>
                        <div class="flex flex-wrap gap-2">
                            <label
                                v-for="mode in offerModeOptions"
                                :key="`mode-${mode}`"
                                class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-stone-50 px-2 py-1 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                            >
                                <input
                                    :checked="form.offers.allowed_modes.includes(mode)"
                                    type="checkbox"
                                    class="rounded border-stone-300 text-green-600 focus:ring-green-600"
                                    @change="toggleAllowedMode(mode)"
                                >
                                <span>{{ mode }}</span>
                            </label>
                        </div>
                        <FloatingSelect
                            v-model="form.offers.default_search_filters.status"
                            label="Default search status"
                            :options="defaultSearchStatusOptions"
                            option-value="value"
                            option-label="label"
                        />
                        <FloatingSelect
                            v-model="form.offers.selection_strategy"
                            label="Selection strategy"
                            :options="selectionStrategyOptions"
                            option-value="value"
                            option-label="label"
                        />
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.templates.allow_campaign_override" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                            <span>Allow campaign-level template override</span>
                        </label>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">VIP automation</h2>
                    <div class="mt-3 grid grid-cols-1 gap-2">
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.vip.automation.enabled" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                            <span>Enable automatic VIP assignment from paid purchases</span>
                        </label>

                        <div class="grid grid-cols-3 gap-2">
                            <FloatingInput
                                v-model="form.vip.automation.evaluation_window_days"
                                type="number"
                                label="Evaluation window (days)"
                            />
                            <FloatingInput
                                v-model="form.vip.automation.minimum_total_spend"
                                type="number"
                                label="Minimum total spend"
                            />
                            <FloatingInput
                                v-model="form.vip.automation.minimum_paid_orders"
                                type="number"
                                label="Minimum paid orders"
                            />
                        </div>

                        <FloatingInput
                            v-model="form.vip.automation.default_tier_code"
                            label="Default tier code (optional)"
                        />

                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.vip.automation.preserve_existing_tier" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                            <span>Preserve existing tier for customers already VIP</span>
                        </label>
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.vip.automation.downgrade_when_not_eligible" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                            <span>Downgrade customers when thresholds are no longer met</span>
                        </label>

                        <FloatingInput
                            v-model="form.vip.automation.excluded_customer_ids"
                            label="Excluded customer IDs (comma separated)"
                        />
                    </div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <TemplateManager :enums="enums" />
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <SegmentManager :segments="[]" />
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <MailingListManager />
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <VipManager />
            </div>
        </section>
    </SettingsLayout>
</template>
