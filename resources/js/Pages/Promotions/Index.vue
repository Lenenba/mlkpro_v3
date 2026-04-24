<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppModal from '@/Components/Modal.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useCurrencyFormatter } from '@/utils/currency';

const props = defineProps({
    promotions: {
        type: Array,
        default: () => [],
    },
    customers: {
        type: Array,
        default: () => [],
    },
    products: {
        type: Array,
        default: () => [],
    },
    services: {
        type: Array,
        default: () => [],
    },
    enums: {
        type: Object,
        default: () => ({}),
    },
    pulse: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();
const { formatCurrency } = useCurrencyFormatter();

const modalOpen = ref(false);
const editingPromotionId = ref(null);

const today = () => new Date().toISOString().slice(0, 10);

const blankPromotion = () => ({
    name: '',
    code: '',
    target_type: 'global',
    target_id: '',
    discount_type: 'percentage',
    discount_value: '',
    start_date: today(),
    end_date: today(),
    status: 'active',
    usage_limit: '',
    minimum_order_amount: '',
});

const form = useForm(blankPromotion());

const targetTypeOptions = computed(() => [
    { value: 'global', label: t('promotions.targets.global') },
    { value: 'client', label: t('promotions.targets.client') },
    { value: 'product', label: t('promotions.targets.product') },
    { value: 'service', label: t('promotions.targets.service') },
]);

const discountTypeOptions = computed(() => [
    { value: 'percentage', label: t('promotions.discount_types.percentage') },
    { value: 'fixed', label: t('promotions.discount_types.fixed') },
]);

const statusOptions = computed(() => [
    { value: 'active', label: t('promotions.statuses.active') },
    { value: 'inactive', label: t('promotions.statuses.inactive') },
]);

const currentTargetOptions = computed(() => {
    if (form.target_type === 'client') {
        return props.customers.map((customer) => ({ value: customer.id, label: customer.label }));
    }
    if (form.target_type === 'product') {
        return props.products.map((product) => ({ value: product.id, label: product.label }));
    }
    if (form.target_type === 'service') {
        return props.services.map((service) => ({ value: service.id, label: service.label }));
    }
    return [];
});

const totalPromotions = computed(() => props.promotions.length);
const activePromotions = computed(() => props.promotions.filter((promotion) => promotion.status === 'active').length);
const validPromotions = computed(() => props.promotions.filter((promotion) => promotion.is_currently_valid).length);
const codedPromotions = computed(() => props.promotions.filter((promotion) => promotion.code).length);
const canOpenPulseComposer = computed(() => Boolean(props.pulse?.can_open));

const targetTypeLabel = (type) => {
    const match = targetTypeOptions.value.find((option) => option.value === type);
    return match?.label || type;
};

const discountTypeLabel = (type) => {
    const match = discountTypeOptions.value.find((option) => option.value === type);
    return match?.label || type;
};

const resetForm = () => {
    const defaults = blankPromotion();
    Object.keys(defaults).forEach((key) => {
        form[key] = defaults[key];
    });
    form.clearErrors();
    editingPromotionId.value = null;
};

const openCreate = () => {
    resetForm();
    modalOpen.value = true;
};

const openEdit = (promotion) => {
    editingPromotionId.value = promotion.id;
    form.name = promotion.name || '';
    form.code = promotion.code || '';
    form.target_type = promotion.target_type || 'global';
    form.target_id = promotion.target_id || '';
    form.discount_type = promotion.discount_type || 'percentage';
    form.discount_value = promotion.discount_value ?? '';
    form.start_date = promotion.start_date || today();
    form.end_date = promotion.end_date || today();
    form.status = promotion.status || 'active';
    form.usage_limit = promotion.usage_limit ?? '';
    form.minimum_order_amount = promotion.minimum_order_amount ?? '';
    form.clearErrors();
    modalOpen.value = true;
};

const closeModal = () => {
    modalOpen.value = false;
    resetForm();
};

const transformPayload = (payload) => ({
    ...payload,
    code: String(payload.code || '').trim() || null,
    target_id: payload.target_type === 'global' ? null : payload.target_id,
    usage_limit: payload.usage_limit === '' || payload.usage_limit === null ? null : Number(payload.usage_limit),
    minimum_order_amount: payload.minimum_order_amount === '' || payload.minimum_order_amount === null
        ? null
        : Number(payload.minimum_order_amount),
    discount_value: Number(payload.discount_value),
});

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => closeModal(),
    };

    if (editingPromotionId.value) {
        form.transform(transformPayload).put(route('promotions.update', editingPromotionId.value), options);
        return;
    }

    form.transform(transformPayload).post(route('promotions.store'), options);
};

