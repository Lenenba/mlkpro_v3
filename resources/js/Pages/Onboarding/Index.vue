<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PlanPriceDisplay from '@/Components/Billing/PlanPriceDisplay.vue';
import {
    displayIntervalKeyForBillingPeriod,
    hasActiveSubscriptionPromotion,
    planPricingForBillingDisplay,
} from '@/utils/subscriptionPricing';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import InputError from '@/Components/InputError.vue';
import DropzoneInput from '@/Components/DropzoneInput.vue';
import Modal from '@/Components/Modal.vue';
import TermsContent from '@/Components/Legal/TermsContent.vue';

const props = defineProps({
    preset: Object,
    plans: {
        type: Array,
        default: () => [],
    },
    planLimits: {
        type: Object,
        default: () => ({}),
    },
    supportedCurrencies: {
        type: Array,
        default: () => ['CAD', 'EUR', 'USD'],
    },
    selectedPlanKey: {
        type: String,
        default: null,
    },
    selectedBillingPeriod: {
        type: String,
        default: 'monthly',
    },
});

const page = usePage();
const isGuest = computed(() => !page.props.auth?.user);
const { t, locale } = useI18n();

const step = ref(1);
const showTerms = ref(false);

const preset = computed(() => props.preset || {});
const planOptions = computed(() => props.plans || []);
const planLimits = computed(() => props.planLimits || {});
const currencyOptions = computed(() =>
    (props.supportedCurrencies || []).map((currency) => ({
        id: currency,
        name: currency,
    }))
);

const registerForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const serviceSectorOptions = computed(() => ([
    { id: '', name: t('onboarding.sectors.service.placeholder') },
    { id: 'salon', name: t('onboarding.sectors.service.salon') },
    { id: 'restaurant', name: t('onboarding.sectors.service.restaurant') },
    { id: 'service_general', name: t('onboarding.sectors.service.service_general') },
    { id: 'menuiserie', name: t('onboarding.sectors.service.menuiserie') },
    { id: 'plomberie', name: t('onboarding.sectors.service.plomberie') },
    { id: 'electricite', name: t('onboarding.sectors.service.electricite') },
    { id: 'peinture', name: t('onboarding.sectors.service.peinture') },
    { id: 'toiture', name: t('onboarding.sectors.service.toiture') },
    { id: 'renovation', name: t('onboarding.sectors.service.renovation') },
    { id: 'paysagisme', name: t('onboarding.sectors.service.paysagisme') },
    { id: 'climatisation', name: t('onboarding.sectors.service.climatisation') },
    { id: 'nettoyage', name: t('onboarding.sectors.service.nettoyage') },
    { id: '__other__', name: t('onboarding.sectors.other') },
]));

const productSectorOptions = computed(() => ([
    { id: '', name: t('onboarding.sectors.product.placeholder') },
    { id: 'retail', name: t('onboarding.sectors.product.retail') },
    { id: 'wholesale', name: t('onboarding.sectors.product.wholesale') },
    { id: 'grocery', name: t('onboarding.sectors.product.grocery') },
    { id: 'convenience', name: t('onboarding.sectors.product.convenience') },
    { id: 'specialty', name: t('onboarding.sectors.product.specialty') },
    { id: 'pharmacy', name: t('onboarding.sectors.product.pharmacy') },
    { id: 'electronics', name: t('onboarding.sectors.product.electronics') },
    { id: 'home_hardware', name: t('onboarding.sectors.product.home_hardware') },
    { id: '__other__', name: t('onboarding.sectors.other') },
]));

const hasOption = (options, value) => {
    return (options || []).some((option) => option.id === value);
};

const resolveSelectValue = (value, options) => {
    const trimmed = String(value || '').trim();
    if (!trimmed) {
        return { select: '', other: '' };
    }
    if (hasOption(options, trimmed)) {
        return { select: trimmed, other: '' };
    }
    return { select: '__other__', other: trimmed };
};

const form = useForm({
    company_name: preset.value.company_name || '',
    company_logo: preset.value.company_logo || null,
    company_description: preset.value.company_description || '',
    company_country: preset.value.company_country || '',
    company_province: preset.value.company_province || '',
    company_city: preset.value.company_city || '',
    currency_code: preset.value.currency_code || 'CAD',
    company_type: preset.value.company_type || 'services',
    company_sector: preset.value.company_sector || '',
    company_sector_other: '',
    company_team_size: preset.value.company_team_size || '',
    invites: [],
    plan_key: props.selectedPlanKey || '',
    billing_period: props.selectedBillingPeriod || 'monthly',
    two_factor_method: preset.value.two_factor_method || 'email',
    accept_terms: false,
});

const accountEmail = computed(() => page.props.auth?.user?.email || t('onboarding.security.email_fallback'));

const sectorOptions = computed(() => (form.company_type === 'products' ? productSectorOptions.value : serviceSectorOptions.value));

const sectorPreset = resolveSelectValue(preset.value.company_sector, sectorOptions.value);
form.company_sector = sectorPreset.select;
form.company_sector_other = sectorPreset.other;

