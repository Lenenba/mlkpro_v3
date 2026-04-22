import { ref } from 'vue';

const countryFilter = 'countrycode:ca,us';

const buildSuggestion = (feature) => ({
    id: feature.properties?.place_id || feature.properties?.formatted || feature.properties?.name,
    label: feature.properties?.formatted || feature.properties?.name || '',
    details: feature.properties || {},
});

const resolveStreet1 = (address) => {
    const streetParts = [];

    if (address.house_number || address.housenumber) {
        streetParts.push(address.house_number || address.housenumber);
    }
    if (address.street) {
        streetParts.push(address.street);
    }

    return streetParts.join(' ').trim();
};

const resolveCity = (address) => address.city || address.town || address.village || address.hamlet || address.suburb || '';
const resolveState = (address) => address.state || address.county || address.region || '';

export function assignGeoapifyAddress(target, details = {}) {
    const normalized = {
        street1: resolveStreet1(details),
        street2: '',
        city: resolveCity(details),
        state: resolveState(details),
        zip: details.postcode || '',
        country: details.country || '',
    };

    if (target && typeof target === 'object') {
        Object.assign(target, normalized);
    }

    return normalized;
}

export function buildAddressSearchLabel(fields = {}) {
    return [
        fields.street1,
        fields.city,
        fields.state,
        fields.zip,
        fields.country,
    ]
        .map((value) => String(value || '').trim())
        .filter(Boolean)
        .join(', ');
}

export function useGeoapifyAddressAutocomplete(options = {}) {
    const query = ref(options.initialQuery || '');
    const suggestions = ref([]);
    const isSearching = ref(false);
    const geoapifyKey = import.meta.env.VITE_GEOAPIFY_KEY;
    let latestRequestId = 0;

    const searchAddress = async () => {
        const value = String(query.value || '').trim();

        if (value.length < 2 || !geoapifyKey) {
            latestRequestId += 1;
            suggestions.value = [];
            isSearching.value = false;
            return;
        }

        const requestId = ++latestRequestId;
        isSearching.value = true;

        try {
            const url = new URL('https://api.geoapify.com/v1/geocode/autocomplete');
            url.search = new URLSearchParams({
                text: value,
                apiKey: geoapifyKey,
                limit: '5',
                filter: countryFilter,
            }).toString();

            const response = await fetch(url.toString());
            if (!response.ok) {
                throw new Error(`Geoapify request failed: ${response.status}`);
            }

            const data = await response.json();
            if (requestId !== latestRequestId) {
                return;
            }

            suggestions.value = (data.features || []).map(buildSuggestion);
        } catch (error) {
            if (requestId !== latestRequestId) {
                return;
            }

            suggestions.value = [];
            options.onError?.(error);
        } finally {
            if (requestId === latestRequestId) {
                isSearching.value = false;
            }
        }
    };

    const selectAddress = (details = {}) => {
        options.onSelect?.(details);
        query.value = details.formatted || details.name || query.value;
        suggestions.value = [];
    };

    const resetSearch = (nextQuery = '') => {
        latestRequestId += 1;
        query.value = nextQuery;
        suggestions.value = [];
        isSearching.value = false;
    };

    return {
        query,
        suggestions,
        isSearching,
        searchAddress,
        selectAddress,
        resetSearch,
    };
}
