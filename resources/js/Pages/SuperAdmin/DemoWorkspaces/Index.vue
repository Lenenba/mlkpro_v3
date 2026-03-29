<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';

const props = defineProps({
    workspaces: {
        type: Object,
        required: true,
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
    options: {
        type: Object,
        required: true,
    },
    defaults: {
        type: Object,
        required: true,
    },
    can_view_tenant: {
        type: Boolean,
        default: false,
    },
    can_impersonate: {
        type: Boolean,
        default: false,
    },
});

const steps = [
    { key: 'prospect', label: 'Prospect' },
    { key: 'scope', label: 'Scope' },
    { key: 'realism', label: 'Realism' },
    { key: 'access', label: 'Access' },
];

const currentStep = ref(0);
const dateDrafts = reactive(
    Object.fromEntries(
        (props.workspaces?.data || []).map((workspace) => [
            workspace.id,
            workspace.expires_at ? String(workspace.expires_at).slice(0, 10) : '',
        ]),
    ),
);

const form = useForm({
    ...props.defaults,
    selected_modules: [...(props.defaults?.selected_modules || [])],
});

const moduleGroups = computed(() => {
    const groups = {};
    for (const module of props.options.modules || []) {
        const category = module.category || 'Other';
        if (!groups[category]) {
            groups[category] = [];
        }
        groups[category].push(module);
    }

    return Object.entries(groups);
});

const selectedModuleLabels = computed(() =>
    (props.options.modules || [])
        .filter((module) => form.selected_modules.includes(module.key))
        .map((module) => module.label),
);

const selectedProfile = computed(() =>
    (props.options.seed_profiles || []).find((profile) => profile.value === form.seed_profile) || null,
);

const stepIsValid = computed(() => {
    if (currentStep.value === 0) {
        return Boolean(form.prospect_name && form.company_name && form.company_type && form.company_sector);
    }
    if (currentStep.value === 1) {
        return (form.selected_modules || []).length > 0;
    }
    if (currentStep.value === 2) {
        return Boolean(form.seed_profile && Number(form.team_size) >= 1);
    }

    return Boolean(form.expires_at && form.timezone && form.locale);
});

const canMoveNext = computed(() => stepIsValid.value && !form.processing);

const formatNumber = (value) =>
    Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });

const formatDate = (value) => {
    if (!value) return 'Not set';
    return new Date(value).toLocaleDateString();
};

const statusClass = (status) =>
    status === 'expired'
        ? 'bg-red-100 text-red-700'
        : 'bg-emerald-100 text-emerald-700';

const toggleModule = (key) => {
    if (form.selected_modules.includes(key)) {
        form.selected_modules = form.selected_modules.filter((item) => item !== key);
        return;
    }

    form.selected_modules = [...form.selected_modules, key];
};

const applyPreset = (preset) => {
    form.company_type = preset.company_type;
    form.company_sector = preset.company_sector;
    form.selected_modules = [...preset.modules];
};

const applyRecommendedModules = () => {
    const preset = (props.options.presets || []).find(
        (item) => item.company_type === form.company_type && item.company_sector === form.company_sector,
    );

    if (preset) {
        form.selected_modules = [...preset.modules];
        return;
    }

    const filtered = (props.options.modules || [])
        .filter((module) => !module.company_types || module.company_types.includes(form.company_type))
        .map((module) => module.key);
    form.selected_modules = filtered;
};

const nextStep = () => {
    if (!canMoveNext.value || currentStep.value >= steps.length - 1) {
        return;
    }

    currentStep.value += 1;
};

const previousStep = () => {
    if (currentStep.value <= 0) {
        return;
    }

    currentStep.value -= 1;
};

const submit = () => {
    form.post(route('superadmin.demo-workspaces.store'), {
        preserveScroll: true,
        onSuccess: () => {
            currentStep.value = 0;
            form.reset();
            form.selected_modules = [...(props.defaults?.selected_modules || [])];
        },
    });
};

const updateExpiration = (workspace) => {
    const expiresAt = dateDrafts[workspace.id];
    if (!expiresAt) return;

    router.patch(route('superadmin.demo-workspaces.expiration.update', workspace.id), {
        expires_at: expiresAt,
    }, {
        preserveScroll: true,
    });
};

