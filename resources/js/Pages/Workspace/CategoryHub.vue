<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppBreadcrumbs from '@/Components/UI/AppBreadcrumbs.vue';
import CategoryIcon from '@/Components/Workspace/CategoryIcon.vue';
import WorkspaceModuleIcon from '@/Components/Workspace/WorkspaceModuleIcon.vue';
import { buildWorkspaceHubCategories } from '@/utils/workspaceHub';

const props = defineProps({
    category: {
        type: String,
        required: true,
    },
});

const page = usePage();
const { t } = useI18n();

const themePalette = {
    revenue: {
        hero: 'border-violet-200 bg-[linear-gradient(135deg,#faf5ff_0%,#ede9fe_45%,#ffffff_100%)] dark:border-violet-900/40 dark:bg-[linear-gradient(135deg,#2e1065_0%,#111827_55%,#020617_100%)]',
        art: 'bg-violet-500/15 text-violet-700 ring-violet-300/40 dark:bg-violet-400/15 dark:text-violet-200 dark:ring-violet-500/30',
        chip: 'border-violet-200 bg-violet-50 text-violet-700 hover:bg-violet-100 dark:border-violet-900/40 dark:bg-violet-950/30 dark:text-violet-200',
    },
    growth: {
        hero: 'border-fuchsia-200 bg-[linear-gradient(135deg,#fdf4ff_0%,#fae8ff_45%,#ffffff_100%)] dark:border-fuchsia-900/40 dark:bg-[linear-gradient(135deg,#4a044e_0%,#111827_55%,#020617_100%)]',
        art: 'bg-fuchsia-500/15 text-fuchsia-700 ring-fuchsia-300/40 dark:bg-fuchsia-400/15 dark:text-fuchsia-200 dark:ring-fuchsia-500/30',
        chip: 'border-fuchsia-200 bg-fuchsia-50 text-fuchsia-700 hover:bg-fuchsia-100 dark:border-fuchsia-900/40 dark:bg-fuchsia-950/30 dark:text-fuchsia-200',
    },
    operations: {
        hero: 'border-blue-200 bg-[linear-gradient(135deg,#eff6ff_0%,#dbeafe_45%,#ffffff_100%)] dark:border-blue-900/40 dark:bg-[linear-gradient(135deg,#172554_0%,#111827_55%,#020617_100%)]',
        art: 'bg-blue-500/15 text-blue-700 ring-blue-300/40 dark:bg-blue-400/15 dark:text-blue-200 dark:ring-blue-500/30',
        chip: 'border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100 dark:border-blue-900/40 dark:bg-blue-950/30 dark:text-blue-200',
    },
    finance: {
        hero: 'border-rose-200 bg-[linear-gradient(135deg,#fff1f2_0%,#ffe4e6_45%,#ffffff_100%)] dark:border-rose-900/40 dark:bg-[linear-gradient(135deg,#4c0519_0%,#111827_55%,#020617_100%)]',
        art: 'bg-rose-500/15 text-rose-700 ring-rose-300/40 dark:bg-rose-400/15 dark:text-rose-200 dark:ring-rose-500/30',
        chip: 'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 dark:border-rose-900/40 dark:bg-rose-950/30 dark:text-rose-200',
    },
    catalog: {
        hero: 'border-emerald-200 bg-[linear-gradient(135deg,#ecfdf5_0%,#d1fae5_45%,#ffffff_100%)] dark:border-emerald-900/40 dark:bg-[linear-gradient(135deg,#022c22_0%,#111827_55%,#020617_100%)]',
        art: 'bg-emerald-500/15 text-emerald-700 ring-emerald-300/40 dark:bg-emerald-400/15 dark:text-emerald-200 dark:ring-emerald-500/30',
        chip: 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-200',
    },
    workspace: {
        hero: 'border-slate-200 bg-[linear-gradient(135deg,#f8fafc_0%,#e2e8f0_45%,#ffffff_100%)] dark:border-slate-800 dark:bg-[linear-gradient(135deg,#0f172a_0%,#111827_55%,#020617_100%)]',
        art: 'bg-slate-500/15 text-slate-700 ring-slate-300/40 dark:bg-slate-400/15 dark:text-slate-200 dark:ring-slate-500/30',
        chip: 'border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100 dark:border-slate-800 dark:bg-slate-900/70 dark:text-slate-200',
    },
};

