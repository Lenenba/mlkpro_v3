<script setup>
import { computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    quote: Object,
    company: Object,
    allowAccept: Boolean,
    allowDecline: Boolean,
    statusMessage: String,
    acceptUrl: String,
    declineUrl: String,
});

const form = useForm({
    deposit_amount: props.quote?.initial_deposit || '',
    method: '',
    reference: '',
});

const submitAccept = () => {
    if (!props.allowAccept || form.processing) {
        return;
    }
    form.post(props.acceptUrl, {
        preserveScroll: true,
    });
};

const submitDecline = () => {
    if (!props.allowDecline) {
        return;
    }
    router.post(props.declineUrl, {}, { preserveScroll: true });
};

const formatDate = (value) => humanizeDate(value) || '-';
const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const customerName = computed(() => {
    const customer = props.quote?.customer;
    if (!customer) {
        return 'Customer';
    }
    const name = customer.company_name || `${customer.first_name || ''} ${customer.last_name || ''}`.trim();
    return name || 'Customer';
});

const property = computed(() => props.quote?.property || null);
const items = computed(() => props.quote?.items || []);
const taxes = computed(() => props.quote?.taxes || []);
</script>

<template>
    <Head title="Quote review" />

    <GuestLayout :card-class="'mt-6 w-full max-w-4xl rounded-sm border border-stone-200 bg-white px-6 py-6 shadow-md'">
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
                        <div class="text-xs uppercase tracking-wide text-stone-500">Quote</div>
                        <div class="text-lg font-semibold text-stone-800">
                            {{ company?.name || 'Company' }}
                        </div>
                    </div>
                </div>
                <span class="rounded-sm bg-stone-100 px-2 py-1 text-xs font-semibold text-stone-700">
                    {{ quote?.status || 'sent' }}
                </span>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-sm border border-stone-200 p-3 text-sm text-stone-700">
                    <div class="text-xs text-stone-500">Quote</div>
                    <div class="font-semibold">{{ quote?.number || `#${quote?.id}` }}</div>
                    <div class="mt-2 text-xs text-stone-500">Created</div>
                    <div class="text-sm">{{ formatDate(quote?.created_at) }}</div>
                </div>
                <div class="rounded-sm border border-stone-200 p-3 text-sm text-stone-700">
                    <div class="text-xs text-stone-500">Customer</div>
                    <div class="font-semibold">{{ customerName }}</div>
                    <div class="mt-2 text-xs text-stone-500">Job</div>
                    <div class="text-sm">{{ quote?.job_title || 'Service' }}</div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 p-3 text-sm text-stone-700">
                <div class="text-xs text-stone-500">Property</div>
                <div v-if="property" class="text-sm">
                    <div>{{ property.street1 }}</div>
                    <div v-if="property.street2">{{ property.street2 }}</div>
                    <div>
                        {{ property.city || '' }} {{ property.state || '' }} {{ property.zip || '' }}
                    </div>
                    <div>{{ property.country || '' }}</div>
                </div>
                <div v-else class="text-sm text-stone-500">No property selected.</div>
            </div>

            <div class="rounded-sm border border-stone-200 p-4">
                <div class="text-sm font-semibold text-stone-800">Items</div>
                <div class="mt-3 space-y-2">
                    <div v-for="item in items" :key="item.id"
                        class="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm">
                        <div>
                            <div class="font-medium text-stone-800">{{ item.name }}</div>
                            <div v-if="item.description" class="text-xs text-stone-500">{{ item.description }}</div>
                        </div>
                        <div class="text-xs text-stone-500">
                            {{ item.quantity }} x {{ formatCurrency(item.price) }}
                        </div>
                        <div class="font-semibold text-stone-800">
                            {{ formatCurrency(item.total) }}
                        </div>
                    </div>
                    <div v-if="!items.length" class="text-sm text-stone-500">No items listed.</div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 p-4 text-sm text-stone-700">
                <div class="flex items-center justify-between">
                    <span>Subtotal</span>
                    <span class="font-semibold">{{ formatCurrency(quote?.subtotal) }}</span>
                </div>
                <div v-for="tax in taxes" :key="tax.id" class="mt-2 flex items-center justify-between text-xs">
                    <span>{{ tax.name }} ({{ tax.rate.toFixed(2) }}%)</span>
                    <span>{{ formatCurrency(tax.amount) }}</span>
                </div>
                <div class="mt-3 flex items-center justify-between text-sm font-semibold">
                    <span>Total</span>
                    <span>{{ formatCurrency(quote?.total) }}</span>
                </div>
                <div v-if="quote?.initial_deposit > 0" class="mt-2 text-xs text-stone-500">
                    Required deposit: {{ formatCurrency(quote.initial_deposit) }}
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 p-4">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800">Respond to this quote</h2>
                    <span v-if="statusMessage" class="text-xs text-stone-500">{{ statusMessage }}</span>
                </div>
                <form class="space-y-3" @submit.prevent="submitAccept">
                    <div v-if="quote?.initial_deposit > 0">
                        <label class="block text-xs text-stone-500">Deposit amount</label>
                        <input
                            v-model="form.deposit_amount"
                            type="number"
                            min="0"
                            step="0.01"
                            :disabled="!allowAccept"
                            class="mt-1 w-full rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-700 disabled:opacity-60"
                        />
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <input
                            v-model="form.method"
                            type="text"
                            :disabled="!allowAccept"
                            class="w-full rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-700 disabled:opacity-60"
                            placeholder="Payment method (optional)"
                        />
                        <input
                            v-model="form.reference"
                            type="text"
                            :disabled="!allowAccept"
                            class="w-full rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-700 disabled:opacity-60"
                            placeholder="Reference (optional)"
                        />
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="submit"
                            :disabled="!allowAccept || form.processing"
                            class="inline-flex items-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                        >
                            Accept quote
                        </button>
                        <button
                            type="button"
                            :disabled="!allowDecline"
                            @click="submitDecline"
                            class="inline-flex items-center rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-50"
                        >
                            Decline quote
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </GuestLayout>
</template>
