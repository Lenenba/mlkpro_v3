<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    alert: {
        type: Object,
        default: null,
    },
    canContinue: {
        type: Boolean,
        default: false,
    },
    continueLabel: {
        type: String,
        default: null,
    },
});

defineEmits(['continue']);

const { t } = useI18n();

const entries = computed(() => (
    Array.isArray(props.alert?.entries)
        ? props.alert.entries
        : []
));

const formatDate = (value) => humanizeDate(value);

const statusLabel = (status) => {
    switch (status) {
        case 'REQ_NEW':
            return t('requests.status.new');
        case 'REQ_CALL_REQUESTED':
            return t('requests.status.call_requested');
        case 'REQ_CONTACTED':
            return t('requests.status.contacted');
        case 'REQ_QUALIFIED':
            return t('requests.status.qualified');
        case 'REQ_QUOTE_SENT':
            return t('requests.status.quote_sent');
        case 'REQ_WON':
            return t('requests.status.won');
        case 'REQ_LOST':
            return t('requests.status.lost');
        case 'REQ_CONVERTED':
            return t('requests.status.converted');
        default:
            return status || t('requests.labels.unknown_status');
    }
};

const scoreClass = (score) => {
    if (score >= 90) {
        return 'border-rose-200 bg-rose-100 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300';
    }
    if (score >= 70) {
        return 'border-amber-200 bg-amber-100 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300';
    }

    return 'border-stone-200 bg-white text-stone-700 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-200';
};

const duplicateIdentity = (duplicate) => (
    [
        duplicate?.contact_name,
        duplicate?.contact_email,
        duplicate?.contact_phone,
    ].filter(Boolean).join(' · ')
);
</script>

<template>
    <div
        v-if="entries.length"
        class="rounded-sm border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold">
                    {{ $t('requests.duplicates.alert_title') }}
                </h3>
                <p class="mt-1 text-sm text-amber-900/90 dark:text-amber-100/90">
                    {{ alert?.message || $t('requests.duplicates.review_existing') }}
                </p>
                <p class="mt-1 text-xs text-amber-800/80 dark:text-amber-100/70">
                    {{ $t('requests.duplicates.summary', {
                        entries: alert?.entry_count || entries.length,
                        matches: alert?.match_count || 0,
                    }) }}
                </p>
            </div>

            <button
                v-if="canContinue"
                type="button"
                class="inline-flex items-center rounded-sm border border-amber-300 bg-white px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-100 dark:border-amber-500/40 dark:bg-neutral-900 dark:text-amber-200 dark:hover:bg-neutral-800"
                @click="$emit('continue')"
            >
                {{ continueLabel || $t('requests.duplicates.continue_anyway') }}
            </button>
        </div>

        <div class="mt-3 space-y-3">
            <div
                v-for="entry in entries"
                :key="entry.key"
                class="rounded-sm border border-amber-200/80 bg-white/70 p-3 dark:border-amber-500/20 dark:bg-neutral-900/60"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <div
                            v-if="entry.row_number"
                            class="text-[11px] font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300"
                        >
                            {{ $t('requests.duplicates.import_entry', { row: entry.row_number }) }}
                        </div>
                        <div class="mt-1 truncate font-semibold text-stone-900 dark:text-neutral-100">
                            {{ entry.label }}
                        </div>
                        <div
                            v-if="entry.subtitle"
                            class="mt-1 text-xs text-stone-600 dark:text-neutral-300"
                        >
                            {{ entry.subtitle }}
                        </div>
                    </div>

                    <div class="text-xs font-medium text-amber-800 dark:text-amber-200">
                        {{ $t('requests.duplicates.match_count', { count: entry.match_count || 0 }) }}
                    </div>
                </div>

                <div
                    v-if="Array.isArray(entry.duplicates) && entry.duplicates.length"
                    class="mt-3 space-y-2"
                >
                    <div
                        v-for="duplicate in entry.duplicates || []"
                        :key="`${entry.key}-${duplicate.id}`"
                        class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <Link
                                    :href="route('prospects.show', duplicate.id)"
                                    class="text-sm font-semibold text-stone-900 hover:text-emerald-600 dark:text-neutral-100 dark:hover:text-emerald-300"
                                >
                                    {{ duplicate.title || duplicate.service_type || $t('requests.labels.request_number', { id: duplicate.id }) }}
                                </Link>
                                <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                    {{ statusLabel(duplicate.status) }} · {{ formatDate(duplicate.created_at) }}
                                </div>
                                <div
                                    v-if="duplicateIdentity(duplicate)"
                                    class="mt-1 text-xs text-stone-600 dark:text-neutral-300"
                                >
                                    {{ duplicateIdentity(duplicate) }}
                                </div>
                                <div
                                    v-if="duplicate.duplicate_summary"
                                    class="mt-2 text-xs text-stone-700 dark:text-neutral-200"
                                >
                                    {{ duplicate.duplicate_summary }}
                                </div>
                                <div
                                    v-if="Array.isArray(duplicate.duplicate_reasons) && duplicate.duplicate_reasons.length"
                                    class="mt-2 flex flex-wrap gap-1"
                                >
                                    <span
                                        v-for="reason in duplicate.duplicate_reasons"
                                        :key="`${duplicate.id}-${reason.code}`"
                                        class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-medium text-stone-700 dark:bg-neutral-800 dark:text-neutral-200"
                                    >
                                        {{ reason.label }}
                                    </span>
                                </div>
                            </div>

                            <div
                                class="inline-flex items-center rounded-full border px-2 py-1 text-[11px] font-semibold"
                                :class="scoreClass(duplicate.duplicate_score || 0)"
                            >
                                {{ $t('requests.duplicates.score', { score: duplicate.duplicate_score || 0 }) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
