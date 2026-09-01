<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminDataTableActions from '@/Components/DataTable/AdminDataTableActions.vue';

const props = defineProps({
    customer: {
        type: Object,
        required: true,
    },
    canEdit: {
        type: Boolean,
        default: false,
    },
    canDelete: {
        type: Boolean,
        default: false,
    },
    customerIndexContext: {
        type: Object,
        default: () => ({
            profile: 'generic',
            sector: null,
            capabilities: {},
            actions: {},
        }),
    },
});

defineEmits(['toggle-archive', 'delete']);

const { t } = useI18n();
const appointmentProfile = computed(() => props.customerIndexContext?.profile === 'appointment');
const capabilities = computed(() => props.customerIndexContext?.capabilities || {});
const contextActions = computed(() => props.customerIndexContext?.actions || {});
const operationalSummary = computed(() => props.customer?.operational_summary || {});
const canBook = computed(() => (
    appointmentProfile.value
    && Boolean(capabilities.value.reservations)
    && contextActions.value.can_book === true
));
const canViewBilling = computed(() => (
    appointmentProfile.value
    && Boolean(capabilities.value.invoices)
    && contextActions.value.can_view_billing === true
));
const unpaidInvoiceId = computed(() => Number(operationalSummary.value.unpaid_invoice_id || 0));
const reservationHref = computed(() => route('reservation.index', {
    customer_id: props.customer.id,
    open_editor: 1,
}));
const billingHref = computed(() => (
    unpaidInvoiceId.value > 0
        ? route('invoice.show', unpaidInvoiceId.value)
        : route('invoice.index', { customer_id: props.customer.id })
));
</script>

<template>
    <AdminDataTableActions :label="t('customers.actions.view')">
        <Link
            :href="route('customer.show', customer)"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ t('customers.actions.view') }}
        </Link>
        <Link
            v-if="canBook"
            :href="reservationHref"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ t('customers.appointment.actions.book') }}
        </Link>
        <Link
            v-if="canViewBilling"
            :href="billingHref"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ unpaidInvoiceId > 0
                ? t('customers.appointment.actions.checkout')
                : t('customers.appointment.actions.billing') }}
        </Link>
        <a
            v-if="appointmentProfile && customer.email"
            :href="`mailto:${customer.email}`"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ t('customers.appointment.actions.email') }}
        </a>
        <a
            v-if="appointmentProfile && customer.phone"
            :href="`tel:${customer.phone}`"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ t('customers.appointment.actions.call') }}
        </a>
        <div v-if="appointmentProfile && canEdit" class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
        <Link
            v-if="canEdit"
            :href="route('customer.edit', customer)"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            {{ t('customers.actions.edit') }}
        </Link>
        <button
            v-if="canEdit"
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback"
            data-tone="warning"
            @click="$emit('toggle-archive')"
        >
            {{ customer.is_active ? t('customers.actions.archive') : t('customers.actions.restore') }}
        </button>
        <div v-if="canDelete && (canEdit || appointmentProfile)" class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
        <button
            v-if="canDelete"
            type="button"
            class="flex w-full items-center gap-x-3 rounded-sm px-2 py-1.5 text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback"
            data-tone="danger"
            @click="$emit('delete')"
        >
            {{ t('customers.actions.delete') }}
        </button>
    </AdminDataTableActions>
</template>
