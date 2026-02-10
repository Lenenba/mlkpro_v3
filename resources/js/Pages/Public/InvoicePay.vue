<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    invoice: Object,
    company: Object,
    allowPayment: Boolean,
    paymentMessage: String,
    paymentUrl: String,
    stripeCheckoutUrl: String,
    tips: {
        type: Object,
        default: () => ({}),
    },
});

const form = useForm({
    amount: props.invoice?.balance_due || '',
    tip_enabled: false,
    tip_mode: 'none',
    tip_percent: null,
    tip_amount: 0,
    method: '',
    reference: '',
    notes: '',
});

const stripeProcessing = ref(false);
const tipEnabled = ref(false);
const tipMode = ref('percent');
const tipPercent = ref(Number(props.tips?.default_percent ?? 10));
const tipFixedAmount = ref(0);

const quickPercents = computed(() => props.tips?.quick_percents || [5, 10, 15, 20]);
const quickFixedAmounts = computed(() => props.tips?.quick_fixed_amounts || [2, 5, 10]);
const maxTipPercent = computed(() => Number(props.tips?.max_percent ?? 30));
const maxTipFixed = computed(() => Number(props.tips?.max_fixed_amount ?? 200));
const balanceDue = computed(() => {
    const value = Number(props.invoice?.balance_due || 0);
    return Number.isFinite(value) ? Math.max(0, value) : 0;
});

const paymentAmount = computed(() => {
    const value = Number(form.amount || 0);
    return Number.isFinite(value) ? Math.max(0, value) : 0;
});
const exceedsBalanceDue = computed(() => paymentAmount.value > balanceDue.value + 0.0001);
const paymentRemainingAfterThis = computed(() => roundMoney(Math.max(0, balanceDue.value - paymentAmount.value)));
const isPartialPayment = computed(() => paymentAmount.value > 0 && paymentAmount.value < balanceDue.value);
const canSubmitPayment = computed(() => props.allowPayment && paymentAmount.value >= 0.01 && !exceedsBalanceDue.value);

const normalizedTipPercent = computed(() => {
    const value = Number(tipPercent.value || 0);
    if (!Number.isFinite(value)) {
        return 0;
    }
    return Math.max(0, Math.min(value, maxTipPercent.value));
});

const normalizedTipFixedAmount = computed(() => {
    const value = Number(tipFixedAmount.value || 0);
    if (!Number.isFinite(value)) {
        return 0;
    }
    return Math.max(0, Math.min(value, maxTipFixed.value));
});

const tipAmount = computed(() => {
    if (!tipEnabled.value) {
        return 0;
    }

    if (tipMode.value === 'percent') {
        return roundMoney(paymentAmount.value * (normalizedTipPercent.value / 100));
    }

    return roundMoney(normalizedTipFixedAmount.value);
});

const totalChargeAmount = computed(() => roundMoney(paymentAmount.value + tipAmount.value));

const tipPayload = computed(() => {
    if (!tipEnabled.value) {
        return {
            tip_enabled: false,
            tip_mode: 'none',
            tip_percent: null,
            tip_amount: 0,
        };
    }

    if (tipMode.value === 'percent') {
        return {
            tip_enabled: true,
            tip_mode: 'percent',
            tip_percent: normalizedTipPercent.value,
            tip_amount: 0,
        };
    }

    return {
        tip_enabled: true,
        tip_mode: 'fixed',
        tip_percent: null,
        tip_amount: normalizedTipFixedAmount.value,
    };
});

const applyTipPayloadToForm = () => {
    form.tip_enabled = tipPayload.value.tip_enabled;
    form.tip_mode = tipPayload.value.tip_mode;
    form.tip_percent = tipPayload.value.tip_percent;
    form.tip_amount = tipPayload.value.tip_amount;
};

const setPaymentAmount = (value) => {
    const normalized = roundMoney(Math.max(0, Math.min(Number(value || 0), balanceDue.value)));
    form.amount = normalized > 0 ? normalized.toFixed(2) : '';
};

const startStripeCheckout = () => {
    if (!canSubmitPayment.value || !props.stripeCheckoutUrl || stripeProcessing.value) {
        return;
    }

    const payload = {
        amount: paymentAmount.value,
        ...tipPayload.value,
    };

    stripeProcessing.value = true;
    router.post(props.stripeCheckoutUrl, payload, {
        preserveScroll: true,
        onFinish: () => {
            stripeProcessing.value = false;
        },
    });
};

const submitPayment = () => {
    if (!canSubmitPayment.value || form.processing) {
        return;
    }

    applyTipPayloadToForm();
    form.post(props.paymentUrl, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('method', 'reference', 'notes');
        },
    });
};

const customer = computed(() => props.invoice?.customer || null);
const work = computed(() => props.invoice?.work || null);
const recentPayments = computed(() => props.invoice?.payments || []);

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

const roundMoney = (value) => Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100;

const paymentTipAmount = (payment) => {
    const value = Number(payment?.tip_amount || 0);
    return Number.isFinite(value) ? Math.max(0, value) : 0;
};