const addressQuery = ref('');
const addressSuggestions = ref([]);
const validatedAddress = ref(null);
const isSearchingAddress = ref(false);
const addressError = ref('');
const showManualAddress = ref(false);
let addressSearchTimeout = null;
const geoapifyKey = import.meta.env.VITE_GEOAPIFY_KEY;

const clearValidatedAddress = () => {
    validatedAddress.value = null;
    form.company_country = '';
    form.company_province = '';
    form.company_city = '';
};

const setAddressError = (message) => {
    addressError.value = message;
    if (message) {
        showManualAddress.value = true;
    }
};

const fetchGeoapify = async (useFilter) => {
    const url = new URL('https://api.geoapify.com/v1/geocode/autocomplete');
    const params = {
        text: addressQuery.value,
        apiKey: geoapifyKey,
        limit: '5',
    };

    if (useFilter) {
        params.filter = 'countrycode:ca,us,fr,be,ch,ma,tn';
    }

    url.search = new URLSearchParams(params).toString();

    const response = await fetch(url.toString());
    if (!response.ok) {
        throw new Error(`Geoapify request failed: ${response.status}`);
    }

    return response.json();
};

const searchAddress = async () => {
    if (addressQuery.value.length < 2) {
        addressSuggestions.value = [];
        addressError.value = '';
        return;
    }

    if (!geoapifyKey) {
        addressSuggestions.value = [];
        setAddressError(t('onboarding.company.address_error_key'));
        return;
    }

    isSearchingAddress.value = true;
    setAddressError('');
    try {
        const primary = await fetchGeoapify(true);
        let features = primary.features || [];

        if (!features.length) {
            const fallback = await fetchGeoapify(false);
            features = fallback.features || [];
        }

        addressSuggestions.value = features.map((feature) => ({
            id: feature.properties?.place_id || feature.properties?.formatted || feature.properties?.name,
            label: feature.properties?.formatted || feature.properties?.name || '',
            details: feature.properties || {},
        }));
    } catch (error) {
        console.error('Erreur lors de la recherche d\'adresse :', error);
        addressSuggestions.value = [];
        setAddressError(t('onboarding.company.address_error_failed'));
    } finally {
        isSearchingAddress.value = false;
    }
};

const handleAddressInput = () => {
    if (validatedAddress.value) {
        clearValidatedAddress();
    }
    if (addressSearchTimeout) {
        clearTimeout(addressSearchTimeout);
    }
    addressSearchTimeout = setTimeout(() => {
        searchAddress();
    }, 350);
};

const selectAddressSuggestion = (suggestion) => {
    if (!suggestion?.details) {
        return;
    }
    const address = suggestion.details || {};
    const streetParts = [];
    if (address.house_number) {
        streetParts.push(address.house_number);
    }
    if (address.street) {
        streetParts.push(address.street);
    }

    const city = address.city || address.town || address.village || address.hamlet || address.suburb;
    const province = address.state || address.county || address.region || '';
    const country = address.country || '';
    const postalCode = address.postcode || '';
    const formatted = address.formatted || address.name || suggestion.label || addressQuery.value;
    const street = streetParts.join(' ').trim();

    form.company_city = city || '';
    form.company_province = province || '';
    form.company_country = country || '';

    addressQuery.value = formatted;
    addressSuggestions.value = [];
    addressError.value = '';
    showManualAddress.value = false;
    validatedAddress.value = {
        formatted,
        street,
        city: city || '',
        province,
        postalCode,
        country,
    };
};

const seedAddressFromPreset = () => {
    if (validatedAddress.value) {
        return;
    }

    const parts = [form.company_city, form.company_province, form.company_country].filter(Boolean);
    if (!parts.length) {
        return;
    }

    const label = parts.join(', ');
    validatedAddress.value = {
        formatted: label,
        street: '',
        city: form.company_city || '',
        province: form.company_province || '',
        postalCode: '',
        country: form.company_country || '',
    };
    addressQuery.value = label;
};

seedAddressFromPreset();

watch(
    () => form.company_sector,
    () => {
        if (form.company_sector !== '__other__') {
            form.company_sector_other = '';
        }
    }
);

watch(
    () => form.company_type,
    () => {
        const currentValue = form.company_sector === '__other__' ? form.company_sector_other : form.company_sector;
        const resolved = resolveSelectValue(currentValue, sectorOptions.value);
        form.company_sector = resolved.select;
        form.company_sector_other = resolved.other;
    }
);

const companyTypeLabel = computed(() => (form.company_type === 'products'
    ? t('onboarding.type.products')
    : t('onboarding.type.services')));
const companySectorLabel = computed(() => {
    if (form.company_sector === '__other__') {
        return form.company_sector_other || t('onboarding.sector.other_label');
    }
    const match = sectorOptions.value.find((option) => option.id === form.company_sector);
    return match?.name || form.company_sector || '-';
});

