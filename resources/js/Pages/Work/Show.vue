<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    work: Object,
    customer: Object,
});

const createInvoice = () => {
    router.post(route('invoice.store-from-work', props.work.id), {}, { preserveScroll: true });
};
</script>
<template>

    <Head title="Show jobs" />
    <AuthenticatedLayout>
        <div class="max-w-5xl mx-auto space-y-4">
            <div class="p-5 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ work.job_title }}
                        </h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ work.number }} · {{ work.status }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link :href="route('work.edit', work.id)"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700">
                            Edit job
                        </Link>
                        <Link v-if="work.invoice" :href="route('invoice.show', work.invoice.id)"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700">
                            View invoice
                        </Link>
                        <button v-else type="button" @click="createInvoice"
                            class="py-2 px-3 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700">
                            Create invoice
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">Customer</h2>
                    <p class="text-sm text-stone-800 dark:text-neutral-100">
                        {{ customer.company_name || `${customer.first_name} ${customer.last_name}` }}
                    </p>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">{{ customer.email }}</p>
                </div>
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">Dates</h2>
                    <p class="text-sm text-stone-800 dark:text-neutral-100">Start: {{ work.start_date || '-' }}</p>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">End: {{ work.end_date || '-' }}</p>
                </div>
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">Totals</h2>
                    <p class="text-sm text-stone-800 dark:text-neutral-100">Subtotal: ${{ Number(work.subtotal || 0).toFixed(2) }}</p>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Total: ${{ Number(work.total || 0).toFixed(2) }}</p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