const paymentChargedTotal = (payment) => {
    const fallback = Number(payment?.amount || 0) + paymentTipAmount(payment);
    const value = Number(payment?.charged_total ?? fallback);
    return Number.isFinite(value) ? value : fallback;
};
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
                        loading="lazy"
                        decoding="async"
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

            <div class="rounded-sm border border-stone-200 p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800">Pay this invoice</h2>
                    <span v-if="!allowPayment" class="text-xs text-stone-500">{{ paymentMessage }}</span>
                </div>

                <form class="space-y-3" @submit.prevent="submitPayment">
                    <input
                        v-model="form.amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                        :disabled="!allowPayment"
                        class="w-full rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-700 disabled:opacity-60"
                        placeholder="Amount"
                    />
                    <div v-if="form.errors.amount" class="text-xs text-red-600">{{ form.errors.amount }}</div>
                    <div v-else-if="exceedsBalanceDue" class="text-xs text-red-600">
                        Amount cannot exceed {{ formatCurrency(balanceDue) }}.
                    </div>
                    <p class="text-[11px] text-stone-500">
                        You can pay the full balance or choose a partial amount.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            :disabled="!allowPayment"
                            class="rounded-sm border border-stone-200 bg-white px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-60"
                            @click="setPaymentAmount(balanceDue)"
                        >
                            Pay full balance
                        </button>
                        <button
                            type="button"
                            :disabled="!allowPayment"
                            class="rounded-sm border border-stone-200 bg-white px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-60"
                            @click="setPaymentAmount(roundMoney(balanceDue * 0.5))"
                        >
                            50%
                        </button>
                        <button
                            type="button"
                            :disabled="!allowPayment"
                            class="rounded-sm border border-stone-200 bg-white px-2.5 py-1 text-xs font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-60"
                            @click="setPaymentAmount(roundMoney(balanceDue * 0.25))"
                        >
                            25%
                        </button>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3">
                        <div class="text-sm font-semibold text-stone-800">Add a tip? (optional)</div>
                        <p class="text-xs text-stone-500">The tip goes directly to the team member serving you.</p>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button
                                type="button"
                                :disabled="!allowPayment"
                                class="rounded-sm border px-3 py-1.5 text-xs font-medium disabled:opacity-60"
                                :class="tipEnabled ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-stone-200 bg-white text-stone-700'"
                                @click="tipEnabled = true"
                            >
                                Yes, add a tip
                            </button>
                            <button
                                type="button"
                                :disabled="!allowPayment"
                                class="rounded-sm border px-3 py-1.5 text-xs font-medium disabled:opacity-60"
                                :class="!tipEnabled ? 'border-stone-900 bg-stone-900 text-white' : 'border-stone-200 bg-white text-stone-700'"
                                @click="tipEnabled = false"
                            >
                                No thanks
                            </button>
                        </div>

                        <div v-if="tipEnabled" class="mt-3 space-y-3">
                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    :disabled="!allowPayment"
                                    class="rounded-sm border px-3 py-1.5 text-xs font-medium disabled:opacity-60"
                                    :class="tipMode === 'percent' ? 'border-slate-900 bg-slate-900 text-white' : 'border-stone-200 bg-white text-stone-700'"
                                    @click="tipMode = 'percent'"
                                >
                                    Percentage (%)
                                </button>
                                <button
                                    type="button"
                                    :disabled="!allowPayment"
                                    class="rounded-sm border px-3 py-1.5 text-xs font-medium disabled:opacity-60"
                                    :class="tipMode === 'fixed' ? 'border-slate-900 bg-slate-900 text-white' : 'border-stone-200 bg-white text-stone-700'"
                                    @click="tipMode = 'fixed'"
                                >
                                    Fixed amount ($)
                                </button>
                            </div>

                            <div v-if="tipMode === 'percent'" class="space-y-2">
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="value in quickPercents"
                                        :key="`tip-percent-${value}`"
                                        type="button"
                                        :disabled="!allowPayment"
                                        class="rounded-sm border px-2.5 py-1 text-xs font-medium disabled:opacity-60"
                                        :class="normalizedTipPercent === value ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-stone-200 bg-white text-stone-700'"
                                        @click="tipPercent = value"
                                    >
                                        {{ value }}%
                                    </button>
                                </div>
                                <input
                                    v-model="tipPercent"
                                    type="number"
                                    min="0"
                                    :max="maxTipPercent"
                                    step="0.01"
                                    :disabled="!allowPayment"
                                    class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 disabled:opacity-60"
                                    placeholder="Other %"
                                />
                                <div class="text-[11px] text-stone-500">Max: {{ maxTipPercent }}%</div>
                                <div v-if="form.errors.tip_percent" class="text-xs text-red-600">{{ form.errors.tip_percent }}</div>
                            </div>

                            <div v-else class="space-y-2">
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="value in quickFixedAmounts"
                                        :key="`tip-fixed-${value}`"
                                        type="button"
                                        :disabled="!allowPayment"
                                        class="rounded-sm border px-2.5 py-1 text-xs font-medium disabled:opacity-60"
                                        :class="normalizedTipFixedAmount === value ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-stone-200 bg-white text-stone-700'"
                                        @click="tipFixedAmount = value"
                                    >
                                        ${{ value }}
                                    </button>
                                </div>
                                <input
                                    v-model="tipFixedAmount"
                                    type="number"
                                    min="0"
                                    :max="maxTipFixed"
                                    step="0.01"
                                    :disabled="!allowPayment"
                                    class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 disabled:opacity-60"
                                    placeholder="Other amount"
                                />
                                <div class="text-[11px] text-stone-500">Max: {{ formatCurrency(maxTipFixed) }}</div>
                                <div v-if="form.errors.tip_amount" class="text-xs text-red-600">{{ form.errors.tip_amount }}</div>
                            </div>
                        </div>

                        <p class="mt-2 text-[11px] text-stone-500">You can change or remove tip before paying.</p>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600">
                        <div class="flex items-center justify-between">
                            <span>Subtotal</span>
                            <span class="font-medium text-stone-800">{{ formatCurrency(paymentAmount) }}</span>
                        </div>
                        <div class="mt-1 flex items-center justify-between">
                            <span>Tip (optional)</span>
                            <span class="font-medium text-stone-800">{{ formatCurrency(tipAmount) }}</span>
                        </div>
                        <div class="mt-2 flex items-center justify-between border-t border-stone-200 pt-2">
                            <span class="font-semibold text-stone-800">Total charge</span>
                            <span class="font-semibold text-stone-900">{{ formatCurrency(totalChargeAmount) }}</span>
                        </div>
                    </div>
                    <p class="text-[11px] text-stone-500">
                        <template v-if="isPartialPayment">
                            Partial payment selected. Remaining balance after this payment:
                            <span class="font-semibold text-stone-700">{{ formatCurrency(paymentRemainingAfterThis) }}</span>
                        </template>
                        <template v-else>
                            Remaining balance after this payment:
                            <span class="font-semibold text-stone-700">{{ formatCurrency(paymentRemainingAfterThis) }}</span>
                        </template>
                    </p>

                    <div class="space-y-2 pt-1">
                        <button
                            type="submit"
                            :disabled="!canSubmitPayment || form.processing"
                            class="inline-flex w-full items-center justify-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                        >
                            Pay invoice
                        </button>
                        <button
                            v-if="stripeCheckoutUrl"
                            type="button"
                            :disabled="!canSubmitPayment || stripeProcessing"
                            class="inline-flex w-full items-center justify-center rounded-sm border border-transparent bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                            @click="startStripeCheckout"
                        >
                            Pay with Stripe
                        </button>
                    </div>

                    <details class="rounded-sm border border-stone-200 bg-stone-50 p-3">
                        <summary class="cursor-pointer text-xs font-semibold text-stone-700">
                            Payment details (optional)
                        </summary>
                        <div class="mt-3 space-y-2">
                            <input
                                v-model="form.method"
                                type="text"
                                :disabled="!allowPayment"
                                class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 disabled:opacity-60"
                                placeholder="Method (card, transfer)"
                            />
                            <input
                                v-model="form.reference"
                                type="text"
                                :disabled="!allowPayment"
                                class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 disabled:opacity-60"
                                placeholder="Reference"
                            />
                            <textarea
                                v-model="form.notes"
                                rows="2"
                                :disabled="!allowPayment"
                                class="w-full rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 disabled:opacity-60"
                                placeholder="Notes"
                            ></textarea>
                        </div>
                    </details>
                </form>
            </div>

            <div v-if="recentPayments.length" class="rounded-sm border border-stone-200 p-4">
                <h2 class="text-sm font-semibold text-stone-800">Recent payments</h2>
                <div class="mt-3 space-y-2">
                    <div
                        v-for="payment in recentPayments"
                        :key="`public-payment-${payment.id}`"
                        class="rounded-sm border border-stone-200 bg-stone-50 p-3"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-stone-700">
                            <span>{{ formatDate(payment.paid_at) }}</span>
                            <span class="rounded-sm bg-stone-200 px-2 py-0.5 text-xs font-medium text-stone-700">
                                {{ payment.status || 'completed' }}
                            </span>
                        </div>
                        <div class="mt-2 space-y-1 text-xs text-stone-600">
                            <div class="flex items-center justify-between">
                                <span>Subtotal</span>
                                <span class="font-medium text-stone-800">{{ formatCurrency(payment.amount) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Tip</span>
                                <span class="font-medium text-stone-800">{{ formatCurrency(paymentTipAmount(payment)) }}</span>
                            </div>
                            <div class="flex items-center justify-between border-t border-stone-200 pt-1">
                                <span class="font-semibold text-stone-800">Total paid</span>
                                <span class="font-semibold text-stone-900">{{ formatCurrency(paymentChargedTotal(payment)) }}</span>
                            </div>
                            <div v-if="payment.tip_assignee_name" class="text-[11px] text-stone-500">
                                Tip assigned to: {{ payment.tip_assignee_name }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
