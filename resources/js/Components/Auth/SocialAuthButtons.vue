<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    source: {
        type: String,
        required: true,
    },
    query: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const { t } = useI18n();

const providers = computed(() => {
    const available = page.props.socialAuth?.providers || [];

    return available.filter((provider) => provider?.ready && provider?.contexts?.[props.source]);
});

const routeParams = (provider) => {
    const query = Object.fromEntries(
        Object.entries(props.query || {}).filter(([, value]) => value !== null && value !== '')
    );

    return {
        provider: provider.key,
        source: props.source,
        ...query,
    };
};
</script>

<template>
    <div v-if="providers.length" class="mb-6 space-y-3">
        <div class="space-y-2">
            <p class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                {{ t('auth_pages.social.title') }}
            </p>

            <div class="space-y-2">
                <Link
                    v-for="provider in providers"
                    :key="provider.key"
                    :href="route('auth.social.redirect', routeParams(provider))"
                    class="flex w-full items-center justify-center rounded-sm border border-stone-200 bg-white px-4 py-3 text-sm font-medium text-stone-700 transition hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100 dark:hover:bg-neutral-800"
                >
                    {{ t('auth_pages.social.continue_with', { provider: provider.label }) }}
                </Link>
            </div>
        </div>

        <div class="flex items-center gap-3 text-xs uppercase tracking-wide text-stone-400 dark:text-neutral-500">
            <span class="h-px flex-1 bg-stone-200 dark:bg-neutral-700" />
            <span>{{ t('auth_pages.social.separator') }}</span>
            <span class="h-px flex-1 bg-stone-200 dark:bg-neutral-700" />
        </div>
    </div>
</template>
