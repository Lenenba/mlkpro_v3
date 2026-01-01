<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import InputError from '@/Components/InputError.vue';
import DropzoneInput from '@/Components/DropzoneInput.vue';
import Modal from '@/Components/Modal.vue';
import TermsContent from '@/Components/Legal/TermsContent.vue';

const props = defineProps({
    preset: Object,
});

const page = usePage();
const isGuest = computed(() => !page.props.auth?.user);

const baseStepItems = [
    { key: 'company', title: 'Entreprise', description: 'Infos principales et identite.' },
    { key: 'type', title: 'Type', description: 'Services ou produits.' },
    { key: 'sector', title: 'Secteur', description: 'Votre activite principale.' },
    { key: 'team', title: 'Equipe', description: 'Invitez votre equipe.' },
];

const step = ref(1);
const showTerms = ref(false);
const stepOffset = computed(() => (isGuest.value ? 1 : 0));
const stepItems = computed(() => {
    const items = baseStepItems.map((item, index) => ({
        ...item,
        id: index + 1 + stepOffset.value,
    }));

    if (!isGuest.value) {
        return items;
    }

    return [
        { id: 1, key: 'account', title: 'Compte', description: 'Identifiants et securite.' },
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
}));
const isStepDisabled = (item) => isGuest.value && item.key !== 'account';
const selectStep = (item) => {
    if (isStepDisabled(item)) {
        return;
    }
    step.value = item.id;
};

const preset = computed(() => props.preset || {});

const registerForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const SECTOR_OPTIONS = [
    { id: '', name: 'Selectionner un secteur' },
    { id: 'menuiserie', name: 'Menuiserie' },
    { id: 'plomberie', name: 'Plomberie' },
    { id: 'electricite', name: 'Electricite' },
    { id: 'peinture', name: 'Peinture' },
    { id: 'toiture', name: 'Toiture' },
    { id: 'renovation', name: 'Renovation' },
    { id: 'paysagisme', name: 'Paysagisme' },
    { id: 'climatisation', name: 'Climatisation' },
    { id: 'nettoyage', name: 'Nettoyage' },
    { id: '__other__', name: 'Autre...' },
];

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
    company_type: preset.value.company_type || 'services',
    company_sector: preset.value.company_sector || '',
    company_sector_other: '',
    invites: [],
    accept_terms: false,
});

const sectorPreset = resolveSelectValue(preset.value.company_sector, SECTOR_OPTIONS);
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
        setAddressError('Cle Geoapify manquante. Ajoutez VITE_GEOAPIFY_KEY dans .env.');
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
        setAddressError('Recherche impossible. Verifiez la cle Geoapify ou utilisez la saisie manuelle.');
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

