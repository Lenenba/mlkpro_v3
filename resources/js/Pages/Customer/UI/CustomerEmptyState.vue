<script setup>
import { Link } from '@inertiajs/vue3';
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
                class="inline-flex items-center rounded-sm border border-green-600 bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700"
            >
                {{ t('customers.empty.action') }}
            </Link>
        </div>
    </div>
</template>
