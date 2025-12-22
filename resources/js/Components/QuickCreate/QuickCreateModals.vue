<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';
import Modal from '@/Components/UI/Modal.vue';
import ProductQuickForm from '@/Components/QuickCreate/ProductQuickForm.vue';
import CustomerQuickForm from '@/Components/QuickCreate/CustomerQuickForm.vue';
import QuoteQuickDialog from '@/Components/QuickCreate/QuoteQuickDialog.vue';

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

onMounted(() => {
    fetchCustomers();
    fetchCategories();
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

    <Modal :title="'New product'" :id="'hs-quick-create-product'">
        <div v-if="loadingCategories" class="text-sm text-stone-500 dark:text-neutral-400">
            Loading categories...
        </div>
        <div v-else-if="categoryError" class="text-sm text-red-600">
            {{ categoryError }}
        </div>
        <div v-else-if="!categories.length" class="text-sm text-stone-500 dark:text-neutral-400">
            Add at least one product category before creating products.
        </div>
        <div v-else>
            <ProductQuickForm :categories="categories" :overlay-id="'#hs-quick-create-product'" />
        </div>
    </Modal>

    <Modal :title="'New quote'" :id="'hs-quick-create-quote'">
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
</template>
