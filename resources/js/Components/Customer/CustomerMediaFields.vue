<script setup>
import { computed, watch } from 'vue';
import DropzoneInput from '@/Components/DropzoneInput.vue';
import InputError from '@/Components/InputError.vue';
import {
    customerIconPresetsForType,
    defaultCustomerIconForType,
} from '@/utils/iconPresets';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    clientType: {
        type: String,
        required: true,
    },
    logo: {
        type: [File, String],
        default: null,
    },
    logoIcon: {
        type: String,
        default: '',
    },
    logoError: {
        type: [String, Array],
        default: '',
    },
    logoIconError: {
        type: [String, Array],
        default: '',
    },
});

const emit = defineEmits(['update:logo', 'update:logoIcon']);
const { t } = useI18n();

const isCompanyClient = computed(() => props.clientType === 'company');
const logoIconPresets = computed(() => customerIconPresetsForType(props.clientType));
const currentDefaultLogoIcon = computed(() => defaultCustomerIconForType(props.clientType));
const logoModel = computed({
    get: () => props.logo,
    set: (value) => emit('update:logo', value),
});
const firstError = (value) => (Array.isArray(value) ? value[0] : value);
const logoFieldLabel = computed(() => (
    isCompanyClient.value
        ? t('customers.form.fields.company_logo')
        : t('customers.form.fields.profile_photo')
));
const uploadLogoLabel = computed(() => (
    isCompanyClient.value
        ? t('customers.form.fields.upload_company_logo')
        : t('customers.form.fields.upload_profile_photo')
));
const chooseLogoIconLabel = computed(() => (
    isCompanyClient.value
        ? t('customers.form.fields.choose_company_icon')
        : t('customers.form.fields.choose_profile_icon')
));
const logoIconAlt = computed(() => (
    isCompanyClient.value
        ? t('customers.form.fields.company_icon_alt')
        : t('customers.form.fields.profile_icon_alt')
));

const selectLogoIcon = (icon) => {
    emit('update:logo', null);
    emit('update:logoIcon', icon);
};

const resetLogoIcon = () => {
    emit('update:logo', null);
    emit('update:logoIcon', currentDefaultLogoIcon.value);
};

watch(() => props.logo, (value) => {
    if (typeof File !== 'undefined' && value instanceof File) {
        if (props.logoIcon) {
            emit('update:logoIcon', '');
        }
    } else if (!value && !props.logoIcon) {
        emit('update:logoIcon', currentDefaultLogoIcon.value);
    }
});

watch(() => props.clientType, (clientType) => {
    const hasUploadedLogo = typeof File !== 'undefined' && props.logo instanceof File;
    const hasExistingLogo = typeof props.logo === 'string' && props.logo.trim() !== '';

    if (hasUploadedLogo || hasExistingLogo) {
        if (props.logoIcon) {
            emit('update:logoIcon', '');
        }
        return;
    }

    if (!customerIconPresetsForType(clientType).includes(props.logoIcon)) {
        emit('update:logoIcon', defaultCustomerIconForType(clientType));
    }
});
</script>

<template>
    <div class="space-y-2" data-testid="customer-media-fields">
        <label class="text-sm font-semibold text-stone-800 dark:text-white">{{ logoFieldLabel }}</label>
        <DropzoneInput v-model="logoModel" :label="uploadLogoLabel" />
        <InputError class="mt-1" :message="firstError(logoError)" />

        <div class="mt-3 space-y-2">
            <p class="text-xs text-stone-500 dark:text-neutral-400">
                {{ chooseLogoIconLabel }}
            </p>
            <div class="grid grid-cols-4 gap-2">
                <button
                    v-for="icon in logoIconPresets"
                    :key="icon"
                    type="button"
                    @click="selectLogoIcon(icon)"
                    class="relative flex items-center justify-center rounded-sm border border-stone-200 bg-white p-2 transition hover:border-green-500 dark:border-neutral-700 dark:bg-neutral-900"
                    :class="logoIcon === icon ? 'ring-2 ring-green-500 border-green-500' : ''"
                >
                    <img :src="icon" :alt="logoIconAlt" class="size-10" loading="lazy" decoding="async" />
                    <span
                        v-if="icon === currentDefaultLogoIcon"
                        class="absolute top-1 right-1 rounded-full bg-green-600 px-1.5 py-0.5 text-[10px] font-semibold text-white"
                    >
                        {{ $t('customers.form.fields.default_icon') }}
                    </span>
                </button>
            </div>
            <div v-if="logoIcon && logoIcon !== currentDefaultLogoIcon" class="flex justify-end">
                <button
                    type="button"
                    @click="resetLogoIcon"
                    class="text-xs font-semibold text-stone-600 hover:text-stone-800 dark:text-neutral-400 dark:hover:text-neutral-200"
                >
                    {{ $t('customers.form.fields.reset_icon') }}
                </button>
            </div>
            <InputError class="mt-1" :message="firstError(logoIconError)" />
        </div>
    </div>
</template>