const toggleStatus = (promotion) => {
    const nextStatus = promotion.status === 'active' ? 'inactive' : 'active';
    router.patch(route('promotions.status.update', promotion.id), {
        status: nextStatus,
    }, {
        preserveScroll: true,
    });
};

const deletePromotion = (promotion) => {
    if (!window.confirm(t('promotions.actions.delete_confirm', { name: promotion.name }))) {
        return;
    }

    router.delete(route('promotions.destroy', promotion.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="t('promotions.title')" />

        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ t('promotions.title') }}
                    </h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        {{ t('promotions.subtitle') }}
                    </p>
                </div>
                <PrimaryButton type="button" @click="openCreate">
                    {{ t('promotions.actions.new') }}
                </PrimaryButton>
            </div>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ t('promotions.stats.total') }}
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                        {{ totalPromotions }}
                    </div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ t('promotions.stats.active') }}
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                        {{ activePromotions }}
                    </div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ t('promotions.stats.valid_now') }}
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                        {{ validPromotions }}
                    </div>
                </div>
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ t('promotions.stats.codes') }}
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-stone-900 dark:text-neutral-100">
                        {{ codedPromotions }}
                    </div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="border-b border-stone-200 px-4 py-3 dark:border-neutral-700">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                        {{ t('promotions.list.title') }}
                    </h2>
                </div>

                <div v-if="!promotions.length" class="px-4 py-10 text-center text-sm text-stone-500 dark:text-neutral-400">
                    {{ t('promotions.list.empty') }}
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-stone-50 dark:bg-neutral-800/40">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-stone-500 dark:text-neutral-400">{{ t('promotions.table.name') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-stone-500 dark:text-neutral-400">{{ t('promotions.table.target') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-stone-500 dark:text-neutral-400">{{ t('promotions.table.discount') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-stone-500 dark:text-neutral-400">{{ t('promotions.table.window') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-stone-500 dark:text-neutral-400">{{ t('promotions.table.usage') }}</th>
                                <th class="px-4 py-3 text-left font-medium text-stone-500 dark:text-neutral-400">{{ t('promotions.table.status') }}</th>
                                <th class="px-4 py-3 text-right font-medium text-stone-500 dark:text-neutral-400">{{ t('promotions.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-for="promotion in promotions" :key="promotion.id">
                                <td class="px-4 py-3 align-top">
                                    <div class="font-medium text-stone-800 dark:text-neutral-100">
                                        {{ promotion.name }}
                                    </div>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        <span v-if="promotion.code">{{ promotion.code }}</span>
                                        <span v-else>{{ t('promotions.table.no_code') }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top text-stone-700 dark:text-neutral-200">
                                    <div>{{ targetTypeLabel(promotion.target_type) }}</div>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ promotion.target_label }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top text-stone-700 dark:text-neutral-200">
                                    <div>
                                        <span v-if="promotion.discount_type === 'percentage'">{{ promotion.discount_value }}%</span>
                                        <span v-else>{{ formatCurrency(promotion.discount_value) }}</span>
                                    </div>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ discountTypeLabel(promotion.discount_type) }}
                                    </div>
                                    <div v-if="promotion.minimum_order_amount !== null" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ t('promotions.table.minimum_order', { amount: formatCurrency(promotion.minimum_order_amount) }) }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top text-stone-700 dark:text-neutral-200">
                                    <div>{{ promotion.start_date }}</div>
                                    <div class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ promotion.end_date }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top text-stone-700 dark:text-neutral-200">
                                    <div v-if="promotion.usage_limit !== null">
                                        {{ promotion.usage_count }} / {{ promotion.usage_limit }}
                                    </div>
                                    <div v-else>
                                        {{ t('promotions.table.unlimited') }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="promotion.status === 'active'
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200'
                                            : 'bg-stone-100 text-stone-700 dark:bg-neutral-800 dark:text-neutral-300'"
                                    >
                                        {{ promotion.status === 'active' ? t('promotions.statuses.active') : t('promotions.statuses.inactive') }}
                                    </span>
                                    <div v-if="promotion.is_currently_valid" class="mt-2 text-xs text-emerald-600 dark:text-emerald-300">
                                        {{ t('promotions.table.valid_now') }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            class="rounded-sm border border-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                            @click="openEdit(promotion)"
                                        >
                                            {{ t('promotions.actions.edit') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-sm border border-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                                            @click="toggleStatus(promotion)"
                                        >
                                            {{ promotion.status === 'active' ? t('promotions.actions.deactivate') : t('promotions.actions.activate') }}
                                        </button>
                                        <Link
                                            v-if="canOpenPulseComposer"
                                            :href="route('social.composer', { source_type: 'promotion', source_id: promotion.id })"
                                            class="rounded-sm border border-green-200 px-3 py-1.5 text-xs font-semibold text-green-700 hover:bg-green-50 dark:border-green-500/30 dark:text-green-200 dark:hover:bg-green-500/10"
                                        >
                                            {{ t('social.composer_manager.actions.publish_with_pulse') }}
                                        </Link>
                                        <button
                                            type="button"
                                            class="rounded-sm border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 dark:border-red-500/30 dark:text-red-300 dark:hover:bg-red-500/10"
                                            @click="deletePromotion(promotion)"
                                        >
                                            {{ t('promotions.actions.delete') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <AppModal :show="modalOpen" max-width="3xl" @close="closeModal">
            <div class="space-y-4 p-6">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-stone-800 dark:text-neutral-100">
                            {{ editingPromotionId ? t('promotions.modal.edit_title') : t('promotions.modal.create_title') }}
                        </h2>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            {{ t('promotions.modal.subtitle') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-sm border border-stone-200 px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        @click="closeModal"
                    >
                        {{ t('promotions.actions.cancel') }}
                    </button>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <FloatingInput v-model="form.name" :label="t('promotions.form.name')" />
                        <InputError class="mt-1" :message="form.errors.name" />
                    </div>
                    <div>
                        <FloatingInput v-model="form.code" :label="t('promotions.form.code')" />
                        <InputError class="mt-1" :message="form.errors.code" />
                    </div>
                    <div>
                        <FloatingSelect
                            v-model="form.target_type"
                            :label="t('promotions.form.target_type')"
                            :options="targetTypeOptions"
                        />
                        <InputError class="mt-1" :message="form.errors.target_type" />
                    </div>
                    <div v-if="form.target_type !== 'global'">
                        <FloatingSelect
                            v-model="form.target_id"
                            :label="t('promotions.form.target')"
                            :options="currentTargetOptions"
                        />
                        <InputError class="mt-1" :message="form.errors.target_id" />
                    </div>
                    <div>
                        <FloatingSelect
                            v-model="form.discount_type"
                            :label="t('promotions.form.discount_type')"
                            :options="discountTypeOptions"
                        />
                        <InputError class="mt-1" :message="form.errors.discount_type" />
                    </div>
                    <div>
                        <FloatingInput
                            v-model="form.discount_value"
                            type="number"
                            step="0.01"
                            min="0"
                            :label="t('promotions.form.discount_value')"
                        />
                        <InputError class="mt-1" :message="form.errors.discount_value" />
                    </div>
                    <div>
                        <FloatingInput v-model="form.start_date" type="date" :label="t('promotions.form.start_date')" />
                        <InputError class="mt-1" :message="form.errors.start_date" />
                    </div>
                    <div>
                        <FloatingInput v-model="form.end_date" type="date" :label="t('promotions.form.end_date')" />
                        <InputError class="mt-1" :message="form.errors.end_date" />
                    </div>
                    <div>
                        <FloatingSelect
                            v-model="form.status"
                            :label="t('promotions.form.status')"
                            :options="statusOptions"
                        />
                        <InputError class="mt-1" :message="form.errors.status" />
                    </div>
                    <div>
                        <FloatingInput
                            v-model="form.usage_limit"
                            type="number"
                            min="1"
                            step="1"
                            :label="t('promotions.form.usage_limit')"
                        />
                        <InputError class="mt-1" :message="form.errors.usage_limit" />
                    </div>
                    <div class="md:col-span-2">
                        <FloatingInput
                            v-model="form.minimum_order_amount"
                            type="number"
                            min="0"
                            step="0.01"
                            :label="t('promotions.form.minimum_order_amount')"
                        />
                        <InputError class="mt-1" :message="form.errors.minimum_order_amount" />
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-sm border border-stone-200 px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        @click="closeModal"
                    >
                        {{ t('promotions.actions.cancel') }}
                    </button>
                    <PrimaryButton type="button" :disabled="form.processing" @click="submit">
                        {{ editingPromotionId ? t('promotions.actions.save_changes') : t('promotions.actions.save') }}
                    </PrimaryButton>
                </div>
            </div>
        </AppModal>
    </AuthenticatedLayout>
</template>
