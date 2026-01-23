<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Checkbox from '@/Components/Checkbox.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    maintenance: {
        type: Object,
        default: () => ({ enabled: false, message: '' }),
    },
    templates: {
        type: Object,
        default: () => ({ email_default: '', quote_default: '', invoice_default: '' }),
    },
    plans: {
        type: Array,
        default: () => [],
    },
    plan_limits: {
        type: Object,
        default: () => ({}),
    },
    plan_modules: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const limitKeys = computed(() => [
    { key: 'quotes', label: t('super_admin.settings.limits.quotes') },
    { key: 'requests', label: t('super_admin.settings.limits.requests') },
    { key: 'plan_scan_quotes', label: t('super_admin.settings.limits.plan_scan_quotes') },
    { key: 'invoices', label: t('super_admin.settings.limits.invoices') },
    { key: 'jobs', label: t('super_admin.settings.limits.jobs') },
    { key: 'products', label: t('super_admin.settings.limits.products') },
    { key: 'services', label: t('super_admin.settings.limits.services') },
    { key: 'tasks', label: t('super_admin.settings.limits.tasks') },
    { key: 'team_members', label: t('super_admin.settings.limits.team_members') },
    { key: 'assistant_requests', label: t('super_admin.settings.limits.assistant_requests') },
]);

const moduleKeys = computed(() => [
    { key: 'quotes', label: t('super_admin.settings.modules.quotes') },
    { key: 'requests', label: t('super_admin.settings.modules.requests') },
    { key: 'plan_scans', label: t('super_admin.settings.modules.plan_scans') },
    { key: 'invoices', label: t('super_admin.settings.modules.invoices') },
    { key: 'jobs', label: t('super_admin.settings.modules.jobs') },
    { key: 'products', label: t('super_admin.settings.modules.products') },
    { key: 'services', label: t('super_admin.settings.modules.services') },
    { key: 'tasks', label: t('super_admin.settings.modules.tasks') },
    { key: 'team_members', label: t('super_admin.settings.modules.team_members') },
    { key: 'assistant', label: t('super_admin.settings.modules.assistant') },
]);

const form = useForm({
    maintenance: {
        enabled: props.maintenance?.enabled ?? false,
        message: props.maintenance?.message ?? '',
    },
    templates: {
        email_default: props.templates?.email_default ?? '',
        quote_default: props.templates?.quote_default ?? '',
        invoice_default: props.templates?.invoice_default ?? '',
    },
    plan_limits: props.plans.reduce((acc, plan) => {
        const existing = props.plan_limits?.[plan.key] || {};
        acc[plan.key] = limitKeys.value.reduce((limits, item) => {
            limits[item.key] = existing[item.key] ?? '';
            return limits;
        }, {});
        return acc;
    }, {}),
    plan_modules: props.plans.reduce((acc, plan) => {
        const existing = props.plan_modules?.[plan.key] || {};
        acc[plan.key] = moduleKeys.value.reduce((modules, item) => {
            modules[item.key] = typeof existing[item.key] === 'boolean' ? existing[item.key] : true;
            return modules;
        }, {});
        return acc;
    }, {}),
});

const activePlanKey = ref(null);
const activePlan = computed(() => props.plans.find((plan) => plan.key === activePlanKey.value) || null);
const showPlanModal = computed(() => Boolean(activePlan.value));

const activeModulePlanKey = ref(null);
const activeModulePlan = computed(() => props.plans.find((plan) => plan.key === activeModulePlanKey.value) || null);
const showModuleModal = computed(() => Boolean(activeModulePlan.value));

const limitValue = (planKey, limitKey) => {
    const value = form.plan_limits?.[planKey]?.[limitKey];
    if (value === '' || value === null || typeof value === 'undefined') {
        return t('super_admin.common.unlimited');
    }
    return value;
};

const moduleValue = (planKey, moduleKey) =>
    form.plan_modules?.[planKey]?.[moduleKey] === false
        ? t('super_admin.common.disabled')
        : t('super_admin.common.enabled');

