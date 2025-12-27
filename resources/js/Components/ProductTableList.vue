<script setup>
import { ref, watch, computed } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
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
  itemType: {
    type: String,
    default: null,
  },
  readOnly: {
    type: Boolean,
    default: false,
  },
  enablePriceLookup: {
    type: Boolean,
    default: false,
  },
  allowMixedTypes: {
    type: Boolean,
    default: false,
  },
});

// Define events to emit
const emits = defineEmits(['update:modelValue', 'update:subtotal']);

const page = usePage();
const companyType = computed(() => page.props.auth?.account?.company?.type ?? null);
const allowMixed = computed(() => props.allowMixedTypes || props.itemType === 'mixed');
const defaultItemType = computed(() => {
  if (props.itemType && props.itemType !== 'mixed') {
    return props.itemType;
  }
  return companyType.value === 'products' ? 'product' : 'service';
});
const lineItemLabel = computed(() => {
  if (allowMixed.value) {
    return 'Produit / Service';
  }
  return defaultItemType.value === 'service' ? 'Service' : 'Produit';
});
const itemTypeOptions = [
  { id: 'product', name: 'Produit' },
  { id: 'service', name: 'Service' },
];

// Local reactive state for product lines
const normalizeLine = (line = {}) => ({
  ...line,
  id: line.id ?? null,
  name: line.name ?? '',
  quantity: line.quantity ?? 1,
  price: line.price ?? 0,
  total: line.total ?? 0,
  item_type: line.item_type ?? defaultItemType.value,
  source_details: line.source_details ?? null,
});
const products = ref(props.modelValue.map(normalizeLine));

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
const priceLookupResults = ref({});
const priceLookupMeta = ref({});
const priceLookupErrors = ref({});
const priceLookupLoading = ref({});
const bulkLookupLoading = ref(false);
const draftCreateLoading = ref({});

const normalizeName = (value) => String(value || '').trim().toLowerCase();

const findCatalogMatch = async (index, query) => {
  const normalized = normalizeName(query);
  if (!normalized) {
    return null;
  }

  let results = searchResults.value[index];
  if (!Array.isArray(results) || results.length === 0) {
    try {
      const lineItemType = products.value[index]?.item_type || defaultItemType.value;
      const response = await axios.get(route(props.searchEndpoint), {
        params: {
          query,
          item_type: lineItemType,
        },
      });
      results = response.data;
      searchResults.value[index] = results;
    } catch (error) {
      return null;
    }
  }

  const match = (results || []).find(result => normalizeName(result.name) === normalized);
  if (match) {
    selectProduct(match, index);
    return match;
  }

  return null;
};

// Function to add a new product line
const addNewLine = () => {
  if (props.readOnly) {
    return;
  }
  products.value.push(normalizeLine());
};

// Function to remove a product line
const removeLine = index => {
  if (props.readOnly) {
    return;
  }
  if (products.value.length > 1) {
    products.value.splice(index, 1);
  }
};

