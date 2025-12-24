<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import InputError from '@/Components/InputError.vue';
import DropzoneInput from '@/Components/DropzoneInput.vue';

const props = defineProps({
    preset: Object,
});

const step = ref(1);
const totalSteps = 4;

const preset = computed(() => props.preset || {});

const COUNTRY_OPTIONS = [
    { id: '', name: 'Selectionner un pays' },
    { id: 'Canada', name: 'Canada' },
    { id: 'France', name: 'France' },
    { id: 'Belgique', name: 'Belgique' },
    { id: 'Suisse', name: 'Suisse' },
    { id: 'Maroc', name: 'Maroc' },
    { id: 'Tunisie', name: 'Tunisie' },
    { id: '__other__', name: 'Autre...' },
];

const PROVINCES_BY_COUNTRY = {
    Canada: [
        'Quebec',
        'Ontario',
        'British Columbia',
        'Alberta',
        'Manitoba',
        'Saskatchewan',
        'Nova Scotia',
        'New Brunswick',
        'Newfoundland and Labrador',
        'Prince Edward Island',
        'Northwest Territories',
        'Yukon',
        'Nunavut',
    ],
    France: [
        'Ile-de-France',
        'Auvergne-Rhone-Alpes',
        'Provence-Alpes-Cote dAzur',
        'Occitanie',
        'Nouvelle-Aquitaine',
        'Hauts-de-France',
        'Grand Est',
        'Bretagne',
        'Normandie',
        'Pays de la Loire',
        'Centre-Val de Loire',
        'Bourgogne-Franche-Comte',
        'Corse',
    ],
    Belgique: ['Bruxelles-Capitale', 'Wallonie', 'Flandre'],
    Suisse: ['Zurich', 'Vaud', 'Geneve', 'Berne', 'Bale-Ville', 'Valais', 'Tessin'],
    Maroc: ['Casablanca-Settat', 'Rabat-Sale-Kenitra', 'Marrakech-Safi', 'Tanger-Tetouan-Al Hoceima'],
    Tunisie: ['Tunis', 'Ariana', 'Ben Arous', 'Sfax', 'Sousse'],
};

const CITIES_BY_COUNTRY_AND_PROVINCE = {
    Canada: {
        Quebec: ['Montreal', 'Quebec City', 'Laval', 'Gatineau', 'Sherbrooke'],
        Ontario: ['Toronto', 'Ottawa', 'Mississauga', 'Hamilton', 'London'],
        'British Columbia': ['Vancouver', 'Victoria', 'Surrey', 'Burnaby'],
        Alberta: ['Calgary', 'Edmonton'],
    },
    France: {
        'Ile-de-France': ['Paris', 'Boulogne-Billancourt', 'Saint-Denis'],
        'Auvergne-Rhone-Alpes': ['Lyon', 'Grenoble', 'Saint-Etienne'],
        'Provence-Alpes-Cote dAzur': ['Marseille', 'Nice', 'Toulon'],
        Occitanie: ['Toulouse', 'Montpellier'],
    },
    Belgique: {
        'Bruxelles-Capitale': ['Bruxelles'],
        Wallonie: ['Liege', 'Namur', 'Charleroi'],
        Flandre: ['Anvers', 'Gand', 'Bruges'],
    },
    Suisse: {
        Zurich: ['Zurich'],
        Vaud: ['Lausanne'],
        Geneve: ['Geneve'],
        Berne: ['Berne'],
    },
    Maroc: {
        'Casablanca-Settat': ['Casablanca', 'Mohammedia'],
        'Rabat-Sale-Kenitra': ['Rabat', 'Sale'],
        'Marrakech-Safi': ['Marrakech'],
    },
    Tunisie: {
        Tunis: ['Tunis'],
        Ariana: ['Ariana'],
        Sfax: ['Sfax'],
        Sousse: ['Sousse'],
    },
};

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
    company_country: '',
    company_country_other: '',
    company_province: '',
    company_province_other: '',
    company_city: '',
    company_city_other: '',
    company_type: preset.value.company_type || 'services',
    is_owner: '1',
    owner_name: '',
    owner_email: '',
    invites: [],
});

const countryPreset = resolveSelectValue(preset.value.company_country, COUNTRY_OPTIONS);
form.company_country = countryPreset.select || 'Canada';
form.company_country_other = countryPreset.other;

const effectiveCountry = computed(() => {
    return form.company_country === '__other__'
        ? String(form.company_country_other || '').trim()
        : form.company_country;
});

const provinceOptions = computed(() => {
    const provinces = PROVINCES_BY_COUNTRY[effectiveCountry.value] || [];
    return [
        { id: '', name: 'Selectionner une province' },
        ...provinces.map((value) => ({ id: value, name: value })),
        { id: '__other__', name: 'Autre...' },
    ];
});

const provincePreset = resolveSelectValue(preset.value.company_province, provinceOptions.value);
form.company_province = provincePreset.select;
form.company_province_other = provincePreset.other;

const effectiveProvince = computed(() => {
    return form.company_province === '__other__'
        ? String(form.company_province_other || '').trim()
        : form.company_province;
});

const cityOptions = computed(() => {
    const country = effectiveCountry.value;
    const province = effectiveProvince.value;
    const cities = CITIES_BY_COUNTRY_AND_PROVINCE[country]?.[province] || [];

    return [
        { id: '', name: 'Selectionner une ville' },
        ...cities.map((value) => ({ id: value, name: value })),
        { id: '__other__', name: 'Autre...' },
    ];
});

