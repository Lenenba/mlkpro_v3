<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    invoice: Object,
    company: Object,
    allowPayment: Boolean,
    paymentMessage: String,
    paymentUrl: String,
    stripeCheckoutUrl: String,
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);

const form = useForm({
    amount: props.invoice?.balance_due || '',
    method: '',
    reference: '',
    notes: '',
});

const stripeProcessing = ref(false);
const startStripeCheckout = () => {
    if (!props.allowPayment || !props.stripeCheckoutUrl || stripeProcessing.value) {
        return;
    }

    stripeProcessing.value = true;
    router.post(props.stripeCheckoutUrl, {}, {
        preserveScroll: true,
        onFinish: () => {
            stripeProcessing.value = false;
        },
    });
};

const submitPayment = () => {
    if (!props.allowPayment || form.processing) {
        return;
    }
    form.post(props.paymentUrl, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('method', 'reference', 'notes');
        },
    });
};

const customer = computed(() => props.invoice?.customer || null);
const work = computed(() => props.invoice?.work || null);

const customerName = computed(() => {
    const data = customer.value;
    if (!data) {
        return 'Customer';
    }
    const name = data.company_name || `${data.first_name || ''} ${data.last_name || ''}`.trim();
    return name || 'Customer';
});

const statusClass = (status) => {
    switch (status) {
        case 'paid':
            return 'bg-emerald-100 text-emerald-800';
        case 'partial':
            return 'bg-amber-100 text-amber-800';
        case 'overdue':
            return 'bg-red-100 text-red-800';
        case 'sent':
            return 'bg-blue-100 text-blue-800';
        default:
            return 'bg-stone-100 text-stone-700';
    }
};

const formatDate = (value) => humanizeDate(value) || '-';
const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
</script>

<template>
    <Head title="Invoice payment" />

    <GuestLayout :card-class="'mt-6 w-full max-w-3xl rounded-sm border border-stone-200 bg-white px-6 py-6 shadow-md'">
        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <img
                        v-if="company?.logo_url"
                        :src="company.logo_url"
                        :alt="company?.name || 'Company'"
                        class="h-10 w-10 rounded-sm border border-stone-200 object-cover"
                    />
                    <div>
                        <div class="text-xs uppercase tracking-wide text-stone-500">Invoice</div>
                        <div class="text-lg font-semibold text-stone-800">
                            {{ company?.name || 'Company' }}
                        </div>
                    </div>
                </div>
                <span class="rounded-sm px-2 py-1 text-xs font-semibold" :class="statusClass(invoice.status)">
                    {{ invoice.status || 'sent' }}
                </span>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-sm border border-stone-200 p-3 text-sm text-stone-700">
                    <div class="text-xs text-stone-500">Invoice</div>
                    <div class="font-semibold">{{ invoice.number || `#${invoice.id}` }}</div>
                    <div class="mt-2 text-xs text-stone-500">Created</div>
                    <div class="text-sm">{{ formatDate(invoice.created_at) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 p-3 text-sm text-stone-700">
                    <div class="text-xs text-stone-500">Customer</div>
                    <div class="font-semibold">{{ customerName }}</div>
                    <div class="mt-2 text-xs text-stone-500">Job</div>
                    <div class="text-sm">{{ work?.job_title || 'Service' }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="rounded-sm border border-stone-200 p-3 text-sm text-stone-700">
                    <div class="text-xs text-stone-500">Total</div>
                    <div class="font-semibold">{{ formatCurrency(invoice.total) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 p-3 text-sm text-stone-700">
                    <div class="text-xs text-stone-500">Paid</div>
                    <div class="font-semibold">{{ formatCurrency(invoice.amount_paid) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 p-3 text-sm text-stone-700">
                    <div class="text-xs text-stone-500">Balance due</div>
                    <div class="font-semibold">{{ formatCurrency(invoice.balance_due) }}</div>
                </div>
            </div>

            <div v-if="flashSuccess" class="rounded-sm border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                {{ flashSuccess }}
            </div>
            <div v-if="flashError" class="rounded-sm border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                {{ flashError }}
            </div>

            <div class="rounded-sm border border-stone-200 p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800">Pay this invoice</h2>
                    <span v-if="!allowPayment" class="text-xs text-stone-500">{{ paymentMessage }}</span>
                </div>
                <div v-if="stripeCheckoutUrl" class="mb-4">
                    <button
                        type="button"
                        :disabled="!allowPayment || stripeProcessing"
                        class="inline-flex w-full items-center justify-center rounded-sm border border-transparent bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        @click="startStripeCheckout"
                    >
                        Pay with Stripe
                    </button>
                </div>
                <form class="space-y-3" @submit.prevent="submitPayment">
                    <input
                        v-model="form.amount"
                        type="number"
                        min="0"
                        step="0.01"
                        :disabled="!allowPayment"
                        class="w-full rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-700 disabled:opacity-60"
                        placeholder="Amount"
                    />
                    <input
                        v-model="form.method"
                        type="text"
                        :disabled="!allowPayment"
                        class="w-full rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-700 disabled:opacity-60"
                        placeholder="Method (card, transfer)"
                    />
                    <input
                        v-model="form.reference"
                        type="text"
                        :disabled="!allowPayment"
                        class="w-full rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-700 disabled:opacity-60"
                        placeholder="Reference"
                    />
                    <textarea
                        v-model="form.notes"
                        rows="2"
                        :disabled="!allowPayment"
                        class="w-full rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-700 disabled:opacity-60"
                        placeholder="Notes"
                    ></textarea>
                    <button
                        type="submit"
                        :disabled="!allowPayment || form.processing"
                        class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                    >
                        Record payment
                    </button>
                </form>
            </div>
        </div>
    </GuestLayout>
</template>
