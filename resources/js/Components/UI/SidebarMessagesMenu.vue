<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useFloatingMenu } from '@/Composables/useFloatingMenu';

const props = defineProps({
    buttonClass: {
        type: String,
        default:
            'relative inline-flex size-8 items-center justify-center rounded-sm border border-stone-200 bg-white text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800',
    },
    badgeClass: {
        type: String,
        default: 'absolute -top-1 -end-1 rounded-full bg-rose-500 px-1.5 text-[10px] font-semibold text-white',
    },
    iconClass: {
        type: String,
        default: 'size-6',
    },
});

const page = usePage();
const { t } = useI18n();

const { isOpen, toggleRef, menuRef, menuStyle, closeMenu, toggleMenu } = useFloatingMenu();

const limitLabelMap = {
    quotes: 'dashboard.limits.quotes',
    requests: 'dashboard.limits.requests',
    plan_scan_quotes: 'settings.company.limits.labels.plan_scan_quotes',
    invoices: 'dashboard.limits.invoices',
    jobs: 'dashboard.limits.jobs',
    products: 'dashboard.limits.products',
    services: 'dashboard.limits.services',
    tasks: 'dashboard.limits.tasks',
    team_members: 'dashboard.limits.team_members',
    assistant_requests: 'settings.company.limits.labels.assistant_requests',
};

const translate = (key, fallback, params = {}) => {
    const value = t(key, params);
    return value === key ? fallback : value;
};

const usageLimits = computed(() => page.props.usage_limits || null);
const planName = computed(() => usageLimits.value?.plan_name || usageLimits.value?.plan_key || '');
const alertItems = computed(() =>
    (usageLimits.value?.items || [])
        .filter((item) => item.status && item.status !== 'ok')
        .slice(0, 5)
);
const alertCount = computed(() => alertItems.value.length);
const title = computed(() => translate('sidebar.messages.title', 'Messages'));
const subtitle = computed(() => {
    if (!alertCount.value) {
        return translate('sidebar.messages.subtitle_empty', 'No operational alert right now.');
    }

    return translate(
        'sidebar.messages.subtitle_count',
        `${alertCount.value} usage alert${alertCount.value > 1 ? 's' : ''}`,
        { count: alertCount.value }
    );
});
const emptyLabel = computed(() =>
    translate('sidebar.messages.empty', 'Your usage alerts will appear here when a limit needs attention.')
);
const actionLabel = computed(() => translate('sidebar.messages.action', 'View limits'));

const actionRoute = computed(() => {
    const isOwner = Boolean(page.props.auth?.account?.is_owner);
    const isSuperadmin = Boolean(page.props.auth?.account?.is_superadmin);

    if (isOwner && !isSuperadmin) {
        return route('settings.company.edit');
    }

    return route('dashboard');
});

const displayLimitLabel = (item) => {
    const translationKey = limitLabelMap[item.key];
    if (translationKey) {
        const translated = t(translationKey);
        if (translated !== translationKey) {
            return translated;
        }
    }

    return item.label || item.key;
};

const displayLimitValue = (item) => {
    if (item.limit === null || item.limit === undefined) {
        return translate('dashboard.usage.unlimited', 'Unlimited');
    }
    if (Number(item.limit) <= 0) {
        return translate('dashboard.usage.not_available', 'Not available');
    }
    return item.limit;
};

const describeAlert = (item) => {
    if (item.status === 'over') {
        return translate(
            'sidebar.messages.alert_over',
            `Limit exceeded. ${item.used} used out of ${displayLimitValue(item)}.`,
            { used: item.used, limit: displayLimitValue(item) }
        );
    }

    if (item.remaining === null) {
        return translate('sidebar.messages.alert_unlimited', 'This limit is not capped on your current plan.');
    }

    return translate(
        'sidebar.messages.alert_warning',
        `${item.remaining} remaining before you reach the limit.`,
        { remaining: item.remaining }
    );
};

const resolveTone = (status) => {
    if (status === 'over') {
        return {
            badge: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-200',
            dot: 'bg-rose-500',
            label: translate('sidebar.messages.status_over', 'Critical'),
        };
    }

    return {
        badge: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-200',
        dot: 'bg-amber-500',
        label: translate('sidebar.messages.status_warning', 'Watch'),
    };
};

const openUsageCenter = () => {
    closeMenu();
    router.visit(actionRoute.value);
};
</script>

<template>
    <div class="relative">
        <button
            ref="toggleRef"
            type="button"
            :class="buttonClass"
            :aria-label="title"
            @click="toggleMenu"
        >
            <svg :class="iconClass" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                <path d="M8 9h8" />
                <path d="M8 13h5" />
            </svg>
            <span v-if="alertCount" :class="badgeClass">
                {{ alertCount > 9 ? '9+' : alertCount }}
            </span>
        </button>

        <Teleport to="body">
            <div
                v-if="isOpen"
                ref="menuRef"
                class="fixed z-[90] w-80 rounded-sm border border-stone-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                :style="menuStyle"
            >
                <div class="border-b border-stone-200 px-4 py-3 dark:border-neutral-700">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ title }}
                            </div>
                            <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ subtitle }}
                            </div>
                        </div>
                        <span
                            v-if="planName"
                            class="rounded-full bg-stone-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-stone-600 dark:bg-neutral-800 dark:text-neutral-300"
                        >
                            {{ planName }}
                        </span>
                    </div>
                </div>

                <div v-if="!alertCount" class="px-4 py-5">
                    <p class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ emptyLabel }}
                    </p>
                </div>

                <div v-else class="max-h-80 space-y-3 overflow-y-auto px-4 py-4">
                    <div
                        v-for="item in alertItems"
                        :key="item.key"
                        class="rounded-sm border border-stone-200 px-3 py-3 dark:border-neutral-800"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex size-2 rounded-full" :class="resolveTone(item.status).dot"></span>
                                    <span class="truncate text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ displayLimitLabel(item) }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs leading-5 text-stone-500 dark:text-neutral-400">
                                    {{ describeAlert(item) }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.12em]"
                                :class="resolveTone(item.status).badge"
                            >
                                {{ resolveTone(item.status).label }}
                            </span>
                        </div>

                        <div class="mt-3 flex items-center justify-between text-xs">
                            <span class="font-medium text-stone-600 dark:text-neutral-300">
                                {{ item.used }} / {{ displayLimitValue(item) }}
                            </span>
                            <span class="text-stone-400 dark:text-neutral-500">
                                {{ item.percent !== null ? `${item.percent}%` : translate('sidebar.messages.no_cap', 'No cap') }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="border-t border-stone-200 px-4 py-3 dark:border-neutral-700">
                    <button
                        type="button"
                        class="inline-flex w-full items-center justify-center rounded-sm bg-stone-900 px-3 py-2 text-sm font-semibold text-white transition hover:bg-stone-800 focus:outline-none focus:ring-2 focus:ring-stone-300 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-white dark:focus:ring-neutral-600"
                        @click="openUsageCenter"
                    >
                        {{ actionLabel }}
                    </button>
                </div>
            </div>
        </Teleport>
    </div>
</template>
