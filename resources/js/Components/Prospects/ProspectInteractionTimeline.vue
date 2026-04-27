<script setup>
import { useI18n } from 'vue-i18n';
import { humanizeDate } from '@/utils/date';
import { formatBytes } from '@/utils/media';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();

const formatDate = (value) => humanizeDate(value);
const formatAbsoluteDate = (value) => {
    if (!value) {
        return '';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleString();
};

const translate = (key, fallback = '') => {
    const translated = t(key);

    if (translated === key) {
        return fallback || key;
    }

    return translated;
};

const interactionBadgeClass = (item) => {
    switch (item?.type) {
        case 'next_action':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300';
        case 'meeting':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300';
        case 'call':
            return 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300';
        case 'call_outcome':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300';
        case 'email':
            return 'bg-violet-100 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300';
        case 'document':
            return 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
        case 'note':
            return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200';
        default:
            return 'bg-stone-100 text-stone-600 dark:bg-neutral-700 dark:text-neutral-300';
    }
};

const interactionTypeLabel = (item) => {
    const activityKey = item?.metadata?.activity_key || item?.metadata?.event_key;
    if (activityKey) {
        const salesKey = `requests.sales_activity.labels.${activityKey}`;
        const translated = translate(salesKey);
        if (translated !== salesKey) {
            return translated;
        }
    }

    return translate(
        `requests.interactions.types.${item?.type || 'system'}`,
        item?.type || translate('requests.interactions.types.system', 'System')
    );
};

const interactionHeadline = (item) => {
    const description = String(item?.description || '').trim();

    return description || interactionTypeLabel(item);
};

const interactionBody = (item) => {
    const note = String(item?.metadata?.note || '').trim();
    const description = String(item?.description || '').trim();

    if (!note || note === description) {
        return null;
    }

    return note;
};

const actorLabel = (item) => item?.user?.name || t('requests.interactions.actor_fallback');
const nextActionLabel = (item) => {
    const prefix = t('requests.interactions.next_action_prefix');
    const date = formatDate(item?.next_action?.at);

    return date ? `${prefix} ${date}` : prefix;
};
</script>

<template>
    <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.interactions.title') }}
                </h2>
                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.interactions.subtitle') }}
                </p>
            </div>
            <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-600 dark:bg-neutral-700 dark:text-neutral-300">
                {{ items.length }}
            </span>
        </div>

        <div v-if="items.length" class="mt-4 space-y-3">
            <article
                v-for="item in items"
                :key="item.id"
                class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="interactionBadgeClass(item)">
                        {{ interactionTypeLabel(item) }}
                    </span>
                    <span class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ actorLabel(item) }} ·
                        <span :title="formatAbsoluteDate(item.created_at)">{{ formatDate(item.created_at) }}</span>
                    </span>
                </div>

                <p class="mt-2 whitespace-pre-wrap break-words text-sm font-medium text-stone-800 dark:text-neutral-100">
                    {{ interactionHeadline(item) }}
                </p>

                <p
                    v-if="interactionBody(item)"
                    class="mt-2 whitespace-pre-wrap break-words text-sm text-stone-600 dark:text-neutral-300"
                >
                    {{ interactionBody(item) }}
                </p>

                <div v-if="item.attachment || item.next_action" class="mt-3 flex flex-wrap gap-2">
                    <span
                        v-if="item.attachment"
                        class="rounded-full bg-stone-100 px-2 py-1 text-xs text-stone-600 dark:bg-neutral-700 dark:text-neutral-300"
                    >
                        {{ $t('requests.interactions.attachment_prefix') }}
                        {{ item.attachment.name }}
                        <template v-if="item.attachment.size">
                            · {{ formatBytes(item.attachment.size) }}
                        </template>
                    </span>

                    <span
                        v-if="item.next_action"
                        class="rounded-full bg-emerald-100 px-2 py-1 text-xs text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"
                        :title="formatAbsoluteDate(item.next_action.at)"
                    >
                        {{ nextActionLabel(item) }}
                    </span>
                </div>
            </article>
        </div>

        <p v-else class="mt-4 text-sm text-stone-500 dark:text-neutral-400">
            {{ $t('requests.interactions.empty') }}
        </p>
    </section>
</template>
