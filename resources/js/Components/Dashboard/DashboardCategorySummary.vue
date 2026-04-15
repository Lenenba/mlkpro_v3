<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CategoryIcon from '@/Components/Workspace/CategoryIcon.vue';

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    subtitle: {
        type: String,
        required: true,
    },
    cards: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();

const themePalette = {
    revenue: {
        card: 'border-violet-200 bg-[linear-gradient(180deg,rgba(250,245,255,0.95)_0%,rgba(255,255,255,0.98)_100%)] dark:border-violet-900/40 dark:bg-[linear-gradient(180deg,rgba(46,16,101,0.36)_0%,rgba(10,10,10,0.92)_100%)]',
        badge: 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-900/40 dark:bg-violet-950/30 dark:text-violet-200',
        icon: 'text-violet-700 dark:text-violet-200',
        art: 'bg-violet-100/90 dark:bg-white/10',
    },
    growth: {
        card: 'border-fuchsia-200 bg-[linear-gradient(180deg,rgba(253,244,255,0.95)_0%,rgba(255,255,255,0.98)_100%)] dark:border-fuchsia-900/40 dark:bg-[linear-gradient(180deg,rgba(74,4,78,0.36)_0%,rgba(10,10,10,0.92)_100%)]',
        badge: 'border-fuchsia-200 bg-fuchsia-50 text-fuchsia-700 dark:border-fuchsia-900/40 dark:bg-fuchsia-950/30 dark:text-fuchsia-200',
        icon: 'text-fuchsia-700 dark:text-fuchsia-200',
        art: 'bg-fuchsia-100/90 dark:bg-white/10',
    },
    operations: {
        card: 'border-blue-200 bg-[linear-gradient(180deg,rgba(239,246,255,0.95)_0%,rgba(255,255,255,0.98)_100%)] dark:border-blue-900/40 dark:bg-[linear-gradient(180deg,rgba(23,37,84,0.36)_0%,rgba(10,10,10,0.92)_100%)]',
        badge: 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/40 dark:bg-blue-950/30 dark:text-blue-200',
        icon: 'text-blue-700 dark:text-blue-200',
        art: 'bg-blue-100/90 dark:bg-white/10',
    },
    finance: {
        card: 'border-rose-200 bg-[linear-gradient(180deg,rgba(255,241,242,0.95)_0%,rgba(255,255,255,0.98)_100%)] dark:border-rose-900/40 dark:bg-[linear-gradient(180deg,rgba(76,5,25,0.38)_0%,rgba(10,10,10,0.92)_100%)]',
        badge: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900/40 dark:bg-rose-950/30 dark:text-rose-200',
        icon: 'text-rose-700 dark:text-rose-200',
        art: 'bg-rose-100/90 dark:bg-white/10',
    },
    catalog: {
        card: 'border-emerald-200 bg-[linear-gradient(180deg,rgba(236,253,245,0.95)_0%,rgba(255,255,255,0.98)_100%)] dark:border-emerald-900/40 dark:bg-[linear-gradient(180deg,rgba(2,44,34,0.38)_0%,rgba(10,10,10,0.92)_100%)]',
        badge: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-200',
        icon: 'text-emerald-700 dark:text-emerald-200',
        art: 'bg-emerald-100/90 dark:bg-white/10',
    },
    workspace: {
        card: 'border-slate-200 bg-[linear-gradient(180deg,rgba(248,250,252,0.98)_0%,rgba(255,255,255,0.98)_100%)] dark:border-slate-800 dark:bg-[linear-gradient(180deg,rgba(15,23,42,0.68)_0%,rgba(10,10,10,0.92)_100%)]',
        badge: 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-800 dark:bg-slate-900/70 dark:text-slate-200',
        icon: 'text-slate-700 dark:text-slate-200',
        art: 'bg-slate-100/90 dark:bg-white/10',
    },
};

const visibleCards = computed(() => (props.cards || []).filter((card) => Array.isArray(card.metrics) && card.metrics.length > 0));
const toneStyles = (tone) => themePalette[tone] || themePalette.workspace;
</script>

<template>
    <section v-if="visibleCards.length" class="space-y-4">
        <div class="flex flex-col gap-2 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <div class="text-xs font-semibold uppercase tracking-[0.28em] text-stone-500 dark:text-neutral-400">
                    {{ t('dashboard.hub.eyebrow') }}
                </div>
                <h2 class="mt-2 text-xl font-semibold text-stone-900 dark:text-white">
                    {{ title }}
                </h2>
                <p class="mt-1 max-w-3xl text-sm text-stone-500 dark:text-neutral-400">
                    {{ subtitle }}
                </p>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="(card, index) in visibleCards"
                :key="card.key"
                :href="route(card.routeName, card.routeParams)"
                class="dashboard-hub-card group relative overflow-hidden rounded-sm border p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-xl"
                :class="toneStyles(card.tone).card"
                :style="{ animationDelay: `${index * 70}ms` }"
            >
                <div class="relative flex min-h-[248px] flex-col">
                    <div class="pr-20">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-lg font-semibold text-stone-900 dark:text-white">
                                    {{ card.title }}
                                </h3>
                                <p class="mt-1 text-sm leading-6 text-stone-500 dark:text-neutral-400">
                                    {{ card.description }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <dl class="mt-5 space-y-3 pr-14">
                        <div
                            v-for="metric in card.metrics"
                            :key="`${card.key}-${metric.label}`"
                            class="flex items-start justify-between gap-3 border-b border-stone-200/70 pb-3 last:border-b-0 last:pb-0 dark:border-white/10"
                        >
                            <dt class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ metric.label }}
                            </dt>
                            <dd class="text-right text-sm font-semibold text-stone-900 dark:text-white">
                                {{ metric.value }}
                            </dd>
                        </div>
                    </dl>

                    <div v-if="card.moduleLabels?.length" class="mt-5 flex flex-wrap gap-2 pr-20">
                        <span
                            v-for="moduleLabel in card.moduleLabels"
                            :key="`${card.key}-${moduleLabel}`"
                            class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-medium"
                            :class="toneStyles(card.tone).badge"
                        >
                            {{ moduleLabel }}
                        </span>
                    </div>

                    <div class="mt-auto pt-5 text-xs font-semibold uppercase tracking-[0.22em] text-stone-500 dark:text-neutral-400">
                        {{ t('dashboard.hub.open_category') }}
                    </div>

                    <div class="pointer-events-none absolute bottom-1 right-1">
                        <div class="dashboard-hub-art relative flex size-24 items-center justify-center">
                            <div class="absolute -left-1 top-5 size-7 rounded-2xl" :class="toneStyles(card.tone).art"></div>
                            <div class="absolute bottom-2 right-1 size-8 rounded-full" :class="toneStyles(card.tone).art"></div>
                            <CategoryIcon :name="card.icon" icon-class="size-10" :class="toneStyles(card.tone).icon" />
                        </div>
                    </div>
                </div>
            </Link>
        </div>
    </section>
</template>

<style scoped>
.dashboard-hub-card {
    animation: dashboardHubReveal 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
}

.dashboard-hub-art {
    transition: transform 220ms ease;
    animation: dashboardHubFloat 8.5s ease-in-out infinite;
}

.group:hover .dashboard-hub-art {
    transform: translateY(-4px);
}

@keyframes dashboardHubReveal {
    from {
        opacity: 0;
        transform: translateY(12px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes dashboardHubFloat {
    0%,
    100% {
        transform: translateY(0);
    }

    50% {
        transform: translateY(-4px);
    }
}
</style>
