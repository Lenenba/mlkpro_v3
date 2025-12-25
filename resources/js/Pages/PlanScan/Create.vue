<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ValidationSummary from '@/Components/ValidationSummary.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingNumberInput from '@/Components/FloatingNumberInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    customers: Array,
    tradeOptions: Array,
    priorityOptions: Array,
});

const form = useForm({
    plan_file: null,
    job_title: '',
    trade_type: props.tradeOptions?.[0]?.id || 'plumbing',
    customer_id: null,
    property_id: null,
    surface_m2: '',
    rooms: '',
    priority: 'balanced',
});

const selectedCustomer = computed(() =>
    props.customers?.find((customer) => customer.id === form.customer_id) || null
);

const customerOptions = computed(() =>
    (props.customers || []).map((customer) => ({
        id: customer.id,
        name: customer.company_name || `${customer.first_name} ${customer.last_name}`,
    }))
);

const properties = computed(() => selectedCustomer.value?.properties || []);

watch(
    () => form.customer_id,
    () => {
        const defaultProperty = properties.value.find((property) => property.is_default);
        form.property_id = defaultProperty?.id || properties.value[0]?.id || null;
    }
);

const handleFileChange = (event) => {
    const file = event.target.files[0];
    if (file) {
        form.plan_file = file;
    }
};

const submit = () => {
    form
        .transform((data) => ({
            ...data,
            plan_file: data.plan_file instanceof File ? data.plan_file : null,
        }))
        .post(route('plan-scans.store'));
};
</script>

<template>
    <Head title="New plan scan" />
    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-5">
            <div
                class="p-5 space-y-1 flex flex-col bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-900 dark:border-neutral-700"
            >
                <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">New plan scan</h1>
                <p class="text-sm text-stone-500 dark:text-neutral-400">
                    Upload a plan, select the trade, and generate quote variants.
                </p>
            </div>

            <form class="space-y-5" @submit.prevent="submit">
                <ValidationSummary :errors="form.errors" />

                <div
                    class="p-5 space-y-4 flex flex-col bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-900 dark:border-neutral-700"
                >
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-xs text-stone-500 dark:text-neutral-400">Plan file (PDF or image)</label>
                            <input
                                type="file"
                                accept="application/pdf,image/*"
                                class="mt-2 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-500 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                @change="handleFileChange"
                            />
                            <p class="mt-1 text-xs text-stone-400 dark:text-neutral-500">Max 5MB.</p>
                        </div>
                        <FloatingInput v-model="form.job_title" label="Project title" />
                        <FloatingSelect v-model="form.trade_type" label="Trade" :options="tradeOptions" />
                        <FloatingSelect v-model="form.priority" label="Priority" :options="priorityOptions" />
                    </div>
                </div>

                <div
                    class="p-5 space-y-3 flex flex-col bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-900 dark:border-neutral-700"
                >
                    <h2 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">Customer</h2>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <FloatingSelect v-model="form.customer_id" label="Customer" :options="customerOptions" />
                        <div>
                            <label class="text-xs text-stone-500 dark:text-neutral-400">Property</label>
                            <select
                                v-model.number="form.property_id"
                                class="mt-2 w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 focus:border-green-500 focus:ring-green-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                            >
                                <option value="">No property</option>
                                <option v-for="property in properties" :key="property.id" :value="property.id">
                                    {{ property.street1 }}{{ property.city ? `, ${property.city}` : '' }}
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <div
                    class="p-5 space-y-3 flex flex-col bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-900 dark:border-neutral-700"
                >
                    <h2 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">Plan metrics (optional)</h2>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <FloatingNumberInput v-model="form.surface_m2" label="Surface (m2)" :step="0.1" />
                        <FloatingNumberInput v-model="form.rooms" label="Rooms" />
                    </div>
                </div>

                <div class="flex justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-sm bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                    >
                        Run scan
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
