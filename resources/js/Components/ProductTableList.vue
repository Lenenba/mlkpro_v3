<script setup>
import { ref, watch, computed, defineProps, defineEmits } from 'vue';
import axios from 'axios';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingNumberMiniInput from '@/Components/FloatingNumberMiniInput.vue';

// Define component props
const props = defineProps({
  // v-model for product lines (an array of product objects)
  modelValue: {
    type: Array,
    default: () => [{ id: null, name: '', quantity: 1, price: 0, total: 0 }],
  },
  // Optional search endpoint (default is "product.search")
  searchEndpoint: {
    type: String,
    default: 'product.search',
  },
});

// Define events to emit
const emits = defineEmits(['update:modelValue', 'update:subtotal']);

// Local reactive state for product lines
const products = ref([...props.modelValue]);

// Computed property for subtotal calculation
const subtotal = computed(() => {
  return products.value.reduce((acc, product) => acc + product.total, 0);
});

// Watch products for changes to recalculate totals and emit updates
watch(
  products,
  (newProducts) => {
    // Update each product's total (quantity * price)
    newProducts.forEach(product => {
      product.total = product.quantity * product.price;
    });
    // Emit updated product list and subtotal to parent component
    emits('update:modelValue', newProducts);
    emits('update:subtotal', subtotal.value);
  },
  { deep: true }
);

// Search results for each product line, stored as an array with index matching the product line
const searchResults = ref([]);

// Function to add a new product line
const addNewLine = () => {
  products.value.push({ id: null, name: '', quantity: 1, price: 0, total: 0 });
};

// Function to remove a product line
const removeLine = index => {
  if (products.value.length > 1) {
    products.value.splice(index, 1);
  }
};

// Function to search for products based on query and update search results for the given index
const searchProducts = async (query, index) => {
  if (query.length > 0) {
    try {
      const response = await axios.get(route(props.searchEndpoint), { params: { query } });
      searchResults.value[index] = response.data;
    } catch (error) {
      console.error('Error fetching products:', error);
    }
  } else {
    searchResults.value[index] = [];
  }
};

// Function to select a product from the search results and update the product line
const selectProduct = (product, index) => {
  products.value[index] = {
    id: product.id,
    name: product.name,
    quantity: 1,
    price: product.price,
    total: product.price,
  };
  searchResults.value[index] = [];
};
</script>

<template>
  <div class="space-y-3 flex flex-col bg-white  rounded-sm shadow-sm xl:shadow-none dark:bg-green-800 dark:border-green-700">
    <!-- Table Section -->
    <div class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-gray-100 [&::-webkit-scrollbar-thumb]:bg-gray-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
      <div class="min-w-full inline-block align-middle min-h-[300px]">
        <!-- Table -->
        <table class="min-w-full divide-y divide-gray-200 dark:divide-neutral-700">
          <thead>
            <tr>
              <th scope="col" class="min-w-[450px]">
                <div class="pe-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-gray-800 dark:text-neutral-200">
                  Product/Services
                </div>
              </th>
              <th scope="col">
                <div class="px-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-gray-800 dark:text-neutral-200">
                  Qty.
                </div>
              </th>
              <th scope="col">
                <div class="px-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-gray-800 dark:text-neutral-200">
                  Unit cost
                </div>
              </th>
              <th scope="col">
                <div class="px-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-gray-800 dark:text-neutral-200">
                  Total
                </div>
              </th>
              <th scope="col" class="size-px">
                <div class="px-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-gray-800 dark:text-neutral-200">
                  Actions
                </div>
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-neutral-700">
            <tr v-for="(product, index) in products" :key="index">
              <td class="size-px whitespace-nowrap px-4 py-3">
                <div class="relative">
                  <FloatingInput autofocus v-model="products[index].name" label="Name"
                    @input="searchProducts(products[index].name, index)" />
                </div>
                <div class="relative w-full">
                  <ul v-if="searchResults[index]?.length"
                      class="absolute left-0 top-full z-50 w-full max-h-60 overflow-y-auto bg-white border border-gray-200 rounded-md shadow-lg dark:bg-neutral-800 dark:border-neutral-700">
                    <li v-for="result in searchResults[index]" :key="result.id"
                        @click="selectProduct(result, index)"
                        class="px-3 py-2 cursor-pointer hover:bg-gray-100 dark:hover:bg-neutral-700 text-gray-800 dark:text-neutral-200">
                      {{ result.name }}
                    </li>
                  </ul>
                </div>
              </td>
              <td class="size-px whitespace-nowrap px-4 py-3">
                <FloatingNumberMiniInput v-model="products[index].quantity" label="Quantity" />
              </td>
              <td class="size-px whitespace-nowrap px-4 py-3">
                <FloatingNumberMiniInput v-model="products[index].price" aria-disabled="true" label="Unit Price" />
              </td>
              <td class="size-px whitespace-nowrap px-4 py-3">
                <FloatingNumberMiniInput v-model="products[index].total" label="Total" />
              </td>
              <td>
                <button type="button" v-if="products.length > 1" @click="removeLine(index)"
                    class="px-4 py-4 inline-flex items-center gap-x-2 text-sm font-medium text-red-800 hover:text-red-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none dark:text-red-300">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                      fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                      stroke-linejoin="round" class="lucide lucide-trash-2">
                    <path d="M3 6h18" />
                    <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" />
                    <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" />
                    <line x1="10" x2="10" y1="11" y2="17" />
                    <line x1="14" x2="14" y1="11" y2="17" />
                  </svg>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <!-- End Table -->
      </div>
    </div>
    <!-- End Table Section -->
    <div class="text-xs text-gray-600 flex justify-between mt-5">
      <button type="button" @click="addNewLine"
          class="hs-tooltip-toggle ml-4 py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
          Add new product line
      </button>
    </div>
  </div>
</template>
