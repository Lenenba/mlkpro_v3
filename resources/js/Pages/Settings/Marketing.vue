<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import TemplateManager from '@/Pages/Campaigns/Components/TemplateManager.vue';
import SegmentManager from '@/Pages/Campaigns/Components/SegmentManager.vue';

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
});

const offerModeOptions = computed(() => {
    return Array.isArray(props.enums?.offer_modes) ? props.enums.offer_modes : ['PRODUCTS', 'SERVICES', 'MIXED'];
});

const submit = () => {
    form
        .transform((payload) => ({
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
        }))
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
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Marketing configuration</h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            Configure channels, consent, templates, tracking, and offer strategy for campaigns.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                        :disabled="form.processing"
                        @click="submit"
                    >
                        Save configuration
                    </button>
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
                        <input v-model="form.channels.provider.sms_provider" type="text" placeholder="SMS provider" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                        <input v-model="form.channels.provider.sender_id" type="text" placeholder="SMS sender ID" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                        <input v-model="form.channels.provider.email_from_name" type="text" placeholder="Email from name" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                        <input v-model="form.channels.quiet_hours.timezone" type="text" placeholder="Quiet hours timezone" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                        <div class="grid grid-cols-2 gap-2">
                            <input v-model="form.channels.quiet_hours.start" type="time" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            <input v-model="form.channels.quiet_hours.end" type="time" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <input v-model.number="form.channels.anti_fatigue.max_messages_per_window" type="number" min="1" placeholder="Max messages" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            <input v-model.number="form.channels.anti_fatigue.window_days" type="number" min="1" placeholder="Window days" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            <input v-model.number="form.channels.anti_fatigue.same_campaign_cooldown_hours" type="number" min="0" placeholder="Cooldown h" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
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
                        <select v-model="form.consent.default_behavior" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            <option value="deny_without_explicit">deny_without_explicit</option>
                            <option value="allow_without_explicit">allow_without_explicit</option>
                        </select>
                        <input v-model="form.consent.stop_keywords" type="text" placeholder="STOP keywords (comma separated)" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                    </div>

                    <h2 class="mt-4 text-sm font-semibold text-stone-800 dark:text-neutral-100">Audience defaults</h2>
                    <div class="mt-2">
                        <input
                            v-model.number="form.audience.default_exclusions.exclude_contacted_last_days"
                            type="number"
                            min="0"
                            placeholder="Exclude contacted last N days"
                            class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                        >
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
                        <select v-model="form.offers.default_search_filters.status" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            <option value="active">Default search status: active</option>
                            <option value="all">Default search status: all</option>
                        </select>
                        <select v-model="form.offers.selection_strategy" class="w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                            <option value="snapshot_on_save">snapshot_on_save</option>
                        </select>
                        <label class="inline-flex items-center gap-2 text-xs text-stone-600 dark:text-neutral-300">
                            <input v-model="form.templates.allow_campaign_override" type="checkbox" class="rounded border-stone-300 text-green-600 focus:ring-green-600">
                            <span>Allow campaign-level template override</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <TemplateManager :enums="enums" />
            </div>

            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <SegmentManager :segments="[]" />
            </div>
        </section>
    </SettingsLayout>
</template>

