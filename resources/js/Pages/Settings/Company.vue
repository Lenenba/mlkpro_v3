<script setup>
import { computed, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import InputError from '@/Components/InputError.vue';
import DropzoneInput from '@/Components/DropzoneInput.vue';

const props = defineProps({
    company: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        default: () => [],
    },
});

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
    company_name: props.company.company_name || '',
    company_logo: props.company.company_logo || null,
    company_description: props.company.company_description || '',
    company_country: '',
    company_country_other: '',
    company_province: '',
    company_province_other: '',
    company_city: '',
    company_city_other: '',
    company_type: props.company.company_type || 'services',
});

const categoryForm = useForm({
    name: '',
});

const countryPreset = resolveSelectValue(props.company.company_country, COUNTRY_OPTIONS);
form.company_country = countryPreset.select || '';
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

const provincePreset = resolveSelectValue(props.company.company_province, provinceOptions.value);
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

const cityPreset = resolveSelectValue(props.company.company_city, cityOptions.value);
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
        .put(route('settings.company.update'), { preserveScroll: true, forceFormData: true });
};

const canAddCategory = computed(() => categoryForm.name.trim().length > 0);

const addCategory = () => {
    if (!canAddCategory.value) {
        return;
    }

    categoryForm.post(route('settings.categories.store'), {
        preserveScroll: true,
        onSuccess: () => categoryForm.reset('name'),
    });
};
</script>

<template>
    <Head title="Entreprise" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-4xl space-y-5">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-gray-800 dark:text-neutral-100">Parametres entreprise</h1>
                    <p class="mt-1 text-sm text-gray-600 dark:text-neutral-400">
                        Mettez a jour les informations de votre entreprise.
                    </p>
                </div>
            </div>

            <div class="flex flex-col bg-white border border-gray-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                <div class="p-4 space-y-4">
                    <div>
                        <FloatingInput v-model="form.company_name" label="Nom de l'entreprise" />
                        <InputError class="mt-1" :message="form.errors.company_name" />
                    </div>

                    <div class="space-y-2">
                        <p class="text-xs text-gray-500 dark:text-neutral-400">Logo (optionnel)</p>
                        <DropzoneInput v-model="form.company_logo" label="Telecharger votre logo" />
                        <InputError class="mt-1" :message="form.errors.company_logo" />
                    </div>

                    <div>
                        <label class="block text-xs text-gray-500 dark:text-neutral-400">Description (optionnel)</label>
                        <textarea v-model="form.company_description"
                            class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200"
                            rows="3" />
                        <InputError class="mt-1" :message="form.errors.company_description" />
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-neutral-400">Pays (optionnel)</label>
                            <select v-model="form.company_country"
                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
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
                            <label class="block text-xs text-gray-500 dark:text-neutral-400">Province / Etat (optionnel)</label>
                            <select v-model="form.company_province"
                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
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
                            <label class="block text-xs text-gray-500 dark:text-neutral-400">Ville (optionnel)</label>
                            <select v-model="form.company_city"
                                class="mt-1 block w-full rounded-sm border-gray-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
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

                    <div>
                        <p class="text-xs text-gray-500 dark:text-neutral-400">Type d'entreprise</p>
                        <div class="mt-2 space-y-2">
                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-neutral-200">
                                <input type="radio" name="company_type" value="services" v-model="form.company_type" />
                                <span>Entreprise de services</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-neutral-200">
                                <input type="radio" name="company_type" value="products" v-model="form.company_type" />
                                <span>Entreprise de produits</span>
                            </label>
                        </div>
                        <InputError class="mt-1" :message="form.errors.company_type" />
                    </div>

                    <div class="flex justify-end">
                        <button type="button" @click="submit" :disabled="form.processing"
                            class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            Enregistrer
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex flex-col bg-white border border-gray-200 shadow-sm rounded-sm overflow-hidden dark:bg-neutral-800 dark:border-neutral-700">
                <div class="p-4 space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-neutral-100">Categories de services / produits</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-neutral-400">
                            Ajoutez des categories pour organiser vos services et produits.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <span v-for="category in props.categories" :key="category.id"
                            class="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-700 dark:bg-neutral-900 dark:text-neutral-200">
                            {{ category.name }}
                        </span>
                        <span v-if="!props.categories.length" class="text-sm text-gray-500 dark:text-neutral-400">
                            Aucune categorie pour le moment.
                        </span>
                    </div>

                    <div class="flex flex-col gap-3 md:flex-row md:items-end">
                        <div class="flex-1">
                            <FloatingInput v-model="categoryForm.name" label="Nouvelle categorie" />
                            <InputError class="mt-1" :message="categoryForm.errors.name" />
                        </div>
                        <button type="button" @click="addCategory" :disabled="!canAddCategory || categoryForm.processing"
                            class="w-full md:w-auto py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none">
                            Ajouter
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