// Function to search for products based on query and update search results for the given index
const searchProducts = async (query, index) => {
  if (props.readOnly) {
    return;
  }
  if (query.length > 0) {
    const lineItemType = products.value[index]?.item_type || defaultItemType.value;
    try {
      const response = await axios.get(route(props.searchEndpoint), {
        params: {
          query,
          item_type: lineItemType,
        },
      });
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
  if (props.readOnly) {
    return;
  }
  products.value[index] = {
    id: product.id,
    name: product.name,
    quantity: 1,
    price: product.price,
    total: product.price,
    item_type: product.item_type || defaultItemType.value,
    unit: product.unit ?? null,
    source_details: null,
  };
  searchResults.value[index] = [];
};

const buildBenchmarks = (sources) => {
  const prices = sources
    .map(source => Number(source.price))
    .filter(value => !Number.isNaN(value))
    .sort((a, b) => a - b);
  if (!prices.length) {
    return null;
  }
  const min = prices[0];
  const max = prices[prices.length - 1];
  const median = prices[Math.floor((prices.length - 1) / 2)];
  return {
    min: Number(min.toFixed(2)),
    median: Number(median.toFixed(2)),
    max: Number(max.toFixed(2)),
  };
};

const createDraftProduct = async (index) => {
  if (props.readOnly) {
    return;
  }
  const line = products.value[index];
  if (!line || line.id) {
    return;
  }
  const name = line.name?.trim();
  if (!name) {
    return;
  }
  if (draftCreateLoading.value[index]) {
    return;
  }
  draftCreateLoading.value[index] = true;
  try {
    const response = await axios.post(route('product.draft.store'), {
      name,
      price: Number(line.price) || 0,
      description: line.description ?? null,
      unit: line.unit ?? null,
      item_type: line.item_type || defaultItemType.value,
      source_details: line.source_details ?? null,
    });
    const product = response?.data?.product;
    if (product?.id) {
      products.value[index].id = product.id;
      if (!products.value[index].name && product.name) {
        products.value[index].name = product.name;
      }
    }
  } catch (error) {
    priceLookupErrors.value[index] = error?.response?.data?.message || 'Draft creation failed.';
  } finally {
    draftCreateLoading.value[index] = false;
  }
};

const applySourceToLine = async (index, source) => {
  if (!source || props.readOnly) {
    return;
  }
  const numeric = Number(source.price);
  if (!Number.isNaN(numeric)) {
    products.value[index].price = numeric;
  }
  const sources = priceLookupResults.value[index] || [];
  const meta = priceLookupMeta.value[index] || {};
  const benchmarks = buildBenchmarks(sources);
  products.value[index].source_details = {
    sources,
    selected_source: source,
    best_source: sources[0] || source,
    source_query: meta.query || products.value[index].name,
    selection_reason: `Selected price from ${source.name || 'supplier'}.`,
    source_status: sources.length ? 'live' : 'missing',
    benchmarks,
  };
  await createDraftProduct(index);
};

const searchPrices = async (index, applyBest = false) => {
  if (props.readOnly || !props.enablePriceLookup) {
    return;
  }
  if (products.value[index]?.id) {
    return;
  }
  const query = products.value[index]?.name?.trim();
  if (!query) {
    priceLookupErrors.value[index] = 'Enter a product name to search.';
    return;
  }

  const catalogMatch = await findCatalogMatch(index, query);
  if (catalogMatch) {
    return;
  }
  if (Array.isArray(searchResults.value[index]) && searchResults.value[index].length) {
    priceLookupErrors.value[index] = 'Select a catalog item before searching prices.';
    return;
  }

  priceLookupErrors.value[index] = '';
  priceLookupLoading.value[index] = true;

  try {
    const response = await axios.get(route('product.price-lookup'), {
      params: { query },
    });
    const data = response?.data || {};
    priceLookupMeta.value[index] = {
      query: data.query,
      provider: data.provider,
      provider_ready: data.provider_ready,
      preferred_suppliers: data.preferred_suppliers || [],
    };
    const sources = Array.isArray(data.sources) ? data.sources : [];
    priceLookupResults.value[index] = sources;
    if (!sources.length) {
      priceLookupErrors.value[index] = 'No live prices found.';
      return;
    }
    if (applyBest) {
      applySourceToLine(index, sources[0]);
    }
  } catch (error) {
    priceLookupErrors.value[index] = error?.response?.data?.message || 'Price lookup failed.';
  } finally {
    priceLookupLoading.value[index] = false;
  }
};

const scanAllPrices = async () => {
  if (props.readOnly || !props.enablePriceLookup || bulkLookupLoading.value) {
    return;
  }
  bulkLookupLoading.value = true;
  for (let index = 0; index < products.value.length; index += 1) {
    const line = products.value[index];
    if (!line?.name || line?.id || Number(line.price) > 0 || searchResults.value[index]?.length) {
      continue;
    }
    await searchPrices(index, true);
  }
  bulkLookupLoading.value = false;
};

const handleItemTypeChange = (index) => {
  if (props.readOnly) {
    return;
  }
  const line = products.value[index];
  if (!line) {
    return;
  }
  if (line.id) {
    line.id = null;
  }
  line.source_details = null;
  searchResults.value[index] = [];
  if (line.name?.trim()) {
    searchProducts(line.name, index);
  }
};
</script>

<template>
  <div class="space-y-3 flex flex-col bg-white  rounded-sm shadow-sm xl:shadow-none dark:bg-neutral-900 dark:border-neutral-700">
    <!-- Table Section -->
    <div class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
      <div class="min-w-full inline-block align-middle min-h-[300px]">
        <!-- Table -->
        <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
          <thead>
            <tr>
              <th scope="col" class="min-w-[450px]">
                <div class="pe-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
                  {{ lineItemLabel }}
                </div>
              </th>
              <th scope="col">
                <div class="px-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
                  Qte
                </div>
              </th>
              <th scope="col">
                <div class="px-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
                  Prix unitaire
                </div>
              </th>
              <th scope="col">
                <div class="px-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
                  Total
                </div>
              </th>
              <th scope="col" class="size-px">
                <div class="px-4 py-3 text-start flex items-center gap-x-1 text-sm font-medium text-stone-800 dark:text-neutral-200">
                  Actions
                </div>
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
            <template v-for="(product, index) in products" :key="index">
            <tr>
              <td class="size-px whitespace-nowrap px-4 py-3">
                <div class="relative">
                  <div v-if="allowMixed" class="mb-2">
                    <select
                      v-model="products[index].item_type"
                      :disabled="readOnly"
                      class="w-full rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs text-stone-700 focus:border-green-600 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                      @change="handleItemTypeChange(index)"
                    >
                      <option v-for="option in itemTypeOptions" :key="option.id" :value="option.id">
                        {{ option.name }}
                      </option>
                    </select>
                  </div>
                  <FloatingInput autofocus v-model="products[index].name" label="Nom" :disabled="readOnly"
                    @input="searchProducts(products[index].name, index)" />
                </div>
                <div class="relative w-full">
                  <ul v-if="searchResults[index]?.length"
                      class="absolute left-0 top-full z-50 w-full max-h-60 overflow-y-auto bg-white border border-stone-200 rounded-sm shadow-lg dark:bg-neutral-800 dark:border-neutral-700">
                    <li v-for="result in searchResults[index]" :key="result.id"
                        @click="selectProduct(result, index)"
                        class="px-3 py-2 cursor-pointer hover:bg-stone-100 dark:hover:bg-neutral-700 text-stone-800 dark:text-neutral-200">
                      <div class="flex items-center justify-between gap-2">
                        <span>{{ result.name }}</span>
                        <span v-if="allowMixed" class="text-[10px] uppercase text-stone-400 dark:text-neutral-500">
                          {{ result.item_type === 'service' ? 'Service' : 'Produit' }}
                        </span>
                      </div>
                    </li>
                  </ul>
                </div>
              </td>
              <td class="size-px whitespace-nowrap px-4 py-3">
                <FloatingNumberMiniInput v-model="products[index].quantity" label="Quantite" :disabled="readOnly" />
              </td>
              <td class="size-px whitespace-nowrap px-4 py-3">
                <FloatingNumberMiniInput v-model="products[index].price" aria-disabled="true" label="Prix unitaire" :step="0.01" :disabled="readOnly" />
              </td>
              <td class="size-px whitespace-nowrap px-4 py-3">
                <FloatingNumberMiniInput v-model="products[index].total" label="Total" :step="0.01" :disabled="readOnly" />
              </td>
              <td>
                <div class="flex items-center gap-2">
                  <button
                    v-if="enablePriceLookup && !readOnly && !products[index].id && !searchResults[index]?.length"
                    type="button"
                    class="inline-flex items-center rounded-sm border border-stone-200 px-2 py-1 text-xs font-medium text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    @click="searchPrices(index, false)"
                  >
                    Find prices
                  </button>
                  <button type="button" v-if="!readOnly && products.length > 1" @click="removeLine(index)"
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
                </div>
              </td>
            </tr>
            <tr
              v-if="enablePriceLookup && (priceLookupLoading[index] || priceLookupErrors[index] || priceLookupResults[index]?.length)"
              class="bg-stone-50/60 dark:bg-neutral-800/40"
            >
              <td colspan="5" class="px-4 py-3">
                <div class="space-y-2 text-xs text-stone-600 dark:text-neutral-300">
                  <div v-if="priceLookupMeta[index]?.provider" class="text-[11px] text-stone-500 dark:text-neutral-400">
                    Provider: <span class="font-semibold">{{ priceLookupMeta[index].provider }}</span>
                  </div>
                  <div v-if="priceLookupLoading[index]" class="text-[11px] text-stone-500 dark:text-neutral-400">
                    Searching suppliers...
                  </div>
                  <div v-if="priceLookupErrors[index]" class="text-[11px] text-rose-600 dark:text-rose-300">
                    {{ priceLookupErrors[index] }}
                  </div>
                  <div v-if="priceLookupResults[index]?.length" class="space-y-2">
                    <div
                      v-for="(source, sourceIndex) in priceLookupResults[index]"
                      :key="source.url || source.name"
                      class="flex flex-col gap-2 rounded-sm border border-stone-200 bg-white p-2 dark:border-neutral-700 dark:bg-neutral-900"
                    >
                      <div class="flex items-start justify-between gap-2">
                        <div class="flex items-start gap-2">
                          <img
                            v-if="source.image_url"
                            :src="source.image_url"
                            :alt="source.title || source.name"
                            class="h-8 w-8 rounded-sm border border-stone-200 object-cover dark:border-neutral-700"
                          />
                          <div>
                            <div class="font-semibold text-stone-700 dark:text-neutral-200">
                              {{ source.name }}
                              <span v-if="sourceIndex === 0" class="ml-2 text-[10px] text-emerald-700 dark:text-emerald-300">
                                Best price
                              </span>
                            </div>
                            <div v-if="source.title" class="mt-1 text-[10px] text-stone-400 dark:text-neutral-500">
                              {{ source.title }}
                            </div>
                          </div>
                        </div>
                        <div class="text-[11px] font-semibold text-stone-700 dark:text-neutral-200">
                          ${{ Number(source.price || 0).toFixed(2) }}
                        </div>
                      </div>
                      <div class="flex flex-wrap items-center gap-2">
                        <a
                          v-if="source.url"
                          :href="source.url"
                          target="_blank"
                          rel="noopener"
                          class="text-[11px] text-green-700 hover:underline dark:text-green-400"
                        >
                          Open link
                        </a>
                        <button
                          type="button"
                          class="rounded-sm border border-stone-200 px-2 py-1 text-[10px] font-semibold text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                          @click="applySourceToLine(index, source)"
                        >
                          Use price
                        </button>
                      </div>
                    </div>
                    <button
                      type="button"
                      class="inline-flex items-center rounded-sm border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-100 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-200 dark:hover:bg-emerald-500/20"
                      @click="applySourceToLine(index, priceLookupResults[index][0])"
                    >
                      Apply best price
                    </button>
                  </div>
                </div>
              </td>
            </tr>
            </template>
          </tbody>
        </table>
        <!-- End Table -->
      </div>
    </div>
    <!-- End Table Section -->
    <div class="text-xs text-stone-600 flex justify-between mt-5">
      <div class="flex items-center gap-2">
        <button v-if="!readOnly" type="button" @click="addNewLine"
            class="hs-tooltip-toggle ml-4 py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500">
            Ajouter une ligne de {{ lineItemLabel.toLowerCase() }}
        </button>
        <button
          v-if="enablePriceLookup && !readOnly"
          type="button"
          class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 disabled:opacity-50 disabled:pointer-events-none dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-200 dark:hover:bg-emerald-500/20"
          :disabled="bulkLookupLoading"
          @click="scanAllPrices"
        >
          Scan prices
        </button>
      </div>
    </div>
  </div>
</template>
