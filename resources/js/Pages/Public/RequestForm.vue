<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    company: {
        type: Object,
        required: true,
    },
    submit_url: {
        type: String,
        required: true,
    },
    catalog_services: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();

const contactPhone = computed(() => (props.company?.phone || '').trim());
const phoneHref = computed(() => {
    if (!contactPhone.value) {
        return '';
    }

    const sanitized = contactPhone.value.replace(/[^\d+]/g, '');
    return sanitized ? `tel:${sanitized}` : '';
});
const hasPhone = computed(() => contactPhone.value.length > 0 && phoneHref.value.length > 0);

const form = useForm({
    contact_name: '',
    contact_email: '',
    contact_phone: '',
    service_type: '',
    description: '',
    street1: '',
    city: '',
    state: '',
    postal_code: '',
    country: '',
    suggested_service_ids: [],
    services_sur_devis: [],
    final_action: 'request_call',
});

const catalogServices = computed(() => (
    (Array.isArray(props.catalog_services) ? props.catalog_services : [])
        .map((service) => ({
            id: Number(service?.id),
            name: String(service?.name || '').trim(),
            description: String(service?.description || '').trim(),
            price: service?.price ?? null,
        }))
        .filter((service) => Number.isInteger(service.id) && service.id > 0 && service.name.length > 0)
));

const catalogServiceLookup = computed(() => {
    const lookup = new Map();
    catalogServices.value.forEach((service) => {
        lookup.set(Number(service.id), service);
    });

    return lookup;
});

const selectedServices = computed(() => {
    const selected = new Set(
        (form.suggested_service_ids || [])
            .map((id) => Number(id))
            .filter((id) => Number.isInteger(id) && id > 0)
    );

    return Array.from(selected)
        .map((id) => catalogServiceLookup.value.get(id))
        .filter((service) => !!service);
});

const hasSelectedServices = computed(() => selectedServices.value.length > 0);
const hasValidQuoteEmail = computed(() => /\S+@\S+\.\S+/.test(String(form.contact_email || '').trim()));
const canReceiveQuote = computed(() => hasSelectedServices.value && hasValidQuoteEmail.value);
const submitLabel = computed(() => (
    canReceiveQuote.value
        ? t('requests.form.receive_quote')
        : t('requests.form.request_call')
));

const addressQuery = ref('');
const addressSuggestions = ref([]);
const validatedAddress = ref(null);
const isSearchingAddress = ref(false);
const addressError = ref('');
let addressSearchTimeout = null;
const geoapifyKey = import.meta.env.VITE_GEOAPIFY_KEY;

const clearValidatedAddress = () => {
    validatedAddress.value = null;
    form.street1 = '';
    form.city = '';
    form.state = '';
    form.postal_code = '';
    form.country = '';
};

const setAddressError = (message) => {
    addressError.value = message;
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
        setAddressError(t('requests.form.address_error_key'));
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
        addressSuggestions.value = [];
        setAddressError(t('requests.form.address_error_failed'));
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

    form.street1 = street || '';
    form.city = city || '';
    form.state = province || '';
    form.postal_code = postalCode || '';
    form.country = country || '';

    addressQuery.value = formatted;
    addressSuggestions.value = [];
    addressError.value = '';
    validatedAddress.value = {
        formatted,
        street: street || '',
        city: city || '',
        province: province || '',
        postalCode: postalCode || '',
        country: country || '',
    };
};

const seedAddressFromForm = () => {
    if (validatedAddress.value) {
        return;
    }

    const hasAny = [form.street1, form.city, form.state, form.postal_code, form.country]
        .some((part) => String(part || '').trim().length > 0);
    if (!hasAny) {
        return;
    }

    const formatted = [form.street1, form.city, form.state, form.country]
        .filter((part) => String(part || '').trim().length > 0)
        .join(', ');

    addressQuery.value = formatted;
    validatedAddress.value = {
        formatted,
        street: form.street1 || '',
        city: form.city || '',
        province: form.state || '',
        postalCode: form.postal_code || '',
        country: form.country || '',
    };
};