const moduleIconToneClass = {
    customers: 'text-violet-600 dark:text-violet-200',
    requests: 'text-cyan-600 dark:text-cyan-200',
    quotes: 'text-amber-600 dark:text-amber-200',
    next_actions: 'text-emerald-600 dark:text-emerald-200',
    orders: 'text-fuchsia-600 dark:text-fuchsia-200',
    sales: 'text-orange-600 dark:text-orange-200',
    campaigns: 'text-fuchsia-600 dark:text-fuchsia-200',
    loyalty: 'text-amber-600 dark:text-amber-200',
    performance: 'text-teal-600 dark:text-teal-200',
    jobs: 'text-indigo-600 dark:text-indigo-200',
    tasks: 'text-teal-600 dark:text-teal-200',
    planning: 'text-violet-600 dark:text-violet-200',
    presence: 'text-sky-600 dark:text-sky-200',
    team: 'text-lime-600 dark:text-lime-200',
    invoices: 'text-rose-600 dark:text-rose-200',
    expenses: 'text-red-600 dark:text-red-200',
    accounting: 'text-slate-600 dark:text-slate-200',
    finance: 'text-rose-600 dark:text-rose-200',
    services: 'text-emerald-600 dark:text-emerald-200',
    categories: 'text-sky-600 dark:text-sky-200',
    products: 'text-blue-600 dark:text-blue-200',
    plan_scans: 'text-slate-600 dark:text-slate-200',
    workspace: 'text-slate-600 dark:text-slate-200',
};

const categories = computed(() => buildWorkspaceHubCategories({
    account: page.props.auth?.account,
    planningPendingCount: page.props.planning?.pending_count || 0,
}));

const visibleCategories = computed(() => categories.value.filter((category) => category.visible));

const currentCategory = computed(() => (
    categories.value.find((category) => category.key === props.category)
    || visibleCategories.value[0]
    || categories.value[0]
    || null
));

const currentTheme = computed(() => themePalette[currentCategory.value?.tone] || themePalette.workspace);
const currentModules = computed(() => currentCategory.value?.modules || []);
const categoryTitle = computed(() => currentCategory.value ? t(currentCategory.value.titleKey) : t('workspace_hub.default_title'));
const categoryDescription = computed(() => currentCategory.value ? t(currentCategory.value.descriptionKey) : t('workspace_hub.default_description'));
const pageTitle = computed(() => currentCategory.value ? `${t(currentCategory.value.labelKey)} - ${t('workspace_hub.page_title')}` : t('workspace_hub.page_title'));
const moduleIconClass = (tone) => moduleIconToneClass[tone] || moduleIconToneClass.workspace;

const breadcrumbItems = computed(() => {
    if (!currentCategory.value) {
        return [];
    }

    return [
        {
            key: 'dashboard',
            label: t('nav.dashboard'),
            href: route('dashboard'),
            icon: 'home',
        },
        {
            key: currentCategory.value.key,
            label: t(currentCategory.value.labelKey),
        },
    ];
});