const purgeWorkspace = (workspace) => {
    if (!window.confirm(`Delete the demo "${workspace.company_name}" and all its tenant data?`)) {
        return;
    }

    router.delete(route('superadmin.demo-workspaces.destroy', workspace.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Demo Workspaces" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        Demo Workspaces
                    </h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        Configure personalized demo tenants for prospects, set an expiration, and purge them cleanly when the trial ends.
                    </p>
                </div>
            </section>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 md:gap-3">
                <div class="rounded-sm border border-t-4 border-t-emerald-600 border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Active demos</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ formatNumber(stats.active) }}</p>
                </div>
                <div class="rounded-sm border border-t-4 border-t-blue-600 border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Total workspaces</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ formatNumber(stats.total) }}</p>
                </div>
                <div class="rounded-sm border border-t-4 border-t-amber-600 border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Expiring in 3 days</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ formatNumber(stats.expiring_soon) }}</p>
                </div>
                <div class="rounded-sm border border-t-4 border-t-rose-600 border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Expired</p>
                    <p class="mt-1 text-2xl font-semibold text-stone-800 dark:text-neutral-100">{{ formatNumber(stats.expired) }}</p>
                </div>
            </div>

            <section class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="border-b border-stone-200 p-5 dark:border-neutral-700">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">Create a demo tenant</h2>
                            <p class="text-sm text-stone-600 dark:text-neutral-400">
                                Use the stepper below to configure modules, realism, and expiration before provisioning the demo.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <div
                                v-for="(step, index) in steps"
                                :key="step.key"
                                class="inline-flex items-center gap-2 rounded-sm border px-3 py-2 text-xs font-medium"
                                :class="index === currentStep ? 'border-green-600 bg-green-50 text-green-700' : index < currentStep ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-stone-200 bg-white text-stone-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300'">
                                <span class="inline-flex size-5 items-center justify-center rounded-full border text-[11px]"
                                    :class="index <= currentStep ? 'border-current' : 'border-stone-300 dark:border-neutral-600'">
                                    {{ index + 1 }}
                                </span>
                                {{ step.label }}
                            </div>
                        </div>
                    </div>
                </div>

                <form class="space-y-6 p-5" @submit.prevent="submit">
                    <div v-if="currentStep === 0" class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-2">
                            <FloatingInput v-model="form.prospect_name" label="Prospect name" required />
                            <FloatingInput v-model="form.prospect_email" type="email" label="Prospect email" />
                            <FloatingInput v-model="form.prospect_company" label="Prospect company" />
                            <FloatingInput v-model="form.company_name" label="Demo company name" required />
                            <FloatingSelect v-model="form.company_type" :options="options.company_types" label="Company type" required />
                            <FloatingSelect v-model="form.company_sector" :options="options.sectors" label="Industry / sector" required />
                        </div>
                        <FloatingTextarea v-model="form.desired_outcome" label="Prospect needs / demo objective" />
                    </div>

                    <div v-else-if="currentStep === 1" class="space-y-5">
                        <div class="grid gap-3 lg:grid-cols-3">
                            <button
                                v-for="preset in options.presets"
                                :key="preset.key"
                                type="button"
                                class="rounded-sm border border-stone-200 bg-stone-50 p-4 text-left hover:border-green-400 hover:bg-green-50 dark:border-neutral-700 dark:bg-neutral-800 dark:hover:border-green-500 dark:hover:bg-neutral-800"
                                @click="applyPreset(preset)">
                                <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ preset.label }}</div>
                                <p class="mt-1 text-xs text-stone-600 dark:text-neutral-400">{{ preset.description }}</p>
                            </button>
                        </div>

                        <div class="flex justify-end">
                            <button type="button"
                                class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                @click="applyRecommendedModules">
                                Use recommended stack
                            </button>
                        </div>

                        <div v-for="[category, modules] in moduleGroups" :key="category" class="space-y-3">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ category }}</h3>
                            </div>
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                <button
                                    v-for="module in modules"
                                    :key="module.key"
                                    type="button"
                                    class="rounded-sm border p-4 text-left transition"
                                    :class="form.selected_modules.includes(module.key) ? 'border-green-600 bg-green-50' : 'border-stone-200 bg-white hover:border-green-300 dark:border-neutral-700 dark:bg-neutral-900'"
                                    @click="toggleModule(module.key)">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ module.label }}</div>
                                            <p class="mt-1 text-xs text-stone-600 dark:text-neutral-400">{{ module.description }}</p>
                                        </div>
                                        <span class="inline-flex size-5 items-center justify-center rounded-sm border text-[11px] font-semibold"
                                            :class="form.selected_modules.includes(module.key) ? 'border-green-600 bg-green-600 text-white' : 'border-stone-300 text-transparent dark:border-neutral-600'">
                                            ✓
                                        </span>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-else-if="currentStep === 2" class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <FloatingSelect v-model="form.seed_profile" :options="options.seed_profiles" label="Seed profile" required option-value="value" option-label="label" />
                            <FloatingInput v-model="form.team_size" type="number" label="Target staff seats" required />
                            <FloatingSelect v-model="form.locale" :options="options.locales" label="Workspace language" required />
                            <FloatingSelect v-model="form.timezone" :options="options.timezones" label="Timezone" required />
                        </div>
                        <FloatingTextarea v-model="form.internal_notes" label="Internal admin notes" />

                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Provisioning preview</div>
                            <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">{{ selectedProfile?.description }}</p>
                            <div class="mt-4 grid gap-2 md:grid-cols-3 xl:grid-cols-5">
                                <div v-for="(value, key) in selectedProfile?.counts || {}" :key="key" class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-900">
                                    <div class="text-[11px] uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">{{ key }}</div>
                                    <div class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ value }}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-2">
                            <FloatingInput v-model="form.expires_at" type="date" label="Expiration date" required />
                            <FloatingInput :model-value="selectedModuleLabels.join(', ')" label="Selected modules" readonly />
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Prospect</div>
                                    <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ form.prospect_name || '-' }}</div>
                                    <div class="text-sm text-stone-600 dark:text-neutral-400">{{ form.prospect_email || 'No email provided' }}</div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Demo company</div>
                                    <div class="mt-1 text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ form.company_name || '-' }}</div>
                                    <div class="text-sm text-stone-600 dark:text-neutral-400">{{ form.company_type }} / {{ form.company_sector }}</div>
                                </div>
                            </div>
                            <div class="mt-4">
                                <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Modules</div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span v-for="label in selectedModuleLabels" :key="label" class="rounded-full bg-white px-3 py-1 text-xs text-stone-700 ring-1 ring-stone-200 dark:bg-neutral-900 dark:text-neutral-200 dark:ring-neutral-700">
                                        {{ label }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 border-t border-stone-200 pt-5 sm:flex-row sm:items-center sm:justify-between dark:border-neutral-700">
                        <div class="text-sm text-red-600" v-if="Object.keys(form.errors || {}).length">
                            {{ Object.values(form.errors)[0] }}
                        </div>
                        <div class="flex gap-2 sm:ms-auto">
                            <button
                                type="button"
                                class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                :disabled="currentStep === 0 || form.processing"
                                @click="previousStep">
                                Back
                            </button>
                            <button
                                v-if="currentStep < steps.length - 1"
                                type="button"
                                class="rounded-sm bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="!canMoveNext"
                                @click="nextStep">
                                Continue
                            </button>
                            <button
                                v-else
                                type="submit"
                                class="rounded-sm bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="form.processing">
                                {{ form.processing ? 'Provisioning…' : 'Create demo workspace' }}
                            </button>
                        </div>
                    </div>
                </form>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="border-b border-stone-200 p-5 dark:border-neutral-700">
                    <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">Existing demo workspaces</h2>
                </div>
                <div class="grid gap-4 p-5 xl:grid-cols-2">
                    <article v-for="workspace in workspaces.data" :key="workspace.id" class="rounded-sm border border-stone-200 bg-stone-50 p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="flex flex-col gap-3 border-b border-stone-200 pb-4 dark:border-neutral-700 md:flex-row md:items-start md:justify-between">
                            <div>
                                <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">{{ workspace.company_type }} / {{ workspace.company_sector || 'general' }}</div>
                                <h3 class="mt-1 text-lg font-semibold text-stone-800 dark:text-neutral-100">{{ workspace.company_name }}</h3>
                                <p class="text-sm text-stone-600 dark:text-neutral-400">
                                    {{ workspace.prospect_name }}<span v-if="workspace.prospect_email"> · {{ workspace.prospect_email }}</span>
                                </p>
                            </div>
                            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium" :class="statusClass(workspace.status)">
                                {{ workspace.status }}
                            </span>
                        </div>

                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <div class="space-y-2 text-sm text-stone-600 dark:text-neutral-400">
                                <div><span class="font-medium text-stone-800 dark:text-neutral-100">Access:</span> {{ workspace.access_email }}</div>
                                <div><span class="font-medium text-stone-800 dark:text-neutral-100">Password:</span> {{ workspace.access_password }}</div>
                                <div><span class="font-medium text-stone-800 dark:text-neutral-100">Expires:</span> {{ formatDate(workspace.expires_at) }}</div>
                                <div><span class="font-medium text-stone-800 dark:text-neutral-100">Seed profile:</span> {{ workspace.seed_profile }}</div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Seed summary</div>
                                <div class="mt-2 grid grid-cols-2 gap-2 text-sm">
                                    <div v-for="(value, key) in workspace.seed_summary" :key="key" class="rounded-sm border border-stone-200 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-900">
                                        <div class="text-[11px] uppercase tracking-[0.15em] text-stone-500 dark:text-neutral-400">{{ key }}</div>
                                        <div class="font-semibold text-stone-800 dark:text-neutral-100">{{ value }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <div class="text-xs uppercase tracking-[0.2em] text-stone-500 dark:text-neutral-400">Modules</div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <span v-for="label in workspace.module_labels" :key="label" class="rounded-full bg-white px-3 py-1 text-xs text-stone-700 ring-1 ring-stone-200 dark:bg-neutral-900 dark:text-neutral-200 dark:ring-neutral-700">
                                    {{ label }}
                                </span>
                            </div>
                        </div>

                        <div class="mt-4 flex flex-col gap-3 border-t border-stone-200 pt-4 dark:border-neutral-700">
                            <div class="flex flex-col gap-2 md:flex-row md:items-center">
                                <input
                                    v-model="dateDrafts[workspace.id]"
                                    type="date"
                                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200" />
                                <button
                                    type="button"
                                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                    @click="updateExpiration(workspace)">
                                    Update expiration
                                </button>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <Link
                                    v-if="can_view_tenant"
                                    :href="route('superadmin.tenants.show', workspace.owner_user_id)"
                                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                                    Open tenant
                                </Link>
                                <Link
                                    v-if="can_impersonate"
                                    :href="route('superadmin.tenants.impersonate', workspace.owner_user_id)"
                                    method="post"
                                    as="button"
                                    type="button"
                                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                                    Impersonate
                                </Link>
                                <button
                                    type="button"
                                    class="rounded-sm bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700"
                                    @click="purgeWorkspace(workspace)">
                                    Delete all data
                                </button>
                            </div>
                        </div>
                    </article>

                    <div v-if="!workspaces.data.length" class="rounded-sm border border-dashed border-stone-300 bg-stone-50 p-8 text-center text-sm text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                        No demo workspace has been provisioned yet.
                    </div>
                </div>

                <div v-if="workspaces.links?.length" class="flex flex-wrap items-center gap-2 border-t border-stone-200 px-5 py-4 text-sm dark:border-neutral-700">
                    <template v-for="link in workspaces.links" :key="link.url || link.label">
                        <span v-if="!link.url" v-html="link.label" class="rounded-sm border border-stone-200 px-2 py-1 text-stone-400 dark:border-neutral-700"></span>
                        <Link v-else :href="link.url" v-html="link.label" preserve-scroll class="rounded-sm border border-stone-200 px-2 py-1 dark:border-neutral-700"
                            :class="link.active ? 'border-transparent bg-green-600 text-white' : 'text-stone-700 hover:bg-stone-50 dark:text-neutral-200 dark:hover:bg-neutral-800'" />
                    </template>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
