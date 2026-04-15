<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import Modal from '@/Components/UI/Modal.vue';
import ProductQuickForm from '@/Components/QuickCreate/ProductQuickForm.vue';
import ServiceQuickForm from '@/Components/QuickCreate/ServiceQuickForm.vue';
import CustomerQuickForm from '@/Components/QuickCreate/CustomerQuickForm.vue';
import QuoteQuickDialog from '@/Components/QuickCreate/QuoteQuickDialog.vue';
import RequestQuickForm from '@/Components/QuickCreate/RequestQuickForm.vue';
import { useI18n } from 'vue-i18n';
import { useAccountFeatures } from '@/Composables/useAccountFeatures';

const { hasFeature } = useAccountFeatures();
const canProducts = computed(() => hasFeature('products'));
const canServices = computed(() => hasFeature('services'));
const canQuotes = computed(() => hasFeature('quotes'));
const canRequests = computed(() => hasFeature('requests'));
const canSales = computed(() => hasFeature('sales'));
const categoryOptionsRoutes = computed(() => {
    const routes = [];
    if (canProducts.value) {
        routes.push('product.options');
    }
    if (canServices.value) {
        routes.push('service.options');
    }
    return routes;
});

const requestCustomers = ref([]);
const quoteCustomers = ref([]);
const categories = ref([]);
const loadingRequestCustomers = ref(false);
const loadingQuoteCustomers = ref(false);
const loadingCategories = ref(false);
const requestCustomerError = ref('');
const quoteCustomerError = ref('');
const categoryError = ref('');
const requestCustomersLoaded = ref(false);
const quoteCustomersLoaded = ref(false);
const categoriesLoaded = ref(false);

const { t } = useI18n();

const customerScopeState = {
    request: {
        rows: requestCustomers,
        loading: loadingRequestCustomers,
        error: requestCustomerError,
        loaded: requestCustomersLoaded,
    },
    quote: {
        rows: quoteCustomers,
        loading: loadingQuoteCustomers,
        error: quoteCustomerError,
        loaded: quoteCustomersLoaded,
    },
};

const fetchCustomers = async (scope) => {
    const state = customerScopeState[scope];
    if (!state || state.loading.value) {
        return;
    }

    state.loading.value = true;
    state.error.value = '';
    try {
        const response = await axios.get(route('customer.options'), {
            params: {
                scope,
            },
        });
        state.rows.value = Array.isArray(response.data?.customers) ? response.data.customers : [];
        state.loaded.value = true;
    } catch (error) {
        state.error.value = t('quick_create.errors.load_customers');
    } finally {
        state.loading.value = false;
    }
};

const fetchCategories = async () => {
    if (!categoryOptionsRoutes.value.length) {
        return;
    }

    if (loadingCategories.value) {
        return;
    }

    loadingCategories.value = true;
    categoryError.value = '';
    try {
        let resolvedCategories = null;
        for (const routeName of categoryOptionsRoutes.value) {
            try {
                const response = await axios.get(route(routeName));
                resolvedCategories = response.data?.categories || [];
                break;
            } catch (requestError) {
                if (requestError?.response?.status !== 403) {
                    throw requestError;
                }
            }
        }

        if (resolvedCategories === null) {
            throw new Error('Unable to load category options');
        }

        categories.value = resolvedCategories;
        categoriesLoaded.value = true;
    } catch (error) {
        categoryError.value = t('quick_create.errors.load_categories');
    } finally {
        loadingCategories.value = false;
    }
};

const ensureRequestCustomersLoaded = async () => {
    if (requestCustomersLoaded.value || (!canRequests.value && !canSales.value)) {
        return;
    }

    await fetchCustomers('request');
};

const ensureQuoteCustomersLoaded = async () => {
    if (quoteCustomersLoaded.value || !canQuotes.value) {
        return;
    }

    await fetchCustomers('quote');
};

const ensureCategoriesLoaded = async () => {
    if (categoriesLoaded.value || (!canProducts.value && !canServices.value)) {
        return;
    }

    await fetchCategories();
};

const buildRequestCustomer = (payload) => {
    const customer = payload?.customer;
    if (!customer?.id) {
        return null;
    }

    return {
        id: customer.id,
        company_name: customer.company_name,
        first_name: customer.first_name,
        last_name: customer.last_name,
        email: customer.email,
        phone: customer.phone,
        number: customer.number,
        logo: customer.logo,
        logo_url: customer.logo_url,
    };
};

