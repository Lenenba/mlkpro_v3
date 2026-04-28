<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import ExpenseStats from '@/Components/UI/ExpenseStats.vue';
import ExpensePeriodRecap from '@/Pages/Expense/UI/ExpensePeriodRecap.vue';
import ExpensePettyCashPanel from '@/Pages/Expense/UI/ExpensePettyCashPanel.vue';
import ExpenseTable from '@/Pages/Expense/UI/ExpenseTable.vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

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
    periodRecap: {
        type: Object,
        required: true,
    },
    pettyCash: {
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

const { t } = useI18n();
const activeTab = ref('list');
const tabs = computed(() => [
    {
        key: 'list',
        label: t('expenses.tabs.list'),
    },
    {
        key: 'recap',
        label: t('expenses.tabs.recap'),
    },
    {
        key: 'petty_cash',
        label: t('expenses.tabs.petty_cash'),
    },
]);

const tabClass = (tab) => (
    activeTab.value === tab
        ? 'border-transparent bg-red-600 text-white shadow-sm'
        : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800'
);
</script>

<template>
    <Head :title="$t('expenses.title')" />

    <AuthenticatedLayout>
        <div class="space-y-5">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    class="inline-flex items-center rounded-sm border px-3.5 py-2 text-sm font-medium transition"
                    :class="tabClass(tab.key)"
                    @click="activeTab = tab.key"
                >
                    {{ tab.label }}
                </button>
            </div>

            <template v-if="activeTab === 'recap'">
                <ExpensePeriodRecap
                    :period-recap="periodRecap"
                    :filters="filters"
                    :tenant-currency-code="tenantCurrencyCode"
                />
            </template>

            <template v-else-if="activeTab === 'petty_cash'">
                <ExpensePettyCashPanel
                    :petty-cash="pettyCash"
                    :filters="filters"
                    :tenant-currency-code="tenantCurrencyCode"
                />
            </template>

            <template v-else>
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
                    :petty-cash="pettyCash"
                    :can-use-ai-intake="canUseAiIntake"
                    :tenant-currency-code="tenantCurrencyCode"
                />
            </template>
        </div>
    </AuthenticatedLayout>
</template>