const openPlan = (plan) => {
    activePlanKey.value = plan.key;
};

const closePlan = () => {
    activePlanKey.value = null;
};

const openModulePlan = (plan) => {
    activeModulePlanKey.value = plan.key;
};

const closeModulePlan = () => {
    activeModulePlanKey.value = null;
};

const submit = () => {
    form.put(route('superadmin.settings.update'), { preserveScroll: true });
};

const submitPlanLimits = () => {
    form.put(route('superadmin.settings.update'), {
        preserveScroll: true,
        onSuccess: () => closePlan(),
    });
};

const submitPlanModules = () => {
    form.put(route('superadmin.settings.update'), {
        preserveScroll: true,
        onSuccess: () => closeModulePlan(),
    });
};
</script>

<template>
    <Head :title="$t('super_admin.settings.page_title')" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.settings.title') }}
                    </h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('super_admin.settings.subtitle') }}
                    </p>
                </div>
            </section>

            <div class="rounded-sm border border-stone-200 border-t-4 border-t-amber-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('super_admin.settings.maintenance.title') }}
                </h2>
                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                        <Checkbox v-model:checked="form.maintenance.enabled" :value="true" />
                        <span>{{ $t('super_admin.settings.maintenance.enable') }}</span>
                    </label>
                    <div>
                        <FloatingInput v-model="form.maintenance.message" :label="$t('super_admin.settings.maintenance.message')" />
                        <InputError class="mt-1" :message="form.errors['maintenance.message']" />
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            {{ $t('super_admin.settings.maintenance.save') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-sm border border-stone-200 border-t-4 border-t-sky-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('super_admin.settings.templates.title') }}
                </h2>
                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.settings.templates.email_default') }}
                        </label>
                        <textarea v-model="form.templates.email_default" rows="3"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"></textarea>
                        <InputError class="mt-1" :message="form.errors['templates.email_default']" />
                    </div>
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.settings.templates.quote_default') }}
                        </label>
                        <textarea v-model="form.templates.quote_default" rows="3"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"></textarea>
                        <InputError class="mt-1" :message="form.errors['templates.quote_default']" />
                    </div>
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.settings.templates.invoice_default') }}
                        </label>
                        <textarea v-model="form.templates.invoice_default" rows="3"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"></textarea>
                        <InputError class="mt-1" :message="form.errors['templates.invoice_default']" />
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            {{ $t('super_admin.settings.templates.save') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-sm border border-stone-200 border-t-4 border-t-zinc-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('super_admin.settings.plan_limits.title') }}
                </h2>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('super_admin.settings.plan_limits.subtitle') }}
                </p>
                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div v-if="plans.length === 0" class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.settings.plan_limits.empty') }}
                    </div>
                    <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div v-for="plan in plans" :key="plan.key"
                            class="cursor-pointer rounded-sm border border-stone-200 bg-white p-4 shadow-sm transition hover:border-green-500 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900/40 dark:hover:border-green-500"
                            role="button"
                            tabindex="0"
                            @click="openPlan(plan)"
                            @keydown.enter="openPlan(plan)"
                            @keydown.space.prevent="openPlan(plan)">
                            <div class="flex items-start justify-between gap-3">
                                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ plan.name }}
                                </div>
                                <span class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.settings.plan_limits.edit_limits') }}
                                </span>
                            </div>
                            <div class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                                <div v-for="limit in limitKeys" :key="limit.key"
                                    class="flex items-center justify-between gap-2 rounded-sm border border-stone-200 bg-stone-50 px-2 py-1 dark:border-neutral-700 dark:bg-neutral-900">
                                    <span class="text-stone-500 dark:text-neutral-400">{{ limit.label }}</span>
                                    <span class="font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ limitValue(plan.key, limit.key) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.settings.plan_limits.helper') }}
                        </p>
                        <button type="submit" :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            {{ $t('super_admin.settings.plan_limits.save') }}
                        </button>
                    </div>
                </form>

                <Modal :show="showPlanModal" @close="closePlan" maxWidth="2xl">
                    <div v-if="activePlan" class="p-5">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.settings.plan_limits.edit_limits_title', { plan: activePlan.name }) }}
                            </h3>
                            <button type="button" @click="closePlan"
                                class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.common.close') }}
                            </button>
                        </div>
                        <form class="mt-4 space-y-4" @submit.prevent="submitPlanLimits">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.settings.plan_limits.modal_hint') }}
                            </p>
                            <div class="grid gap-3 md:grid-cols-3">
                                <div v-for="limit in limitKeys" :key="limit.key">
                                    <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ limit.label }}</label>
                                    <input v-model="form.plan_limits[activePlan.key][limit.key]" type="number" min="0"
                                        :placeholder="$t('super_admin.common.unlimited')"
                                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200" />
                                    <InputError class="mt-1" :message="form.errors[`plan_limits.${activePlan.key}.${limit.key}`]" />
                                </div>
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="closePlan"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                    {{ $t('super_admin.common.cancel') }}
                                </button>
                                <button type="submit" :disabled="form.processing"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                                    {{ $t('super_admin.settings.plan_limits.save') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </Modal>
            </div>

            <div class="rounded-sm border border-stone-200 border-t-4 border-t-emerald-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('super_admin.settings.plan_modules.title') }}
                </h2>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    {{ $t('super_admin.settings.plan_modules.subtitle') }}
                </p>
                <form class="mt-4 space-y-4" @submit.prevent="submit">
                    <div v-if="plans.length === 0" class="text-sm text-stone-500 dark:text-neutral-400">
                        {{ $t('super_admin.settings.plan_modules.empty') }}
                    </div>
                    <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div v-for="plan in plans" :key="plan.key"
                            class="cursor-pointer rounded-sm border border-stone-200 bg-white p-4 shadow-sm transition hover:border-green-500 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900/40 dark:hover:border-green-500"
                            role="button"
                            tabindex="0"
                            @click="openModulePlan(plan)"
                            @keydown.enter="openModulePlan(plan)"
                            @keydown.space.prevent="openModulePlan(plan)">
                            <div class="flex items-start justify-between gap-3">
                                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                    {{ plan.name }}
                                </div>
                                <span class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.settings.plan_modules.edit_modules') }}
                                </span>
                            </div>
                            <div class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                                <div v-for="module in moduleKeys" :key="module.key"
                                    class="flex items-center justify-between gap-2 rounded-sm border border-stone-200 bg-stone-50 px-2 py-1 dark:border-neutral-700 dark:bg-neutral-900">
                                    <span class="text-stone-500 dark:text-neutral-400">{{ module.label }}</span>
                                    <span class="font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ moduleValue(plan.key, module.key) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('super_admin.settings.plan_modules.helper') }}
                        </p>
                        <button type="submit" :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            {{ $t('super_admin.settings.plan_modules.save') }}
                        </button>
                    </div>
                </form>

                <Modal :show="showModuleModal" @close="closeModulePlan" maxWidth="2xl">
                    <div v-if="activeModulePlan" class="p-5">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                                {{ $t('super_admin.settings.plan_modules.edit_modules_title', { plan: activeModulePlan.name }) }}
                            </h3>
                            <button type="button" @click="closeModulePlan"
                                class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.common.close') }}
                            </button>
                        </div>
                        <form class="mt-4 space-y-4" @submit.prevent="submitPlanModules">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.settings.plan_modules.modal_hint') }}
                            </p>
                            <div class="grid gap-3 md:grid-cols-2">
                                <label v-for="module in moduleKeys" :key="module.key"
                                    class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                    <Checkbox v-model:checked="form.plan_modules[activeModulePlan.key][module.key]" :value="true" />
                                    <span>{{ module.label }}</span>
                                </label>
                            </div>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="closeModulePlan"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-stone-200 text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                    {{ $t('super_admin.common.cancel') }}
                                </button>
                                <button type="submit" :disabled="form.processing"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                                    {{ $t('super_admin.settings.plan_modules.save') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </Modal>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
