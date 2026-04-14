<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
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
</script>

<template>
    <Head :title="$t('expenses.title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
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
