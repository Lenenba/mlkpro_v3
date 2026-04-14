<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import InvoiceStats from '@/Components/UI/InvoiceStats.vue';
import InvoiceTable from '@/Pages/Invoice/UI/InvoiceTable.vue';

const props = defineProps({
    invoices: Object,
    filters: Object,
    stats: Object,
    count: Number,
    customers: Array,
});

const page = usePage();
const canOpenFinanceApprovals = computed(() => {
    const account = page.props.auth?.account;
    const permissions = account?.team?.permissions || [];

    if (account?.is_client) {
        return false;
    }

    if (account?.is_owner) {
        return Boolean(account?.features?.expenses || account?.features?.invoices);
    }

    return permissions.includes('expenses.approve')
        || permissions.includes('expenses.approve_high')
        || permissions.includes('invoices.approve')
        || permissions.includes('invoices.approve_high');
});
</script>

<template>
    <Head :title="$t('invoices.title')" />
    <AuthenticatedLayout>
        <div class="space-y-3">
            <div
                v-if="canOpenFinanceApprovals"
                class="flex justify-end"
            >
                <Link
                    :href="route('finance-approvals.index')"
                    class="inline-flex items-center gap-x-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                >
                    {{ $t('finance_approvals.title') }}
                </Link>
            </div>
            <InvoiceStats :stats="stats" />
        </div>
        <div class="mt-3">
            <InvoiceTable :invoices="invoices" :filters="filters" :customers="customers" />
        </div>
    </AuthenticatedLayout>
</template>