const teamSizeValue = computed(() => {
    const raw = Number(form.company_team_size);
    if (Number.isFinite(raw) && raw > 0) {
        return Math.floor(raw);
    }
    const inviteCount = Array.isArray(form.invites) ? form.invites.length : 0;
    return Math.max(1, inviteCount + 1);
});
const isSoloProfile = computed(() => teamSizeValue.value <= 1);
const showInviteSection = computed(() => !isSoloProfile.value || form.invites.length > 0);
const teamStepTitle = computed(() => (
    isSoloProfile.value ? t('onboarding.steps.team_solo.title') : t('onboarding.steps.team.title')
));
const teamStepDescription = computed(() => (
    isSoloProfile.value ? t('onboarding.steps.team_solo.description') : t('onboarding.steps.team.description')
));
const teamSizeTitle = computed(() => (
    isSoloProfile.value ? t('onboarding.team.size_title_solo') : t('onboarding.team.size_title')
));
const teamSizeHint = computed(() => (
    isSoloProfile.value ? t('onboarding.team.size_hint_solo') : t('onboarding.team.size_hint')
));
const teamSizeLabel = computed(() => (
    isSoloProfile.value ? t('onboarding.team.size_label_solo') : t('onboarding.team.size_label')
));
const teamWorkspaceTitle = computed(() => (
    isSoloProfile.value ? t('onboarding.team.solo_title') : t('onboarding.team.title')
));
const teamWorkspaceSubtitle = computed(() => (
    isSoloProfile.value ? t('onboarding.team.solo_subtitle') : t('onboarding.team.subtitle')
));
const stepOffset = computed(() => (isGuest.value ? 1 : 0));
const baseStepItems = computed(() => ([
    { key: 'company', title: t('onboarding.steps.company.title'), description: t('onboarding.steps.company.description') },
    { key: 'type', title: t('onboarding.steps.type.title'), description: t('onboarding.steps.type.description') },
    { key: 'sector', title: t('onboarding.steps.sector.title'), description: t('onboarding.steps.sector.description') },
    { key: 'team', title: teamStepTitle.value, description: teamStepDescription.value },
    { key: 'plan', title: t('onboarding.steps.plan.title'), description: t('onboarding.steps.plan.description') },
    { key: 'security', title: t('onboarding.steps.security.title'), description: t('onboarding.steps.security.description') },
]));
const stepItems = computed(() => {
    const items = baseStepItems.value.map((item, index) => ({
        ...item,
        id: index + 1 + stepOffset.value,
    }));

    if (!isGuest.value) {
        return items;
    }

    return [
        { id: 1, key: 'account', title: t('onboarding.steps.account.title'), description: t('onboarding.steps.account.description') },
        ...items,
    ];
});
const totalSteps = computed(() => stepItems.value.length);
const currentStep = computed(() => stepItems.value.find((item) => item.id === step.value) || stepItems.value[0]);
const stepIds = computed(() => ({
    account: 1,
    company: 1 + stepOffset.value,
    type: 2 + stepOffset.value,
    sector: 3 + stepOffset.value,
    team: 4 + stepOffset.value,
    plan: 5 + stepOffset.value,
    security: 6 + stepOffset.value,
}));
const isStepDisabled = (item) => isGuest.value && item.key !== 'account';
const selectStep = (item) => {
    if (isStepDisabled(item)) {
        return;
    }
    step.value = item.id;
};

const selectedCurrencyCode = computed(() => String(form.currency_code || 'CAD').toUpperCase());
const priceForCurrency = (plan) => plan?.prices_by_currency?.[selectedCurrencyCode.value]?.[form.billing_period] || null;
const hasPlanPrice = (plan) => Boolean(plan?.contact_only || priceForCurrency(plan)?.stripe_price_id);
const visiblePlanOptions = computed(() => {
    const targetAudience = isSoloProfile.value ? 'solo' : 'team';
    const matchingAudience = planOptions.value.filter((plan) => (plan?.audience || 'team') === targetAudience);

    return matchingAudience.length ? matchingAudience : planOptions.value;
});
const planCandidates = computed(() => visiblePlanOptions.value
    .filter((plan) => hasPlanPrice(plan))
    .map((plan) => {
        const limit = planLimits.value?.[plan.key]?.team_members;
        return {
            ...plan,
            team_limit: typeof limit === 'number' ? limit : null,
        };
    }));

const recommendedPlan = computed(() => {
    if (!planCandidates.value.length) {
        return null;
    }
    if (isSoloProfile.value) {
        return planCandidates.value.find((plan) => Boolean(plan?.recommended))
            || planCandidates.value.find((plan) => Boolean(plan?.owner_only))
            || planCandidates.value[0];
    }
    const size = teamSizeValue.value;
    const candidate = planCandidates.value.find((plan) => plan.team_limit === null || plan.team_limit >= size);
    return candidate || planCandidates.value[planCandidates.value.length - 1];
});

const recommendedPlanBadgeLabel = computed(() => {
    if (!recommendedPlan.value) {
        return '';
    }
    if (isSoloProfile.value) {
        return t('onboarding.team.recommendation_owner_only');
    }
    const limit = recommendedPlan.value.team_limit;
    if (limit === null || typeof limit === 'undefined') {
        return t('onboarding.team.recommendation_unlimited');
    }
    return t('onboarding.team.recommendation_limit', { count: limit });
});

const recommendedPlanSubtitle = computed(() => (
    isSoloProfile.value
        ? t('onboarding.team.recommendation_solo_subtitle')
        : t('onboarding.team.recommendation_subtitle', { count: teamSizeValue.value })
));