const buildQuoteCustomer = (payload) => {
    const customer = buildRequestCustomer(payload);
    if (!customer) {
        return null;
    }

    return {
        ...customer,
        properties: Array.isArray(payload?.properties)
            ? payload.properties.map((property) => ({
                id: property.id,
                is_default: Boolean(property.is_default),
                street1: property.street1,
                city: property.city,
            }))
            : [],
    };
};

const upsertCustomerOption = (rows, customer) => {
    if (!customer?.id) {
        return;
    }

    const existingIndex = rows.value.findIndex((item) => item.id === customer.id);
    if (existingIndex >= 0) {
        rows.value.splice(existingIndex, 1, customer);
        return;
    }

    rows.value.unshift(customer);
};

const handleCustomerCreated = (payload) => {
    upsertCustomerOption(requestCustomers, buildRequestCustomer(payload));
    upsertCustomerOption(quoteCustomers, buildQuoteCustomer(payload));
};

const handleCategoryCreated = (category) => {
    if (!category?.id) {
        return;
    }

    const existingIndex = categories.value.findIndex((item) => item.id === category.id);
    if (existingIndex >= 0) {
        categories.value.splice(existingIndex, 1, category);
    } else {
        categories.value.push(category);
        categories.value.sort((a, b) => String(a.name).localeCompare(String(b.name)));
    }

    categoriesLoaded.value = true;
};
</script>

<template>
    <Modal :title="$t('quick_create.new_customer')" :id="'hs-quick-create-customer'">
        <CustomerQuickForm
            :overlay-id="'#hs-quick-create-customer'"
            :submit-label="$t('quick_create.create_customer')"
            :close-on-success="true"
            @created="handleCustomerCreated"
        />
    </Modal>

    <Modal v-if="canProducts" :title="$t('quick_create.new_product')" :id="'hs-quick-create-product'" @open="ensureCategoriesLoaded">
        <div v-if="loadingCategories" class="text-sm text-stone-500 dark:text-neutral-400">
            {{ $t('quick_create.loading_categories') }}
        </div>
        <div v-else-if="categoryError" class="text-sm text-red-600">
            {{ categoryError }}
        </div>
        <div v-else>
            <ProductQuickForm
                :categories="categories"
                :overlay-id="'#hs-quick-create-product'"
                @category-created="handleCategoryCreated"
            />
        </div>
    </Modal>

    <Modal v-if="canServices" :title="$t('quick_create.new_service')" :id="'hs-quick-create-service'" @open="ensureCategoriesLoaded">
        <div v-if="loadingCategories" class="text-sm text-stone-500 dark:text-neutral-400">
            {{ $t('quick_create.loading_categories') }}
        </div>
        <div v-else-if="categoryError" class="text-sm text-red-600">
            {{ categoryError }}
        </div>
        <div v-else>
            <ServiceQuickForm
                :categories="categories"
                :overlay-id="'#hs-quick-create-service'"
                @category-created="handleCategoryCreated"
            />
        </div>
    </Modal>

    <Modal v-if="canQuotes" :title="$t('quick_create.new_quote')" :id="'hs-quick-create-quote'" @open="ensureQuoteCustomersLoaded">
        <div v-if="quoteCustomerError" class="mb-3 text-sm text-red-600">
            {{ quoteCustomerError }}
        </div>
        <QuoteQuickDialog
            :customers="quoteCustomers"
            :loading="loadingQuoteCustomers"
            :overlay-id="'#hs-quick-create-quote'"
            @customer-created="handleCustomerCreated"
        />
    </Modal>

    <Modal v-if="canRequests" :title="$t('quick_create.new_request')" :id="'hs-quick-create-request'" @open="ensureRequestCustomersLoaded">
        <div v-if="requestCustomerError" class="mb-3 text-sm text-red-600">
            {{ requestCustomerError }}
        </div>
        <RequestQuickForm
            :customers="requestCustomers"
            :loading="loadingRequestCustomers"
            :overlay-id="'#hs-quick-create-request'"
            @customer-created="handleCustomerCreated"
        />
    </Modal>
</template>