const companyTypeLabel = computed(() => (form.company_type === 'products' ? 'Entreprise de produits' : 'Entreprise de services'));
const companySectorLabel = computed(() => {
    if (form.company_sector === '__other__') {
        return form.company_sector_other || 'Autre';
    }
    const match = SECTOR_OPTIONS.find((option) => option.id === form.company_sector);
    return match?.name || form.company_sector || '-';
});

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

    form
        .transform((data) => {
            const sector = data.company_sector === '__other__' ? data.company_sector_other : data.company_sector;

            const payload = {
                ...data,
                company_country: normalizeText(data.company_country),
                company_province: normalizeText(data.company_province),
                company_city: normalizeText(data.company_city),
                company_sector: normalizeText(sector),
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
        <Head title="Onboarding" />

        <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Creer votre espace</h1>
                    <p class="text-sm text-stone-600 dark:text-neutral-400">
                        Finalisez la configuration en {{ totalSteps }} etapes.
                    </p>
                </div>
                <div class="rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                    Etape {{ step }} / {{ totalSteps }}
                </div>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-[240px_minmax(0,1fr)]">
            <aside class="space-y-3">
                <div class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <p class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                        Progression
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
                    Gardez ces infos a jour pour activer toutes les fonctions du dashboard.
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
                            Ce compte deviendra le proprietaire de l'entreprise.
                        </div>

                        <form class="space-y-3" @submit.prevent="submitRegister">
                            <FloatingInput v-model="registerForm.name" label="Nom complet" autocomplete="name" required />
                            <InputError class="mt-1" :message="registerForm.errors.name" />

                            <FloatingInput v-model="registerForm.email" label="Email" type="email" autocomplete="email" required />
                            <InputError class="mt-1" :message="registerForm.errors.email" />

                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                <div>
                                    <FloatingInput
                                        v-model="registerForm.password"
                                        label="Mot de passe"
                                        type="password"
                                        autocomplete="new-password"
                                        required
                                    />
                                    <InputError class="mt-1" :message="registerForm.errors.password" />
                                </div>
                                <div>
                                    <FloatingInput
                                        v-model="registerForm.password_confirmation"
                                        label="Confirmer le mot de passe"
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
                                    Deja un compte ? Se connecter
                                </Link>
                                <button
                                    type="submit"
                                    :disabled="registerForm.processing"
                                    class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                                >
                                    Creer mon compte
                                </button>
                            </div>
                        </form>
                    </div>

                    <div v-else-if="step === stepIds.company" class="space-y-3">
                        <FloatingInput v-model="form.company_name" label="Nom de l'entreprise" />
                        <InputError class="mt-1" :message="form.errors.company_name" />

                        <div class="space-y-2">
                            <p class="text-xs text-stone-500 dark:text-neutral-400">Logo (optionnel)</p>
                            <DropzoneInput v-model="form.company_logo" label="Telecharger votre logo" />
                            <InputError class="mt-1" :message="form.errors.company_logo" />
                        </div>

                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Description (optionnel)</label>
                            <textarea v-model="form.company_description"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                                rows="3" />
                            <InputError class="mt-1" :message="form.errors.company_description" />
                        </div>

                        <div class="space-y-3">
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Adresse de l'entreprise</label>
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
                                        placeholder="Rechercher une adresse"
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
                                Recherche en cours...
                            </div>
                            <div v-if="addressError" class="text-xs text-red-600 dark:text-red-400">
                                {{ addressError }}
                            </div>
                            <InputError class="mt-1" :message="form.errors.company_country || form.errors.company_province || form.errors.company_city" />
                        </div>

                        <div v-if="validatedAddress" class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-xs text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                            <p class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">Adresse validee</p>
                            <div class="mt-2 grid gap-2">
                                <div v-if="validatedAddress.formatted">
                                    <span class="font-medium">Adresse :</span> {{ validatedAddress.formatted }}
                                </div>
                                <div v-if="validatedAddress.street">
                                    <span class="font-medium">Rue :</span> {{ validatedAddress.street }}
                                </div>
                                <div>
                                    <span class="font-medium">Ville :</span> {{ validatedAddress.city || '-' }}
                                    <span class="mx-2">/</span>
                                    <span class="font-medium">Province :</span> {{ validatedAddress.province || '-' }}
                                </div>
                                <div>
                                    <span class="font-medium">Pays :</span> {{ validatedAddress.country || '-' }}
                                    <span v-if="validatedAddress.postalCode" class="mx-2">/</span>
                                    <span v-if="validatedAddress.postalCode">
                                        <span class="font-medium">Code postal :</span> {{ validatedAddress.postalCode }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                            <span>Adresse manuelle</span>
                            <button
                                type="button"
                                class="text-green-700 hover:underline dark:text-green-400"
                                @click="showManualAddress = !showManualAddress"
                            >
                                {{ showManualAddress ? 'Masquer' : 'Saisir manuellement' }}
                            </button>
                        </div>

                        <div v-if="showManualAddress" class="grid grid-cols-1 gap-3 md:grid-cols-3">
                            <FloatingInput v-model="form.company_city" label="Ville" />
                            <FloatingInput v-model="form.company_province" label="Province / Region" />
                            <FloatingInput v-model="form.company_country" label="Pays" />
                        </div>
                    </div>

                    <div v-else-if="step === stepIds.type" class="space-y-3">
                        <div class="space-y-2">
                            <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                <input type="radio" name="company_type" value="services" v-model="form.company_type" />
                                <span>Entreprise de services</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                                <input type="radio" name="company_type" value="products" v-model="form.company_type" />
                                <span>Entreprise de produits</span>
                            </label>
                        </div>

                        <InputError class="mt-1" :message="form.errors.company_type" />

                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            Modules actifs : <span class="font-medium">{{ companyTypeLabel }}</span>
                        </div>
                    </div>

                        <div v-else-if="step === stepIds.sector" class="space-y-3">
                        <div>
                            <label class="block text-xs text-stone-500 dark:text-neutral-400">Secteur d'activite</label>
                            <select v-model="form.company_sector"
                                class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                                <option v-for="option in SECTOR_OPTIONS" :key="option.id" :value="option.id">
                                    {{ option.name }}
                                </option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.company_sector" />
                            <div v-if="form.company_sector === '__other__'" class="mt-2">
                                <FloatingInput v-model="form.company_sector_other" label="Secteur (autre)" />
                            </div>
                            <p class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                                Si votre secteur n'est pas liste, choisissez Autre pour le creer.
                            </p>
                        </div>
                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            Des categories de services seront creees automatiquement, y compris pour un secteur ajoute.
                        </div>
                    </div>

                    <div v-else-if="step === stepIds.team" class="space-y-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Inviter l'equipe (optionnel)</h3>
                                <p class="text-xs text-stone-500 dark:text-neutral-400">Ajoutez des membres maintenant ou plus tard.</p>
                            </div>
                            <button type="button" @click="addInvite"
                                class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                                + Ajouter
                            </button>
                        </div>

                        <div v-if="!form.invites.length" class="text-sm text-stone-600 dark:text-neutral-400">
                            Aucune invitation ajoutee.
                        </div>

                        <div v-else class="space-y-3">
                            <div v-for="(invite, index) in form.invites" :key="index"
                                class="rounded-sm border border-stone-200 p-3 dark:border-neutral-700">
                                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <div>
                                        <FloatingInput v-model="invite.name" label="Nom" />
                                        <InputError class="mt-1" :message="form.errors[`invites.${index}.name`]" />
                                    </div>
                                    <div>
                                        <FloatingInput v-model="invite.email" label="Email" />
                                        <InputError class="mt-1" :message="form.errors[`invites.${index}.email`]" />
                                    </div>
                                </div>

                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-3 text-sm text-stone-700 dark:text-neutral-200">
                                        <label class="flex items-center gap-2">
                                            <input type="radio" :name="`invite-role-${index}`" value="admin"
                                                v-model="invite.role" />
                                            <span>Administrateur</span>
                                        </label>
                                        <label class="flex items-center gap-2">
                                            <input type="radio" :name="`invite-role-${index}`" value="member"
                                                v-model="invite.role" />
                                            <span>Membre</span>
                                        </label>
                                        <InputError class="mt-1" :message="form.errors[`invites.${index}.role`]" />
                                    </div>

                                    <button type="button" @click="removeInvite(index)"
                                        class="rounded-sm border border-red-200 bg-white px-2 py-1 text-xs text-red-700 hover:bg-red-50 dark:border-red-900/50 dark:bg-neutral-900 dark:text-red-300 dark:hover:bg-red-900/20">
                                        Supprimer
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            <p class="font-medium">Resume</p>
                            <p class="mt-1">
                                <span class="font-medium">Entreprise :</span> {{ form.company_name || '-' }}
                                <span class="mx-2">/</span>
                                <span class="font-medium">Type :</span> {{ companyTypeLabel }}
                                <span class="mx-2">/</span>
                                <span class="font-medium">Secteur :</span> {{ companySectorLabel }}
                            </p>
                        </div>

                        <div class="rounded-sm border border-stone-200 bg-white p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                            <label class="flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    v-model="form.accept_terms"
                                    class="mt-1 rounded-sm border-stone-300 text-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900"
                                />
                                <span>
                                    J'accepte les
                                    <button
                                        type="button"
                                        class="inline-flex items-center border-0 bg-transparent p-0 text-green-700 hover:underline dark:text-green-400"
                                        @click.stop="openTerms"
                                    >
                                        conditions d'utilisation
                                    </button>
                                    .
                                </span>
                            </label>
                            <InputError class="mt-1" :message="form.errors.accept_terms" />
                        </div>
                    </div>
                </div>

                <div v-if="!(isGuest && step === stepIds.account)" class="border-t border-stone-200 p-4 dark:border-neutral-700 flex items-center justify-between">
                    <button type="button" @click="goBack" :disabled="step === 1"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm text-stone-700 hover:bg-stone-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                        Retour
                    </button>

                    <div class="flex items-center gap-2">
                        <button v-if="step < totalSteps" type="button" @click="goNext"
                            class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">
                            Continuer
                        </button>

                        <button v-else type="button" @click="submit" :disabled="form.processing"
                            class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50">
                            Acceder au tableau de bord
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
                    Fermer
                </button>
            </div>
            <div class="max-h-[70vh] overflow-y-auto p-4">
                <TermsContent />
            </div>
        </Modal>
    </GuestLayout>
</template>