const formatMoney = (value) => {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) {
        return t('requests.form.sur_devis_label');
    }

    return `$${numeric.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const resolveServicePriceLabel = (service) => {
    const price = Number(service?.price);
    if (!Number.isFinite(price)) {
        return t('requests.form.sur_devis_label');
    }

    return formatMoney(price);
};

const selectedPricingSummary = computed(() => {
    let fixedTotal = 0;
    let onRequestCount = 0;

    selectedServices.value.forEach((service) => {
        const price = Number(service?.price);
        if (!Number.isFinite(price)) {
            onRequestCount += 1;
            return;
        }

        fixedTotal += price;
    });

    return {
        fixedTotal,
        onRequestCount,
    };
});

const syncSelectedServiceIds = () => {
    const allowedIds = new Set(
        catalogServices.value
            .map((service) => Number(service.id))
            .filter((id) => Number.isInteger(id) && id > 0)
    );

    form.suggested_service_ids = (form.suggested_service_ids || [])
        .map((id) => Number(id))
        .filter((id) => allowedIds.has(id));
};

watch(catalogServices, syncSelectedServiceIds, { immediate: true });
seedAddressFromForm();

onBeforeUnmount(() => {
    if (addressSearchTimeout) {
        clearTimeout(addressSearchTimeout);
    }
});

const submit = () => {
    syncSelectedServiceIds();

    form.services_sur_devis = selectedServices.value
        .map((service) => ({ id: Number(service.id), price: Number(service?.price) }))
        .filter((item) => !Number.isFinite(item.price))
        .map((item) => item.id);

    form.final_action = canReceiveQuote.value ? 'receive_quote' : 'request_call';

    if (!String(form.service_type || '').trim() && selectedServices.value.length > 0) {
        form.service_type = selectedServices.value
            .map((service) => service.name)
            .join(', ')
            .slice(0, 255);
    }

    form.post(props.submit_url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.final_action = 'request_call';
            addressQuery.value = '';
            addressSuggestions.value = [];
            validatedAddress.value = null;
            addressError.value = '';
        },
    });
};
</script>

<template>
    <GuestLayout :card-class="'mt-6 w-full max-w-2xl rounded-sm border border-stone-200 bg-white px-6 py-6 shadow-md dark:border-neutral-700 dark:bg-neutral-900'">
        <Head :title="$t('requests.form.title')" />

        <div class="flex flex-col items-center gap-2 text-center">
            <img
                v-if="company.logo_url"
                :src="company.logo_url"
                :alt="company.name"
                class="h-12 w-12 rounded-sm object-contain"
                loading="lazy"
                decoding="async"
            >
            <div class="text-sm text-stone-500 dark:text-neutral-400">{{ company.name }}</div>
            <h1 class="text-2xl font-semibold text-stone-800 dark:text-neutral-100">
                {{ $t('requests.form.title') }}
            </h1>
            <p class="text-sm text-stone-500 dark:text-neutral-400">
                {{ $t('requests.form.subtitle') }}
            </p>
            <a
                v-if="hasPhone"
                :href="phoneHref"
                class="mt-2 inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
            >
                <span>{{ $t('requests.form.contact_phone_label') }}</span>
                <span>{{ contactPhone }}</span>
            </a>
        </div>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div>
                    <FloatingInput v-model="form.contact_name" :label="$t('requests.form.contact_name')" />
                    <InputError class="mt-1" :message="form.errors.contact_name" />
                </div>
                <div>
                    <FloatingInput v-model="form.contact_email" type="email" :label="$t('requests.form.contact_email')" />
                    <InputError class="mt-1" :message="form.errors.contact_email" />
                </div>
                <div>
                    <FloatingInput v-model="form.contact_phone" :label="$t('requests.form.contact_phone')" />
                    <InputError class="mt-1" :message="form.errors.contact_phone" />
                </div>
                <div>
                    <FloatingInput v-model="form.service_type" :label="$t('requests.form.service_type')" />
                    <InputError class="mt-1" :message="form.errors.service_type" />
                </div>
            </div>

            <div>
                <FloatingTextarea v-model="form.description" :label="$t('requests.form.description')" />
                <InputError class="mt-1" :message="form.errors.description" />
            </div>

            <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.form.address_title') }}
                </h3>

                <div class="relative mt-2 w-full">
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 start-0 z-20 flex items-center ps-3.5">
                            <svg
                                class="size-4 shrink-0 text-stone-400 dark:text-white/60"
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <circle cx="11" cy="11" r="8" />
                                <path d="m21 21-4.3-4.3" />
                            </svg>
                        </div>
                        <input
                            v-model="addressQuery"
                            @input="handleAddressInput"
                            class="block w-full rounded-sm border-stone-200 py-3 pe-4 ps-10 text-sm focus:border-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                            type="text"
                            role="combobox"
                            aria-expanded="false"
                            :placeholder="$t('requests.form.address_search_placeholder')"
                        >
                    </div>

                    <div
                        v-if="addressSuggestions.length"
                        class="absolute z-50 w-full rounded-sm bg-white shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:bg-neutral-800"
                    >
                        <div class="max-h-[280px] overflow-y-auto p-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-stone-300 [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar]:w-2 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500 dark:[&::-webkit-scrollbar-track]:bg-neutral-700">
                            <div
                                v-for="suggestion in addressSuggestions"
                                :key="suggestion.id"
                                class="cursor-pointer rounded-sm px-3 py-2 text-sm text-stone-800 hover:bg-stone-100 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                @click="selectAddressSuggestion(suggestion)"
                            >
                                {{ suggestion.label }}
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="isSearchingAddress" class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.form.address_searching') }}
                </div>
                <div v-if="addressError" class="mt-2 text-xs text-red-600 dark:text-red-400">
                    {{ addressError }}
                </div>

                <InputError
                    class="mt-2"
                    :message="form.errors.street1 || form.errors.city || form.errors.state || form.errors.postal_code || form.errors.country"
                />

                <div
                    v-if="validatedAddress"
                    class="mt-3 rounded-sm border border-stone-200 bg-white p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300"
                >
                    <p class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        {{ $t('requests.form.address_validated') }}
                    </p>
                    <div class="mt-2 grid gap-2">
                        <div v-if="validatedAddress.formatted">
                            <span class="font-medium">{{ $t('requests.form.address_label') }}:</span> {{ validatedAddress.formatted }}
                        </div>
                        <div v-if="validatedAddress.street">
                            <span class="font-medium">{{ $t('requests.form.street1') }}:</span> {{ validatedAddress.street }}
                        </div>
                        <div>
                            <span class="font-medium">{{ $t('requests.form.city') }}:</span> {{ validatedAddress.city || '-' }}
                            <span class="mx-2">/</span>
                            <span class="font-medium">{{ $t('requests.form.state') }}:</span> {{ validatedAddress.province || '-' }}
                        </div>
                        <div>
                            <span class="font-medium">{{ $t('requests.form.country') }}:</span> {{ validatedAddress.country || '-' }}
                            <span v-if="validatedAddress.postalCode" class="mx-2">/</span>
                            <span v-if="validatedAddress.postalCode">
                                <span class="font-medium">{{ $t('requests.form.postal_code') }}:</span> {{ validatedAddress.postalCode }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.form.intent_title') }}
                </h3>
                <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.form.intent_hint') }}
                </p>

                <div v-if="catalogServices.length" class="mt-3 space-y-2">
                    <label
                        v-for="service in catalogServices"
                        :key="`catalog-service-${service.id}`"
                        class="flex cursor-pointer items-start gap-3 rounded-sm border border-stone-200 bg-white p-3 hover:bg-stone-100 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                    >
                        <input
                            v-model="form.suggested_service_ids"
                            :value="service.id"
                            type="checkbox"
                            class="mt-0.5 h-4 w-4 rounded border-stone-300 text-green-600 focus:ring-green-500 dark:border-neutral-600 dark:bg-neutral-900"
                        >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ service.name }}</span>
                                <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">{{ resolveServicePriceLabel(service) }}</span>
                            </div>
                            <p v-if="service.description" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ service.description }}
                            </p>
                        </div>
                    </label>
                </div>

                <p v-else class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.form.suggestions_empty') }}
                </p>
                <InputError class="mt-2" :message="form.errors.suggested_service_ids" />
            </div>

            <div
                v-if="selectedServices.length"
                class="rounded-sm border border-emerald-200 bg-emerald-50/60 p-4 dark:border-emerald-700/50 dark:bg-emerald-900/10"
            >
                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.form.pricing_preview_title') }}
                </div>
                <div class="mt-2 space-y-1">
                    <div
                        v-for="service in selectedServices"
                        :key="`selected-service-price-${service.id}`"
                        class="flex items-center justify-between gap-2 text-xs text-stone-700 dark:text-neutral-200"
                    >
                        <span class="min-w-0 flex-1 truncate">{{ service.name }}</span>
                        <span class="font-semibold text-emerald-700 dark:text-emerald-300">{{ resolveServicePriceLabel(service) }}</span>
                    </div>
                </div>

                <div class="mt-3 flex items-center justify-between border-t border-stone-200 pt-2 text-sm font-semibold text-stone-800 dark:border-neutral-700 dark:text-neutral-100">
                    <span>{{ $t('requests.form.pricing_total_estimate') }}</span>
                    <span>{{ formatMoney(selectedPricingSummary.fixedTotal) }}</span>
                </div>
                <p v-if="selectedPricingSummary.onRequestCount > 0" class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                    {{ $t('requests.form.pricing_on_request_count', { count: selectedPricingSummary.onRequestCount }) }}
                </p>
            </div>

            <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                    {{ $t('requests.form.final_actions_title') }}
                </h3>
                <p v-if="canReceiveQuote" class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                    {{ $t('requests.form.final_actions_hint') }}
                </p>
                <p v-else class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                    {{ $t('requests.form.receive_quote_requirements') }}
                </p>
                <InputError class="mt-2" :message="form.errors.final_action" />

                <div class="mt-3 flex justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center justify-center rounded-sm bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                    >
                        {{ submitLabel }}
                    </button>
                </div>
            </div>
        </form>
    </GuestLayout>
</template>