const addMonthNoOverflow = (date) => {
    const base = new Date(date);
    const day = base.getDate();
    const targetYear = base.getFullYear();
    const targetMonth = base.getMonth() + 1;
    const daysInTargetMonth = new Date(targetYear, targetMonth + 1, 0).getDate();
    return new Date(targetYear, targetMonth, Math.min(day, daysInTargetMonth));
};

const trialEndLabel = computed(() => {
    const label = addMonthNoOverflow(new Date());
    return new Intl.DateTimeFormat(locale.value || undefined, { dateStyle: 'medium' }).format(label);
});

const displayedPricingForPlan = (plan) => planPricingForBillingDisplay(
    plan,
    form.billing_period,
    priceForCurrency(plan) || {
        display_price: plan?.display_price || null,
        original_display_price: plan?.original_display_price || plan?.display_price || null,
        discounted_display_price: plan?.discounted_display_price || plan?.display_price || null,
        is_discounted: Boolean(plan?.is_discounted),
        promotion: plan?.promotion || { is_active: false, discount_percent: null },
    }
);
const yearlyPromotionActive = computed(() =>
    visiblePlanOptions.value.some((plan) => hasActiveSubscriptionPromotion(displayedPricingForPlan(plan)))
);
const resolvePlanIntervalLabel = () => (
    t(displayIntervalKeyForBillingPeriod(
        form.billing_period,
        'onboarding.plan.interval_month'
    ))
);
const isPlanSelected = (plan) => form.plan_key === plan?.key;
const isPlanRecommended = (plan) => recommendedPlan.value?.key === plan?.key;
const selectPlan = (plan) => {
    if (!plan?.key || !hasPlanPrice(plan)) {
        return;
    }
    form.plan_key = plan.key;
};

watch(
    () => [recommendedPlan.value?.key, visiblePlanOptions.value.map((plan) => plan.key).join('|')],
    () => {
        const currentPlan = visiblePlanOptions.value.find((plan) => plan.key === form.plan_key);
        if (form.plan_key && (!currentPlan || !hasPlanPrice(currentPlan))) {
            form.plan_key = '';
        }

        if (!form.plan_key && recommendedPlan.value?.key && hasPlanPrice(recommendedPlan.value)) {
            form.plan_key = recommendedPlan.value.key;
        }
    },
    { immediate: true }
);

watch(
    () => [form.currency_code, form.billing_period],
    () => {
        if (!form.plan_key) {
            return;
        }

        const currentPlan = visiblePlanOptions.value.find((plan) => plan.key === form.plan_key);
        if (!currentPlan || !hasPlanPrice(currentPlan)) {
            form.plan_key = '';
        }
    }
);

const goNext = () => {
    if (step.value < totalSteps.value) {
        step.value += 1;
    }
};

const goBack = () => {
    if (step.value > 1) {
        step.value -= 1;
    }
};

const addInvite = () => {
    form.invites.push({ name: '', email: '', role: 'member' });
};

const removeInvite = (index) => {
    form.invites.splice(index, 1);
};

const submitRegister = () => {
    registerForm.post(route('onboarding.register'), {
        onFinish: () => registerForm.reset('password', 'password_confirmation'),
    });
};

const submit = () => {
    const normalizeText = (value) => {
        const trimmed = String(value || '').trim();
        return trimmed.length ? trimmed : null;
    };
    const normalizeNumber = (value) => {
        const raw = Number(value);
        if (!Number.isFinite(raw) || raw <= 0) {
            return null;
        }
        return Math.floor(raw);
    };

    form
        .transform((data) => {
            const sector = data.company_sector === '__other__' ? data.company_sector_other : data.company_sector;

            const payload = {
                ...data,
                company_country: normalizeText(data.company_country),
                company_province: normalizeText(data.company_province),
                company_city: normalizeText(data.company_city),
                company_sector: normalizeText(sector),
                company_team_size: normalizeNumber(data.company_team_size),
            };

            if (data.company_logo instanceof File) {
                payload.company_logo = data.company_logo;
            } else {
                delete payload.company_logo;
            }

            return payload;
        })
        .post(route('onboarding.store'), { forceFormData: true });
};

const openTerms = () => {
    showTerms.value = true;
};

const closeTerms = () => {
    showTerms.value = false;
};
</script>