const cityPreset = resolveSelectValue(preset.value.company_city, cityOptions.value);
form.company_city = cityPreset.select;
form.company_city_other = cityPreset.other;

watch(
    () => form.company_country,
    () => {
        form.company_province = '';
        form.company_province_other = '';
        form.company_city = '';
        form.company_city_other = '';
    }
);

watch(
    () => form.company_province,
    () => {
        form.company_city = '';
        form.company_city_other = '';
    }
);

const companyTypeLabel = computed(() => (form.company_type === 'products' ? 'Entreprise de produits' : 'Entreprise de services'));

const goNext = () => {
    if (step.value < totalSteps) {
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

const submit = () => {
    const normalizeText = (value) => {
        const trimmed = String(value || '').trim();
        return trimmed.length ? trimmed : null;
    };

    form
        .transform((data) => {
            const country = data.company_country === '__other__' ? data.company_country_other : data.company_country;
            const province = data.company_province === '__other__' ? data.company_province_other : data.company_province;
            const city = data.company_city === '__other__' ? data.company_city_other : data.company_city;

            const payload = {
                ...data,
                company_country: normalizeText(country),
                company_province: normalizeText(province),
                company_city: normalizeText(city),
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
</script>

<template>
    <GuestLayout>
        <Head title="Onboarding" />

        <div class="space-y-4">
            <div>
                <h1 class="text-lg font-semibold text-stone-900 dark:text-neutral-100">Créer votre espace</h1>
                <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                    Étape {{ step }} / {{ totalSteps }}
                </p>
            </div>

            <div v-if="step === 1" class="space-y-3">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Entreprise</h2>

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

                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">Pays (optionnel)</label>
                        <select v-model="form.company_country"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option v-for="option in COUNTRY_OPTIONS" :key="option.id" :value="option.id">
                                {{ option.name }}
                            </option>
                        </select>
                        <InputError class="mt-1" :message="form.errors.company_country" />
                        <div v-if="form.company_country === '__other__'" class="mt-2">
                            <FloatingInput v-model="form.company_country_other" label="Pays (autre)" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">Province / Etat (optionnel)</label>
                        <select v-model="form.company_province"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option v-for="option in provinceOptions" :key="option.id" :value="option.id">
                                {{ option.name }}
                            </option>
                        </select>
                        <InputError class="mt-1" :message="form.errors.company_province" />
                        <div v-if="form.company_province === '__other__'" class="mt-2">
                            <FloatingInput v-model="form.company_province_other" label="Province / Etat (autre)" />
                        </div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs text-stone-500 dark:text-neutral-400">Ville (optionnel)</label>
                        <select v-model="form.company_city"
                            class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                            <option v-for="option in cityOptions" :key="option.id" :value="option.id">
                                {{ option.name }}
                            </option>
                        </select>
                        <InputError class="mt-1" :message="form.errors.company_city" />
                        <div v-if="form.company_city === '__other__'" class="mt-2">
                            <FloatingInput v-model="form.company_city_other" label="Ville (autre)" />
                        </div>
                    </div>
                </div>
            </div>

            <div v-else-if="step === 2" class="space-y-3">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Type d'entreprise</h2>

                <div class="space-y-2">
                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                        <input type="radio" name="company_type" value="services" v-model="form.company_type" />
                        <span>🛠️ Entreprise de services</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                        <input type="radio" name="company_type" value="products" v-model="form.company_type" />
                        <span>📦 Entreprise de produits</span>
                    </label>
                </div>

                <InputError class="mt-1" :message="form.errors.company_type" />

                <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 text-sm text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200">
                    Modules activés : <span class="font-medium">{{ companyTypeLabel }}</span>
                </div>
            </div>

            <div v-else-if="step === 3" class="space-y-3">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Rôle du créateur</h2>

                <p class="text-sm text-stone-600 dark:text-neutral-400">Êtes-vous le propriétaire de l'entreprise ?</p>

                <div class="space-y-2">
                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                        <input type="radio" name="is_owner" value="1" v-model="form.is_owner" />
                        <span>✅ Oui</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-neutral-200">
                        <input type="radio" name="is_owner" value="0" v-model="form.is_owner" />
                        <span>❌ Non</span>
                    </label>
                </div>

                <InputError class="mt-1" :message="form.errors.is_owner" />

                <div v-if="form.is_owner === '0'" class="mt-3 space-y-2">
                    <div class="rounded-sm border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-200">
                        Un compte propriétaire sera créé automatiquement (mot de passe temporaire affiché à la fin).
                    </div>

                    <FloatingInput v-model="form.owner_name" label="Nom du propriétaire" />
                    <InputError class="mt-1" :message="form.errors.owner_name" />

                    <FloatingInput v-model="form.owner_email" label="Email du propriétaire" />
                    <InputError class="mt-1" :message="form.errors.owner_email" />
                </div>
            </div>

            <div v-else-if="step === 4" class="space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Inviter l'équipe (optionnel)</h2>
                    <button type="button" @click="addInvite"
                        class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                        + Ajouter
                    </button>
                </div>

                <div v-if="!form.invites.length" class="text-sm text-stone-600 dark:text-neutral-400">
                    Aucune invitation ajoutée.
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
                    <p class="font-medium">Résumé</p>
                    <p class="mt-1">
                        <span class="font-medium">Entreprise :</span> {{ form.company_name || '-' }}
                        <span class="mx-2">•</span>
                        <span class="font-medium">Type :</span> {{ companyTypeLabel }}
                    </p>
                </div>
            </div>

            <div class="flex items-center justify-between pt-2">
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
                        Accéder au tableau de bord
                    </button>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
