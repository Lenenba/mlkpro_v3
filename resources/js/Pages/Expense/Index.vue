<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import ExpenseStats from '@/Components/UI/ExpenseStats.vue';
import ExpenseTable from '@/Pages/Expense/UI/ExpenseTable.vue';

defineProps({
    expenses: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    count: {
        type: Number,
        required: true,
    },
    stats: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        required: true,
    },
    paymentMethods: {
        type: Array,
        required: true,
    },
    statuses: {
        type: Array,
        required: true,
    },
    recurrenceFrequencies: {
        type: Array,
        required: true,
    },
    teamMembers: {
        type: Array,
        default: () => [],
    },
    linkOptions: {
        type: Object,
        default: () => ({
            customers: [],
            works: [],
            sales: [],
            invoices: [],
            campaigns: [],
        }),
    },
    canUseAiIntake: {
        type: Boolean,
        default: false,
    },
    tenantCurrencyCode: {
        type: String,
        default: 'CAD',
    },
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
    <Head :title="$t('expenses.title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
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
            <ExpenseStats :stats="stats" :tenant-currency-code="tenantCurrencyCode" />

            <ExpenseTable
                :expenses="expenses"
                :filters="filters"
                :count="count"
                :categories="categories"
                :payment-methods="paymentMethods"
                :statuses="statuses"
                :recurrence-frequencies="recurrenceFrequencies"
                :team-members="teamMembers"
                :link-options="linkOptions"
                :can-use-ai-intake="canUseAiIntake"
                :tenant-currency-code="tenantCurrencyCode"
            />
        </div>
    </AuthenticatedLayout>
</template>