<template>
    <GuestLayout card-class="mt-6 w-full max-w-6xl space-y-6">
        <Head :title="$t('onboarding.title')" />

        <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ $t('onboarding.header.title') }}</h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        {{ $t('onboarding.header.subtitle', { count: totalSteps }) }}
                    </p>
                </div>
                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                    {{ $t('onboarding.header.step', { current: step, total: totalSteps }) }}
                </div>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-[240px_minmax(0,1fr)]">
            <aside class="space-y-3">
                <div class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ $t('onboarding.progress.title') }}
                    </p>
                    <div class="mt-3 space-y-2">
                        <button
                            v-for="item in stepItems"
                            :key="item.id"
                            type="button"
                            :disabled="isStepDisabled(item)"
                            @click="selectStep(item)"
                            class="w-full rounded-sm border px-3 py-2 text-left text-sm transition disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-60"
                            :class="item.id === step
                                ? 'border-green-600 bg-green-50 text-green-700 dark:border-green-500/50 dark:bg-green-500/10 dark:text-green-300'
                                : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800'">
                            <div class="flex items-center justify-between">
                                <span class="font-medium">{{ item.title }}</span>
                                <span class="text-xs text-stone-400 dark:text-neutral-500">#{{ item.id }}</span>
                            </div>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ item.description }}</p>
                        </button>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-3 text-xs text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                    {{ $t('onboarding.progress.note') }}
                </div>
            </aside>

            <section class="rounded-sm border border-stone-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="border-b border-stone-200 p-4 dark:border-neutral-700">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ currentStep.title }}</h2>
                    <p class="text-xs text-stone-500 dark:text-neutral-400">{{ currentStep.description }}</p>
                </div>

                <div class="p-4 space-y-4">
                    <div v-if="isGuest && step === stepIds.account" class="space-y-4">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                            {{ $t('onboarding.account.owner_notice') }}
                        </div>

                        <form class="space-y-3" @submit.prevent="submitRegister">
                            <FloatingInput v-model="registerForm.name" :label="$t('onboarding.account.full_name')" autocomplete="name" required />
                            <InputError class="mt-1" :message="registerForm.errors.name" />

                            <FloatingInput v-model="registerForm.email" :label="$t('onboarding.account.email')" type="email" autocomplete="email" required />
                            <InputError class="mt-1" :message="registerForm.errors.email" />

                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                <div>
                                    <FloatingInput
                                        v-model="registerForm.password"
                                        :label="$t('onboarding.account.password')"
                                        type="password"
                                        autocomplete="new-password"
                                        required
                                    />
                                    <InputError class="mt-1" :message="registerForm.errors.password" />
                                </div>
                                <div>
                                    <FloatingInput
                                        v-model="registerForm.password_confirmation"
                                        :label="$t('onboarding.account.confirm_password')"
                                        type="password"
                                        autocomplete="new-password"
                                        required
                                    />
                                    <InputError class="mt-1" :message="registerForm.errors.password_confirmation" />
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                                <Link
                                    :href="route('login')"
                                    class="text-xs text-stone-600 hover:text-stone-900 dark:text-neutral-400 dark:hover:text-neutral-200"
                                >
                                    {{ $t('onboarding.account.have_account') }}
                                </Link>
                                <button
                                    type="submit"
                                    :disabled="registerForm.processing"
                                    class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                                >
                                    {{ $t('onboarding.account.create_account') }}
                                </button>
                            </div>
                        </form>
                    </div>

                    <div v-else-if="step === stepIds.company" class="space-y-3">
                        <FloatingInput v-model="form.company_name" :label="$t('onboarding.company.name')" />
                        <InputError class="mt-1" :message="form.errors.company_name" />

                        <div class="space-y-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">{{ $t('onboarding.company.logo_optional') }}</p>
                            <DropzoneInput v-model="form.company_logo" :label="$t('onboarding.company.logo_upload')" />
                            <InputError class="mt-1" :message="form.errors.company_logo" />
                        </div>

                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ $t('onboarding.company.description_optional') }}</label>
                            <textarea v-model="form.company_description"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                rows="3" />
                            <InputError class="mt-1" :message="form.errors.company_description" />
                        </div>

                        <div class="space-y-3">
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">{{ $t('onboarding.company.address') }}</label>
                            <div class="relative w-full">
                                <div class="relative">
                                    <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                                        <svg class="shrink-0 size-4 text-stone-400 dark:text-white/60"
                                            xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="11" cy="11" r="8"></circle>
                                            <path d="m21 21-4.3-4.3"></path>
                                        </svg>
                                    </div>
                                    <input
                                        v-model="addressQuery"
                                        @input="handleAddressInput"
                                        class="py-3 ps-10 pe-4 block w-full border-stone-200 rounded-sm text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                        type="text"
                                        role="combobox"
                                        aria-expanded="false"
                                        :placeholder="$t('onboarding.company.address_search_placeholder')"
                                    />
                                </div>

                                <div v-if="addressSuggestions.length"
                                    class="absolute z-50 w-full bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:bg-neutral-800">
                                    <div
                                        class="max-h-[300px] p-2 overflow-y-auto overflow-hidden [&::-webkit-scrollbar]:w-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
                                        <div v-for="suggestion in addressSuggestions" :key="suggestion.id"
                                            class="py-2 px-3 flex items-center gap-x-3 hover:bg-stone-100 rounded-sm dark:hover:bg-neutral-700 cursor-pointer"
                                            @click="selectAddressSuggestion(suggestion)">
                                            <span class="text-sm text-stone-800 dark:text-neutral-200">
                                                {{ suggestion.label }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div v-if="isSearchingAddress" class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('onboarding.company.address_searching') }}
                            </div>
                            <div v-if="addressError" class="text-xs text-red-600 dark:text-red-400">
                                {{ addressError }}
                            </div>
                            <InputError class="mt-1" :message="form.errors.company_country || form.errors.company_province || form.errors.company_city" />
                        </div>

                        <div v-if="validatedAddress" class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                            <p class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ $t('onboarding.company.address_validated') }}</p>
                            <div class="mt-2 grid gap-2">
                                <div v-if="validatedAddress.formatted">
                                    <span class="font-medium">{{ $t('onboarding.company.address_label') }}:</span> {{ validatedAddress.formatted }}
                                </div>
                                <div v-if="validatedAddress.street">
                                    <span class="font-medium">{{ $t('onboarding.company.street') }}:</span> {{ validatedAddress.street }}
                                </div>
                                <div>
                                    <span class="font-medium">{{ $t('onboarding.company.city') }}:</span> {{ validatedAddress.city || '-' }}
                                    <span class="mx-2">/</span>
                                    <span class="font-medium">{{ $t('onboarding.company.province') }}:</span> {{ validatedAddress.province || '-' }}
                                </div>
                                <div>
                                    <span class="font-medium">{{ $t('onboarding.company.country') }}:</span> {{ validatedAddress.country || '-' }}
                                    <span v-if="validatedAddress.postalCode" class="mx-2">/</span>
                                    <span v-if="validatedAddress.postalCode">
                                        <span class="font-medium">{{ $t('onboarding.company.postal_code') }}:</span> {{ validatedAddress.postalCode }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                            <span>{{ $t('onboarding.company.address_manual') }}</span>
                            <button
                                type="button"
                                class="text-green-700 hover:underline dark:text-green-400"
                                @click="showManualAddress = !showManualAddress"
                            >
                                {{ showManualAddress ? $t('onboarding.company.address_hide') : $t('onboarding.company.address_show') }}
                            </button>
                        </div>

                        <div v-if="showManualAddress" class="grid grid-cols-1 gap-3 md:grid-cols-3">
                            <FloatingInput v-model="form.company_city" :label="$t('onboarding.company.city')" />
                            <FloatingInput v-model="form.company_province" :label="$t('onboarding.company.province_region')" />
                            <FloatingInput v-model="form.company_country" :label="$t('onboarding.company.country')" />
                        </div>
                        <div>
                            <FloatingSelect
                                v-model="form.currency_code"
                                :label="'Main business currency'"
                                :options="currencyOptions"
                            />
                            <InputError class="mt-1" :message="form.errors.currency_code" />
                            <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                Products, services, invoices, and Stripe online charges will use {{ selectedCurrencyCode }}.
                            </p>
                        </div>
                    </div>

                    <div v-else-if="step === stepIds.type" class="space-y-3">
                        <div class="space-y-2">
                            <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                <input type="radio" name="company_type" value="services" v-model="form.company_type" />
                                <span>{{ $t('onboarding.type.services') }}</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                <input type="radio" name="company_type" value="products" v-model="form.company_type" />
                                <span>{{ $t('onboarding.type.products') }}</span>
                            </label>
                        </div>

                        <InputError class="mt-1" :message="form.errors.company_type" />

                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            {{ $t('onboarding.type.modules_active', { label: companyTypeLabel }) }}
                        </div>
                    </div>

                    <div v-else-if="step === stepIds.sector" class="space-y-3">
                        <div>
                            <FloatingSelect
                                v-model="form.company_sector"
                                :label="form.company_type === 'products'
                                    ? $t('onboarding.sector.label_products')
                                    : $t('onboarding.sector.label_services')"
                                :options="sectorOptions"
                            />
                            <InputError class="mt-1" :message="form.errors.company_sector" />
                            <div v-if="form.company_sector === '__other__'" class="mt-2">
                                <FloatingInput v-model="form.company_sector_other" :label="$t('onboarding.sector.other_input')" />
                            </div>
                            <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('onboarding.sector.hint') }}
                            </p>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            <span v-if="form.company_type === 'products'">
                                {{ $t('onboarding.sector.products_note') }}
                            </span>
                            <span v-else>
                                {{ $t('onboarding.sector.services_note') }}
                            </span>
                        </div>
                    </div>

                    <div v-else-if="step === stepIds.team" class="space-y-3">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ teamSizeTitle }}</h3>
                            <p class="text-xs text-stone-500 dark:text-neutral-400">{{ teamSizeHint }}</p>
                            <div class="mt-3">
                                <FloatingInput
                                    v-model="form.company_team_size"
                                    type="number"
                                    min="1"
                                    :label="teamSizeLabel"
                                />
                                <InputError class="mt-1" :message="form.errors.company_team_size" />
                            </div>
                        </div>

                        <div v-if="recommendedPlan" class="rounded-sm border border-stone-200 bg-white p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            <p class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                                {{ $t('onboarding.team.recommendation_title') }}
                            </p>
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ recommendedPlan.name }}</p>
                                    <p class="text-xs text-stone-500 dark:text-neutral-400">
                                        {{ recommendedPlanSubtitle }}
                                    </p>
                                </div>
                                <span class="rounded-full bg-stone-100 px-2 py-1 text-xs font-semibold text-stone-600 dark:bg-neutral-800 dark:text-neutral-200">
                                    {{ recommendedPlanBadgeLabel }}
                                </span>
                            </div>
                        </div>

                        <div v-if="showInviteSection" class="flex items-center justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ teamWorkspaceTitle }}</h3>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">{{ teamWorkspaceSubtitle }}</p>
                            </div>
                            <button type="button" @click="addInvite"
                                class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                                {{ $t('onboarding.team.add') }}
                            </button>
                        </div>

                        <div
                            v-else
                            class="rounded-sm border border-stone-200 bg-white p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        >
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ teamWorkspaceTitle }}</h3>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ teamWorkspaceSubtitle }}</p>
                        </div>

                        <div v-if="showInviteSection && !form.invites.length" class="text-sm text-stone-600 dark:text-neutral-400">
                            {{ $t('onboarding.team.empty') }}
                        </div>

                        <div v-else-if="showInviteSection" class="space-y-3">
                            <div v-for="(invite, index) in form.invites" :key="index"
                                class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <div>
                                        <FloatingInput v-model="invite.name" :label="$t('onboarding.team.name')" />
                                        <InputError class="mt-1" :message="form.errors[`invites.${index}.name`]" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="invite.email" :label="$t('onboarding.team.email')" />
                                        <InputError class="mt-1" :message="form.errors[`invites.${index}.email`]" />
                                    </div>
                                </div>

                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-3 text-sm text-stone-700 dark:text-neutral-200">
                                        <label class="flex items-center gap-2">
                                            <input type="radio" :name="`invite-role-${index}`" value="admin"
                                                v-model="invite.role" />
                                            <span>{{ $t('onboarding.team.role_admin') }}</span>
                                        </label>
                                        <label class="flex items-center gap-2">
                                            <input type="radio" :name="`invite-role-${index}`" value="member"
                                                v-model="invite.role" />
                                            <span>{{ $t('onboarding.team.role_member') }}</span>
                                        </label>
                                        <InputError class="mt-1" :message="form.errors[`invites.${index}.role`]" />
                                    </div>

                                    <button type="button" @click="removeInvite(index)"
                                        class="rounded-sm border border-red-200 bg-white px-2 py-1 text-xs text-red-700 hover:bg-red-50 dark:border-red-900/50 dark:bg-neutral-900 dark:text-red-300 dark:hover:bg-red-900/20">
                                        {{ $t('onboarding.team.remove') }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            <p class="font-medium">{{ $t('onboarding.team.summary') }}</p>
                            <p class="mt-1">
                                <span class="font-medium">{{ $t('onboarding.team.summary_company') }}:</span> {{ form.company_name || '-' }}
                                <span class="mx-2">/</span>
                                <span class="font-medium">{{ $t('onboarding.team.summary_type') }}:</span> {{ companyTypeLabel }}
                                <span class="mx-2">/</span>
                                <span class="font-medium">{{ $t('onboarding.team.summary_sector') }}:</span> {{ companySectorLabel }}
                            </p>
                        </div>

                    </div>
                    <div v-else-if="step === stepIds.plan" class="space-y-3">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('onboarding.plan.title') }}</h3>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ $t('onboarding.plan.subtitle') }}</p>
                            <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                Charged currency: {{ selectedCurrencyCode }}
                            </p>
                            <div class="mt-3 inline-flex rounded-sm border border-stone-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-950">
                                <button
                                    type="button"
                                    class="rounded-sm px-3 py-2 text-xs font-semibold transition"
                                    :class="form.billing_period === 'monthly'
                                        ? 'bg-green-600 text-white'
                                        : 'text-stone-600 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                                    @click="form.billing_period = 'monthly'"
                                >
                                    {{ $t('onboarding.plan.monthly') }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-sm px-3 py-2 text-xs font-semibold transition"
                                    :class="form.billing_period === 'yearly'
                                        ? 'bg-green-600 text-white'
                                        : 'text-stone-600 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                                    @click="form.billing_period = 'yearly'"
                                >
                                    {{ $t('onboarding.plan.yearly') }}
                                </button>
                            </div>
                            <p v-if="form.billing_period === 'yearly'" class="mt-2 text-xs font-semibold text-green-700 dark:text-green-400">
                                {{ yearlyPromotionActive
                                    ? $t('onboarding.plan.billed_yearly')
                                    : $t('onboarding.plan.yearly_note', { percent: visiblePlanOptions[0]?.annual_discount_percent || 20 }) }}
                            </p>
                            <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('onboarding.plan.trial_note', { date: trialEndLabel }) }}
                            </p>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('onboarding.plan.downgrade_note') }}
                            </p>
                        </div>

                        <div class="grid gap-3 md:grid-cols-3">
                            <button
                                v-for="plan in visiblePlanOptions"
                                :key="plan.key"
                                type="button"
                                :disabled="!hasPlanPrice(plan)"
                                @click="selectPlan(plan)"
                                class="rounded-sm border p-3 text-left transition"
                                :class="[
                                    isPlanSelected(plan)
                                        ? 'border-green-600 bg-green-50 text-green-800 dark:border-green-500/60 dark:bg-green-500/10 dark:text-green-200'
                                        : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800',
                                    !hasPlanPrice(plan) ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer'
                                ]"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="text-sm font-semibold">{{ plan.name }}</p>
                                        <PlanPriceDisplay
                                            :pricing="displayedPricingForPlan(plan)"
                                            :contact-only="plan.contact_only"
                                            :interval-label="resolvePlanIntervalLabel()"
                                            price-class="text-sm font-semibold text-stone-700 dark:text-neutral-200"
                                            original-price-class="text-xs font-medium text-stone-400 line-through dark:text-neutral-500"
                                            interval-class="text-xs font-medium text-stone-500 dark:text-neutral-400"
                                            badge-class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
                                        />
                                        <p v-if="form.billing_period === 'yearly' && !plan.contact_only" class="mt-2 text-[11px] font-medium text-emerald-700 dark:text-emerald-300">
                                            {{ hasActiveSubscriptionPromotion(displayedPricingForPlan(plan))
                                                ? $t('onboarding.plan.billed_yearly')
                                                : $t('onboarding.plan.yearly_note', { percent: plan.annual_discount_percent || 20 }) }}
                                        </p>
                                    </div>
                                    <span
                                        v-if="isPlanRecommended(plan)"
                                        class="rounded-full bg-stone-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-stone-600 dark:bg-neutral-800 dark:text-neutral-200"
                                    >
                                        {{ $t('onboarding.plan.recommended') }}
                                    </span>
                                </div>

                                <ul v-if="plan.features?.length" class="mt-2 space-y-1 text-xs text-stone-600 dark:text-neutral-400">
                                    <li v-for="feature in plan.features.slice(0, 4)" :key="feature">
                                        {{ feature }}
                                    </li>
                                </ul>

                                <p v-if="!hasPlanPrice(plan)" class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                                    {{ $t('onboarding.plan.unavailable') }}
                                </p>
                            </button>
                        </div>

                        <InputError class="mt-1" :message="form.errors.plan_key" />

                        <div class="rounded-sm border border-stone-200 bg-white p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            <label class="flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    v-model="form.accept_terms"
                                    class="mt-1 rounded-sm border-stone-300 text-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900"
                                />
                                <span>
                                    {{ $t('onboarding.team.terms_label') }}
                                    <button
                                        type="button"
                                        class="inline-flex items-center border-0 bg-transparent p-0 text-green-700 hover:underline dark:text-green-400"
                                        @click.stop="openTerms"
                                    >
                                        {{ $t('onboarding.team.terms_action') }}
                                    </button>
                                    .
                                </span>
                            </label>
                            <InputError class="mt-1" :message="form.errors.accept_terms" />
                        </div>
                    </div>
                    <div v-else-if="step === stepIds.security" class="space-y-3">
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ $t('onboarding.security.title') }}</h3>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">{{ $t('onboarding.security.subtitle') }}</p>
                        </div>

                        <div class="grid gap-3 md:grid-cols-2">
                            <label
                                class="rounded-sm border p-3 text-left transition"
                                :class="form.two_factor_method === 'email'
                                    ? 'border-green-600 bg-green-50 text-green-800 dark:border-green-500/60 dark:bg-green-500/10 dark:text-green-200'
                                    : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                            >
                                <div class="flex items-start gap-3">
                                    <input type="radio" value="email" v-model="form.two_factor_method" class="mt-1" />
                                    <div>
                                        <p class="text-sm font-semibold">{{ $t('onboarding.security.method_email') }}</p>
                                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ $t('onboarding.security.method_email_hint', { email: accountEmail }) }}
                                        </p>
                                    </div>
                                </div>
                            </label>
                            <label
                                class="rounded-sm border p-3 text-left transition"
                                :class="form.two_factor_method === 'app'
                                    ? 'border-green-600 bg-green-50 text-green-800 dark:border-green-500/60 dark:bg-green-500/10 dark:text-green-200'
                                    : 'border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800'"
                            >
                                <div class="flex items-start gap-3">
                                    <input type="radio" value="app" v-model="form.two_factor_method" class="mt-1" />
                                    <div>
                                        <p class="text-sm font-semibold">{{ $t('onboarding.security.method_app') }}</p>
                                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                                            {{ $t('onboarding.security.method_app_hint') }}
                                        </p>
                                    </div>
                                </div>
                            </label>
                        </div>

                        <InputError class="mt-1" :message="form.errors.two_factor_method" />
                        <p class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ $t('onboarding.security.change_later') }}
                        </p>
                    </div>
                </div>

                <div v-if="!(isGuest && step === stepIds.account)" class="border-t border-stone-200 p-4 dark:border-neutral-700 flex items-center justify-between">
                    <button type="button" @click="goBack" :disabled="step === 1"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                        {{ $t('onboarding.actions.back') }}
                    </button>

                    <div class="flex items-center gap-2">
                        <button v-if="step < totalSteps" type="button" @click="goNext"
                            class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">
                            {{ $t('onboarding.actions.continue') }}
                        </button>

                        <button v-else type="button" @click="submit" :disabled="form.processing || !form.plan_key || !form.two_factor_method"
                            class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50">
                            {{ $t('onboarding.actions.finish') }}
                        </button>
                    </div>
                </div>
            </section>
        </div>

        <Modal :show="showTerms" @close="closeTerms" maxWidth="2xl">
            <div class="flex items-center justify-end border-b border-stone-200 px-4 py-3 dark:border-neutral-700">
                <button
                    type="button"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-1 text-xs text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    @click="closeTerms"
                >
                    {{ $t('onboarding.actions.close') }}
                </button>
            </div>
            <div class="max-h-[70vh] overflow-y-auto p-4">
                <TermsContent />
            </div>
        </Modal>
    </GuestLayout>
</template>
