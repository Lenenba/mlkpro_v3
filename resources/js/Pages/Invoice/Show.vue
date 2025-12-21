<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    invoice: Object,
});

const form = useForm({
    amount: '',
    method: '',
    reference: '',
    paid_at: '',
    notes: '',
});

const submitPayment = () => {
    form.post(route('payment.store', props.invoice.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('amount', 'method', 'reference', 'paid_at', 'notes');
        },
    });
};

const formatDate = (value) => {
    if (!value) {
        return '-';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }
    return date.toLocaleDateString();
};
</script>

<template>
    <Head :title="`Invoice ${invoice.number || invoice.id}`" />
    <AuthenticatedLayout>
        <div class="max-w-5xl mx-auto space-y-4">
            <div class="p-5 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ invoice.number || `Invoice #${invoice.id}` }}
                        </h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            Job: {{ invoice.work?.job_title ?? '-' }}
                        </p>
                    </div>
                    <span
                        class="py-1.5 px-3 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-full"
                        :class="{
                            'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-200': invoice.status === 'draft',
                            'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-200': invoice.status === 'sent',
                            'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200': invoice.status === 'partial',
                            'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200': invoice.status === 'paid',
                            'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200': invoice.status === 'overdue' || invoice.status === 'void',
                        }">
                        {{ invoice.status }}
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">Customer</h2>
                    <p class="text-sm text-stone-800 dark:text-neutral-100">
                        {{ invoice.customer?.company_name || `${invoice.customer?.first_name ?? ''} ${invoice.customer?.last_name ?? ''}`.trim() || '-' }}
                    </p>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                        {{ invoice.customer?.email ?? '-' }}
                    </p>
                </div>
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">Totals</h2>
                    <p class="text-sm text-stone-800 dark:text-neutral-100">Total: ${{ Number(invoice.total || 0).toFixed(2) }}</p>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Paid: ${{ Number(invoice.amount_paid || 0).toFixed(2) }}</p>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">Balance: ${{ Number(invoice.balance_due || 0).toFixed(2) }}</p>
                </div>
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm text-stone-500 dark:text-neutral-400">Created</h2>
                    <p class="text-sm text-stone-800 dark:text-neutral-100">{{ formatDate(invoice.created_at) }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100 mb-3">Payments</h2>
                    <div v-if="invoice.payments?.length" class="space-y-2">
                        <div v-for="payment in invoice.payments" :key="payment.id"
                            class="flex items-center justify-between p-2 rounded-sm bg-stone-50 dark:bg-neutral-900">
                            <div>
                                <p class="text-sm text-stone-700 dark:text-neutral-200">
                                    ${{ Number(payment.amount || 0).toFixed(2) }} · {{ payment.method || 'method' }}
                                </p>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">
                                    {{ formatDate(payment.paid_at) }}
                                </p>
                            </div>
                            <span class="text-xs text-stone-500 dark:text-neutral-400">{{ payment.status }}</span>
                        </div>
                    </div>
                    <p v-else class="text-sm text-stone-500 dark:text-neutral-400">No payments yet.</p>
                </div>

                <div class="p-4 bg-white border border-stone-200 rounded-sm shadow-sm dark:bg-neutral-800 dark:border-neutral-700">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100 mb-3">Add payment</h2>
                    <form @submit.prevent="submitPayment" class="space-y-3">
                        <input v-model="form.amount" type="number" min="0" step="0.01"
                            class="w-full py-2 px-3 bg-stone-100 border-transparent rounded-lg text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                            placeholder="Amount">
                        <input v-model="form.method" type="text"
                            class="w-full py-2 px-3 bg-stone-100 border-transparent rounded-lg text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                            placeholder="Method (card, transfer)">
                        <input v-model="form.reference" type="text"
                            class="w-full py-2 px-3 bg-stone-100 border-transparent rounded-lg text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                            placeholder="Reference">
                        <input v-model="form.paid_at" type="date"
                            class="w-full py-2 px-3 bg-stone-100 border-transparent rounded-lg text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200">
                        <textarea v-model="form.notes" rows="2"
                            class="w-full py-2 px-3 bg-stone-100 border-transparent rounded-lg text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-700 dark:text-neutral-200"
                            placeholder="Notes"></textarea>
                        <button type="submit"
                            class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            Record payment
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