const categoryChipClass = (categoryKey) => (
    categoryKey === currentCategory.value?.key
        ? `${currentTheme.value.chip} shadow-sm`
        : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800'
);
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="pageTitle" />

        <template #breadcrumb>
            <div class="px-4 pt-6 sm:px-6 lg:px-8">
                <AppBreadcrumbs :items="breadcrumbItems" />
            </div>
        </template>

        <div class="space-y-6 px-4 pb-6 sm:px-6 lg:px-8">
            <section
                class="relative overflow-hidden rounded-sm border p-6 shadow-sm lg:p-8"
                :class="currentTheme.hero"
            >
                <div class="absolute -left-10 top-10 size-28 rounded-full bg-white/25 blur-2xl dark:bg-white/5"></div>
                <div class="absolute bottom-0 right-0 size-40 translate-x-10 translate-y-10 rounded-full bg-white/25 blur-3xl dark:bg-white/5"></div>
                <div class="absolute right-6 top-6 hidden md:block">
                    <div class="hub-hero-art relative flex size-48 items-center justify-center rounded-[2rem] ring-1 backdrop-blur" :class="currentTheme.art">
                        <div class="absolute -left-5 top-6 size-10 rounded-2xl bg-white/70 dark:bg-white/10"></div>
                        <div class="absolute -right-4 bottom-6 size-12 rounded-full bg-white/60 dark:bg-white/10"></div>
                        <CategoryIcon :name="currentCategory?.icon || 'workspace'" icon-class="size-20" />
                    </div>
                </div>

                <div class="relative max-w-3xl">
                    <div class="text-xs font-semibold uppercase tracking-[0.28em] text-stone-500 dark:text-neutral-400">
                        {{ t('workspace_hub.eyebrow') }}
                    </div>
                    <h1 class="mt-3 text-3xl font-semibold tracking-tight text-stone-900 dark:text-white">
                        {{ categoryTitle }}
                    </h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-stone-600 dark:text-neutral-300">
                        {{ categoryDescription }}
                    </p>

                    <div class="mt-5 inline-flex items-center rounded-full border border-white/60 bg-white/70 px-3 py-1 text-xs font-medium text-stone-700 backdrop-blur dark:border-white/10 dark:bg-white/5 dark:text-neutral-200">
                        {{ t('workspace_hub.category_count', { count: currentModules.length }) }}
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-950">
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        v-for="categoryItem in visibleCategories"
                        :key="categoryItem.key"
                        :href="route(categoryItem.routeName, categoryItem.routeParams)"
                        class="inline-flex items-center gap-2 rounded-full border px-3 py-2 text-sm font-medium transition"
                        :class="categoryChipClass(categoryItem.key)"
                    >
                        <CategoryIcon :name="categoryItem.icon" icon-class="size-4" />
                        <span>{{ t(categoryItem.labelKey) }}</span>
                    </Link>
                </div>
            </section>

            <section class="space-y-3">
                <p class="max-w-2xl text-sm text-stone-500 dark:text-neutral-400">
                    {{ t('workspace_hub.modules_subtitle') }}
                </p>

                <div v-if="currentModules.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <Link
                        v-for="(module, index) in currentModules"
                        :key="module.key"
                        :href="route(module.routeName)"
                        class="hub-module-card group relative overflow-hidden rounded-sm border border-stone-200 bg-white p-4 shadow-sm transition hover:-translate-y-1 hover:border-stone-300 hover:shadow-xl dark:border-neutral-800 dark:bg-neutral-950 dark:hover:border-neutral-700"
                        :style="{ animationDelay: `${index * 70}ms` }"
                    >
                        <div class="pointer-events-none absolute right-3 top-3">
                            <div class="hub-module-art relative flex size-20 items-center justify-center">
                                <div class="absolute -left-1 top-4 size-6 rounded-2xl bg-stone-100/90 dark:bg-white/10"></div>
                                <div class="absolute bottom-2 right-0 size-7 rounded-full bg-stone-100/80 dark:bg-white/10"></div>
                                <WorkspaceModuleIcon :name="module.key" icon-class="size-8" :class="moduleIconClass(module.tone)" />
                            </div>
                        </div>

                        <div class="relative flex min-h-[70px] flex-col pr-16 sm:pr-20">
                            <div>
                                <div class="text-base font-semibold text-stone-900 dark:text-white">
                                    {{ t(module.labelKey) }}
                                </div>
                                <p class="mt-1 text-sm leading-5 text-stone-500 dark:text-neutral-400">
                                    {{ t(module.descriptionKey) }}
                                </p>
                            </div>

                            <div v-if="module.badge" class="mt-auto pt-3">
                                <span
                                    class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-200"
                                >
                                    {{ t(module.badge.labelKey, { count: module.badge.value, value: module.badge.value }) }}
                                </span>
                            </div>
                        </div>
                    </Link>
                </div>

                <div v-else class="rounded-sm border border-dashed border-stone-300 bg-white p-8 text-center dark:border-neutral-700 dark:bg-neutral-950">
                    <div class="mx-auto flex size-14 items-center justify-center rounded-2xl" :class="currentTheme.art">
                        <CategoryIcon :name="currentCategory?.icon || 'workspace'" icon-class="size-7" />
                    </div>
                    <h3 class="mt-4 text-lg font-semibold text-stone-900 dark:text-white">
                        {{ t('workspace_hub.empty_title') }}
                    </h3>
                    <p class="mt-2 text-sm text-stone-500 dark:text-neutral-400">
                        {{ t('workspace_hub.empty_body') }}
                    </p>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.hub-hero-art {
    animation: hubFloat 8s ease-in-out infinite;
}

.hub-module-card {
    animation: hubReveal 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
}

.hub-module-art {
    transition: transform 220ms ease;
    animation: hubFloatSoft 9s ease-in-out infinite;
}

.group:hover .hub-module-art {
    transform: translateY(-4px) scale(1.02);
}

@keyframes hubReveal {
    from {
        opacity: 0;
        transform: translateY(12px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes hubFloat {
    0%,
    100% {
        transform: translateY(0);
    }

    50% {
        transform: translateY(-6px);
    }
}

@keyframes hubFloatSoft {
    0%,
    100% {
        transform: translateY(0) scale(1);
    }

    50% {
        transform: translateY(-4px) scale(1.015);
    }
}

@media (prefers-reduced-motion: reduce) {
    .hub-hero-art,
    .hub-module-card,
    .hub-module-art {
        animation: none;
        transition: none;
    }

    .group:hover .hub-module-art {
        transform: none;
        box-shadow: none;
    }
}
</style>
