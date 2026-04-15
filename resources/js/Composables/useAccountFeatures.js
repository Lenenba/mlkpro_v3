import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { isFeatureEnabled } from '@/utils/features';

export function useAccountFeatures() {
    const page = usePage();
    const featureFlags = computed(() => page.props.auth?.account?.features || {});

    const hasFeature = (key) => isFeatureEnabled(featureFlags.value, key);
    const visibleFeaturePayload = (key, payload, fallback = null) =>
        hasFeature(key) ? (payload ?? fallback) : fallback;

    return {
        featureFlags,
        hasFeature,
        visibleFeaturePayload,
    };
}
