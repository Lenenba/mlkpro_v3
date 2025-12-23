<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StarRating from '@/Components/UI/StarRating.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    invoice: Object,
});

const page = usePage();
const companyName = computed(() => page.props.auth?.account?.company?.name || 'Entreprise');
const companyLogo = computed(() => page.props.auth?.account?.company?.logo_url || null);

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

const customer = computed(() => props.invoice.customer || null);
const work = computed(() => props.invoice.work || null);
const invoiceItems = computed(() => props.invoice.items || []);
const isTaskBased = computed(() => invoiceItems.value.length > 0);
const lineItems = computed(() => (isTaskBased.value ? invoiceItems.value : work.value?.products || []));

const customerName = computed(() => {
    const data = customer.value;
    if (!data) {
        return 'Customer';
    }
    const name = data.company_name || `${data.first_name || ''} ${data.last_name || ''}`.trim();
    return name || 'Customer';
});

const contactName = computed(() => {
    const data = customer.value;
    if (!data) {
        return '-';
    }
    const name = `${data.first_name || ''} ${data.last_name || ''}`.trim();
    return name || data.company_name || '-';
});

const contactEmail = computed(() => customer.value?.email || '-');
const contactPhone = computed(() => customer.value?.phone || '-');

const fallbackProperty = computed(() => {
    const properties = customer.value?.properties || [];
    return properties.find((item) => item.is_default) || properties[0] || null;
});

const property = computed(() => work.value?.quote?.property || fallbackProperty.value);

const ratingValue = computed(() => {
    const ratings = work.value?.ratings || [];
    if (!ratings.length) {
        return null;
    }
    const sum = ratings.reduce((total, rating) => total + Number(rating.rating || 0), 0);
    return sum / ratings.length;
});

const ratingCount = computed(() => work.value?.ratings?.length || 0);

const formatDate = (value) => humanizeDate(value) || '-';

