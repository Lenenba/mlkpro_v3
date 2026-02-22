<script setup>
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    loyaltyProgram: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const form = useForm({
    is_enabled: Boolean(props.loyaltyProgram?.is_enabled ?? true),
    points_per_currency_unit: String(props.loyaltyProgram?.points_per_currency_unit ?? 1),
    minimum_spend: String(props.loyaltyProgram?.minimum_spend ?? 0),
    rounding_mode: props.loyaltyProgram?.rounding_mode || 'floor',
    points_label: props.loyaltyProgram?.points_label || 'points',
});

const roundingOptions = computed(() => ([
    { id: 'floor', name: t('settings.loyalty.rounding_modes.floor') },
    { id: 'round', name: t('settings.loyalty.rounding_modes.round') },
    { id: 'ceil', name: t('settings.loyalty.rounding_modes.ceil') },
]));

const pointLabelPreview = computed(() => {
    const label = String(form.points_label || '').trim();
    return label || 'points';
});

const submit = () => {
    form
        .transform((payload) => ({
            is_enabled: Boolean(payload.is_enabled),
            points_per_currency_unit: payload.points_per_currency_unit === ''
                ? null
                : Number(payload.points_per_currency_unit),
            minimum_spend: payload.minimum_spend === ''
                ? null
                : Number(payload.minimum_spend),
            rounding_mode: payload.rounding_mode || 'floor',
            points_label: String(payload.points_label || '').trim(),
        }))
        .put(route('settings.loyalty.update'), { preserveScroll: true });
};

watch(
    () => form.points_per_currency_unit,
    (value) => {
        if (value === '' || value === null || value === undefined) {
            return;
        }

        const numeric = Number(value);
        if (Number.isNaN(numeric)) {
            return;
        }

        if (numeric < 0.0001) {
            form.points_per_currency_unit = '0.0001';
        }
    }
);

watch(
    () => form.minimum_spend,
    (value) => {
        if (value === '' || value === null || value === undefined) {
            return;
        }

        const numeric = Number(value);
        if (Number.isNaN(numeric)) {
            return;
        }

        if (numeric < 0) {
            form.minimum_spend = '0';
        }
    }
);

watch(
    () => form.rounding_mode,
    (value) => {
        if (!['floor', 'round', 'ceil'].includes(value)) {
            form.rounding_mode = 'floor';
        }
    }
);
</script>

<template>
    <Head :title="$t('settings.loyalty.meta_title')" />

    <SettingsLayout active="loyalty">
        <section class="space-y-4 loyalty-settings-enter">
            <div class="rounded-sm border border-stone-200 border-t-4 border-t-amber-500 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="space-y-1">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ $t('settings.loyalty.title') }}</h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">{{ $t('settings.loyalty.subtitle') }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link
                            :href="route('loyalty.index')"
                            class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                        >
                            {{ $t('loyalty_module.title') }}
                        </Link>
                        <button
                            type="button"
                            class="inline-flex items-center rounded-sm border border-transparent bg-amber-500 px-3 py-2 text-xs font-medium text-white hover:bg-amber-600 disabled:opacity-60"
                            :disabled="form.processing"
                            @click="submit"
                        >
                            {{ $t('settings.loyalty.save') }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr),340px]">
                <div class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="md:col-span-2 rounded-sm border border-stone-200 bg-stone-50/80 px-4 py-3 dark:border-neutral-700 dark:bg-neutral-800/70">
                            <label class="inline-flex items-center gap-2 text-sm font-medium text-stone-700 dark:text-neutral-200">
                                <input
                                    v-model="form.is_enabled"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-stone-300 text-amber-500 focus:ring-amber-500"
                                />
                                <span>{{ $t('settings.loyalty.fields.enabled') }}</span>
                            </label>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ form.is_enabled ? $t('settings.loyalty.status.enabled') : $t('settings.loyalty.status.disabled') }}
                            </p>
                            <InputError class="mt-1" :message="form.errors.is_enabled" />
                        </div>

                        <div>
                            <FloatingInput
                                v-model="form.points_per_currency_unit"
                                type="number"
                                step="0.0001"
                                min="0.0001"
                                :label="$t('settings.loyalty.fields.points_per_currency_unit')"
                            />
                            <InputError class="mt-1" :message="form.errors.points_per_currency_unit" />
                        </div>

                        <div>
                            <FloatingInput
                                v-model="form.minimum_spend"
                                type="number"
                                step="0.01"
                                min="0"
                                :label="$t('settings.loyalty.fields.minimum_spend')"
                            />
                            <InputError class="mt-1" :message="form.errors.minimum_spend" />
                        </div>

                        <div>
                            <FloatingSelect
                                v-model="form.rounding_mode"
                                :options="roundingOptions"
                                :label="$t('settings.loyalty.fields.rounding_mode')"
                            />
                            <InputError class="mt-1" :message="form.errors.rounding_mode" />
                        </div>

                        <div>
                            <FloatingInput
                                v-model="form.points_label"
                                :label="$t('settings.loyalty.fields.points_label')"
                            />
                            <InputError class="mt-1" :message="form.errors.points_label" />
                        </div>
                    </div>
                </div>

                <aside class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('settings.loyalty.preview_title') }}</h2>
                    <div class="mt-3 space-y-3 text-sm text-stone-700 dark:text-neutral-300">
                        <div class="rounded-sm border border-amber-200 bg-amber-50/80 px-3 py-2 dark:border-amber-500/20 dark:bg-amber-500/10">
                            {{ $t('settings.loyalty.preview', { label: pointLabelPreview }) }}
                        </div>
                        <div class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('settings.loyalty.fields.points_per_currency_unit') }}</div>
                            <div class="font-semibold text-stone-800 dark:text-neutral-100">{{ form.points_per_currency_unit || '0' }}</div>
                        </div>
                        <div class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('settings.loyalty.fields.minimum_spend') }}</div>
                            <div class="font-semibold text-stone-800 dark:text-neutral-100">{{ form.minimum_spend || '0' }}</div>
                        </div>
                        <div class="rounded-sm border border-stone-200 px-3 py-2 dark:border-neutral-700">
                            <div class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('settings.loyalty.fields.rounding_mode') }}</div>
                            <div class="font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t(`settings.loyalty.rounding_modes.${form.rounding_mode || 'floor'}`) }}
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </section>
    </SettingsLayout>
</template>

<style scoped>
.loyalty-settings-enter {
    animation: loyaltyFadeUp 260ms ease-out both;
}

@keyframes loyaltyFadeUp {
    from {
        opacity: 0;
        transform: translateY(8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .loyalty-settings-enter {
        animation: none;
    }
}
</style>
