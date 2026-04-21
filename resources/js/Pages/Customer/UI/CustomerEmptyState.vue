<script setup>
import { Link } from '@inertiajs/vue3';
import { crmButtonClass } from '@/utils/crmButtonStyles';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <div class="space-y-2">
        <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
            {{ t('customers.empty.title') }}
        </div>
        <div class="text-xs text-stone-500 dark:text-neutral-400">
            {{ t('customers.empty.subtitle') }}
        </div>
        <div class="flex justify-center pt-2">
            <Link
                :href="route('customer.create')"
                :class="crmButtonClass('primary', 'compact')"
            >
                {{ t('customers.empty.action') }}
            </Link>
        </div>
    </div>
</template>