const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const formatShortDate = (value) => {
    if (!value) {
        return '-';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return date.toLocaleDateString();
};

const formatTimeRange = (start, end) => {
    const startLabel = start ? String(start).slice(0, 5) : '';
    const endLabel = end ? String(end).slice(0, 5) : '';
    if (!startLabel && !endLabel) {
        return '-';
    }
    if (!endLabel) {
        return startLabel;
    }
    return `${startLabel} - ${endLabel}`;
};

const invoiceSubtotal = computed(() => {
    if (isTaskBased.value) {
        return invoiceItems.value.reduce((sum, item) => sum + Number(item.total || 0), 0);
    }

    if (work.value?.subtotal !== undefined && work.value?.subtotal !== null) {
        return Number(work.value.subtotal || 0);
    }

    return Number(props.invoice.total || 0);
});

const lineItemColspan = computed(() => (isTaskBased.value ? 5 : 4));
</script>

<template>
    <Head :title="`Invoice ${invoice.number || invoice.id}`" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-6xl space-y-5">
            <div class="p-5 space-y-3 flex flex-col bg-gray-100 border border-gray-100 rounded-sm shadow-sm">
                <div class="flex flex-wrap justify-between items-center gap-3">
                    <div class="flex items-center gap-3">
                        <img v-if="companyLogo"
                            :src="companyLogo"
                            :alt="companyName"
                            class="h-12 w-12 rounded-sm border border-stone-200 object-cover dark:border-neutral-700" />
                        <div>
                            <p class="text-xs uppercase text-gray-500">
                                {{ companyName }}
                            </p>
                            <h1 class="text-xl inline-block font-semibold text-gray-800">
                                Invoice For {{ customerName }}
                            </h1>
                            <p class="text-sm text-gray-600">
                                {{ work?.job_title || 'Job' }}
                            </p>
                        </div>
                    </div>
                    <span class="py-1.5 px-3 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-full"
                        :class="{
                            'bg-stone-100 text-stone-700': invoice.status === 'draft',
                            'bg-sky-100 text-sky-700': invoice.status === 'sent',
                            'bg-amber-100 text-amber-800': invoice.status === 'partial',
                            'bg-emerald-100 text-emerald-800': invoice.status === 'paid',
                            'bg-rose-100 text-rose-800': invoice.status === 'overdue' || invoice.status === 'void',
                        }">
                        {{ invoice.status }}
                    </span>
                </div>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="col-span-2 space-x-2">
                        <div class="bg-white rounded-sm border border-gray-100 p-4 mb-4">
                            {{ work?.job_title || 'Job' }}
                        </div>
                        <div class="flex flex-row space-x-6">
                            <div class="lg:col-span-3">
                                <p>Property address</p>
                                <div v-if="property" class="space-y-1">
                                    <div class="text-xs text-gray-600">{{ property.country }}</div>
                                    <div class="text-xs text-gray-600">{{ property.street1 }}</div>
                                    <div class="text-xs text-gray-600">{{ property.state }} - {{ property.zip }}</div>
                                </div>
                                <div v-else class="text-xs text-gray-600">
                                    No property selected.
                                </div>
                            </div>
                            <div class="lg:col-span-3">
                                <p>Contact details</p>
                                <div class="text-xs text-gray-600">
                                    {{ contactName }}
                                </div>
                                <div class="text-xs text-gray-600">
                                    {{ contactEmail }}
                                </div>
                                <div class="text-xs text-gray-600">
                                    {{ contactPhone }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white p-4 rounded-sm border border-gray-100">
                        <p>Invoice details</p>
                        <div class="text-xs text-gray-600 flex justify-between">
                            <span>Invoice:</span>
                            <span>{{ invoice.number || invoice.id }}</span>
                        </div>
                        <div class="text-xs text-gray-600 flex justify-between">
                            <span>Issued:</span>
                            <span>{{ formatDate(invoice.created_at) }}</span>
                        </div>
                        <div class="text-xs text-gray-600 flex justify-between">
                            <span>Balance due:</span>
                            <span>{{ formatCurrency(invoice.balance_due) }}</span>
                        </div>
                        <div class="text-xs text-gray-600 flex justify-between">
                            <span>Job rating:</span>
                            <span class="flex items-center gap-2">
                                <StarRating :value="ratingValue" show-value empty-label="No rating yet" />
                                <span v-if="ratingCount" class="text-xs text-gray-500">({{ ratingCount }})</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="p-5 space-y-3 flex flex-col bg-white border border-gray-100 rounded-sm shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr>
                                <th class="min-w-[300px] text-left text-sm font-medium text-gray-800">
                                    {{ isTaskBased ? 'Tasks' : 'Product/Services' }}
                                </th>
                                <th v-if="isTaskBased" class="text-left text-sm font-medium text-gray-800">Date</th>
                                <th v-if="isTaskBased" class="text-left text-sm font-medium text-gray-800">Time</th>
                                <th v-if="isTaskBased" class="text-left text-sm font-medium text-gray-800">Assignee</th>
                                <th v-if="isTaskBased" class="text-left text-sm font-medium text-gray-800">Total</th>
                                <th v-else class="text-left text-sm font-medium text-gray-800">Qty.</th>
                                <th v-else class="text-left text-sm font-medium text-gray-800">Unit cost</th>
                                <th v-else class="text-left text-sm font-medium text-gray-800">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="item in lineItems" :key="item.id">
                                <template v-if="isTaskBased">
                                    <td class="px-4 py-3">{{ item.title }}</td>
                                    <td class="px-4 py-3">{{ formatShortDate(item.scheduled_date) }}</td>
                                    <td class="px-4 py-3">{{ formatTimeRange(item.start_time, item.end_time) }}</td>
                                    <td class="px-4 py-3">{{ item.assignee_name || '-' }}</td>
                                    <td class="px-4 py-3">{{ formatCurrency(item.total) }}</td>
                                </template>
                                <template v-else>
                                    <td class="px-4 py-3">{{ item.name }}</td>
                                    <td class="px-4 py-3">{{ item.pivot?.quantity ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ formatCurrency(item.pivot?.price) }}</td>
                                    <td class="px-4 py-3">{{ formatCurrency(item.pivot?.total) }}</td>
                                </template>
                            </tr>
                            <tr v-if="!lineItems.length">
                                <td :colspan="lineItemColspan" class="px-4 py-4 text-sm text-gray-500">
                                    No line items.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="p-5 grid grid-cols-2 gap-4 justify-between bg-white border border-gray-100 rounded-sm shadow-sm">
                <div></div>
                <div class="border-l border-gray-200 rounded-sm p-4">
                    <div class="py-4 grid grid-cols-2 gap-x-4">
                        <div class="col-span-1">
                            <p class="text-sm text-gray-500">Subtotal:</p>
                        </div>
                        <div class="col-span-1 flex justify-end">
                            <p class="text-sm text-green-600">
                                {{ formatCurrency(invoiceSubtotal) }}
                            </p>
                        </div>
                    </div>

                    <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-gray-200">
                        <div class="col-span-1">
                            <p class="text-sm text-gray-500">Paid:</p>
                        </div>
                        <div class="flex justify-end">
                            <p class="text-sm text-gray-800">
                                {{ formatCurrency(invoice.amount_paid) }}
                            </p>
                        </div>
                    </div>

                    <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-gray-200">
                        <div class="col-span-1">
                            <p class="text-sm text-gray-800 font-bold">Total amount:</p>
                        </div>
                        <div class="flex justify-end">
                            <p class="text-sm text-gray-800 font-bold">
                                {{ formatCurrency(invoice.total) }}
                            </p>
                        </div>
                    </div>

                    <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-gray-200">
                        <div class="col-span-1">
                            <p class="text-sm text-gray-500">Balance due:</p>
                        </div>
                        <div class="flex justify-end">
                            <p class="text-sm text-gray-800">
                                {{ formatCurrency(invoice.balance_due) }}
                            </p>
                        </div>
                    </div>
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
                                    {{ formatCurrency(payment.amount) }} - {{ payment.method || 'method' }}
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
