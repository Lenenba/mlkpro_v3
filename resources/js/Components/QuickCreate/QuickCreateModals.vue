<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import Modal from '@/Components/UI/Modal.vue';
import ProductQuickForm from '@/Components/QuickCreate/ProductQuickForm.vue';
import ServiceQuickForm from '@/Components/QuickCreate/ServiceQuickForm.vue';
import CustomerQuickForm from '@/Components/QuickCreate/CustomerQuickForm.vue';
import QuoteQuickDialog from '@/Components/QuickCreate/QuoteQuickDialog.vue';
import RequestQuickForm from '@/Components/QuickCreate/RequestQuickForm.vue';
import { isFeatureEnabled } from '@/utils/features';

const page = usePage();
const featureFlags = computed(() => page.props.auth?.account?.features || {});
const canProducts = computed(() => isFeatureEnabled(featureFlags.value, 'products'));
const canServices = computed(() => isFeatureEnabled(featureFlags.value, 'services'));
const canQuotes = computed(() => isFeatureEnabled(featureFlags.value, 'quotes'));
const canRequests = computed(() => isFeatureEnabled(featureFlags.value, 'requests'));
const canSales = computed(() => isFeatureEnabled(featureFlags.value, 'sales'));

const customers = ref([]);
const categories = ref([]);
const loadingCustomers = ref(false);
const loadingCategories = ref(false);
const customerError = ref('');
const categoryError = ref('');

const fetchCustomers = async () => {
    loadingCustomers.value = true;
    customerError.value = '';
    try {
        const response = await axios.get(route('customer.options'));
        customers.value = response.data?.customers || [];
    } catch (error) {
        customerError.value = 'Unable to load customers.';
    } finally {
        loadingCustomers.value = false;
    }
};

const fetchCategories = async () => {
    loadingCategories.value = true;
    categoryError.value = '';
    try {
        const response = await axios.get(route('product.options'));
        categories.value = response.data?.categories || [];
    } catch (error) {
        categoryError.value = 'Unable to load categories.';
    } finally {
        loadingCategories.value = false;
    }
};

const handleCustomerCreated = (payload) => {
    const customer = payload?.customer;
    if (!customer) {
        return;
    }

    const nextCustomer = {
        ...customer,
        properties: payload?.properties || [],
    };

    const existingIndex = customers.value.findIndex((item) => item.id === customer.id);
    if (existingIndex >= 0) {
        customers.value.splice(existingIndex, 1, nextCustomer);
    } else {
        customers.value.unshift(nextCustomer);
    }
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
};

onMounted(() => {
    if (canQuotes.value || canRequests.value || canSales.value) {
        fetchCustomers();
    }
    if (canProducts.value || canServices.value) {
        fetchCategories();
    }
});
</script>

<template>
    <Modal :title="'New customer'" :id="'hs-quick-create-customer'">
        <CustomerQuickForm
            :overlay-id="'#hs-quick-create-customer'"
            submit-label="Create customer"
            :close-on-success="true"
            @created="handleCustomerCreated"
        />
    </Modal>

    <Modal v-if="canProducts" :title="'New product'" :id="'hs-quick-create-product'">
        <div v-if="loadingCategories" class="text-sm text-stone-500 dark:text-neutral-400">
            Loading categories...
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

    <Modal v-if="canServices" :title="'New service'" :id="'hs-quick-create-service'">
        <div v-if="loadingCategories" class="text-sm text-stone-500 dark:text-neutral-400">
            Loading categories...
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

    <Modal v-if="canQuotes" :title="'New quote'" :id="'hs-quick-create-quote'">
        <div v-if="customerError" class="mb-3 text-sm text-red-600">
            {{ customerError }}
        </div>
        <QuoteQuickDialog
            :customers="customers"
            :loading="loadingCustomers"
            :overlay-id="'#hs-quick-create-quote'"
            @customer-created="handleCustomerCreated"
        />
    </Modal>

    <Modal v-if="canRequests" :title="'New request'" :id="'hs-quick-create-request'">
        <div v-if="customerError" class="mb-3 text-sm text-red-600">
            {{ customerError }}
        </div>
        <RequestQuickForm :customers="customers" :overlay-id="'#hs-quick-create-request'" />
    </Modal>
</template>
