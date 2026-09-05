<script setup>
import { Link } from '@inertiajs/vue3';
import { crmButtonClass } from '@/utils/crmButtonStyles';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    variant: {
        type: String,
        default: 'empty',
        validator: (value) => ['empty', 'no-results', 'error'].includes(value),
    },
    canCreate: {
        type: Boolean,
        default: true,
    },
});

defineEmits(['clear', 'retry']);
const { t } = useI18n();
</script>

<template>
    <div class="space-y-2">
        <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
            {{ t(`customers.states.${variant}.title`) }}
        </div>
        <div class="text-xs text-stone-500 dark:text-neutral-400">
            {{ t(`customers.states.${variant}.description`) }}
        </div>
        <div class="flex justify-center pt-2">
            <Link
                v-if="variant === 'empty' && canCreate"
                :href="route('customer.create')"
                :class="crmButtonClass('primary', 'compact')"
            >
                {{ t('customers.states.empty.action') }}
            </Link>
            <button
                v-else-if="variant === 'no-results'"
                type="button"
                :class="crmButtonClass('secondary', 'compact')"
                @click="$emit('clear')"
            >
                {{ t('customers.states.no-results.action') }}
            </button>
            <button
                v-else-if="variant === 'error'"
                type="button"
                :class="crmButtonClass('primary', 'compact')"
                @click="$emit('retry')"
            >
                {{ t('customers.states.error.action') }}
            </button>
        </div>
    </div>
</template>
