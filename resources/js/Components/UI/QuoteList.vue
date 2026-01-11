<script setup>
import { Link, router } from '@inertiajs/vue3';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    quotes: {
        type: Array,
        default: () => [],
    },
});

const formatDate = (value) => humanizeDate(value);
const formatCurrency = (value) =>
    `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const formatStatus = (status) => (status || 'draft').replace(/_/g, ' ');

const statusPillClass = (status) => {
    switch (status) {
        case 'accepted':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        case 'declined':
            return 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-400';
        case 'sent':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-400';
        default:
            return 'bg-stone-100 text-stone-800 dark:bg-neutral-700 dark:text-neutral-200';
    }
};

const statusAccentClass = (status) => {
    switch (status) {
        case 'accepted':
            return 'border-l-emerald-400';
        case 'declined':
            return 'border-l-rose-400';
        case 'sent':
            return 'border-l-sky-400';
        default:
            return 'border-l-stone-300 dark:border-l-neutral-600';
    }
};

const fallbackPropertyForCustomer = (customer) => {
    const properties = customer?.properties || [];
    return properties.find((property) => property.is_default) || properties[0] || null;
};

const propertyForQuote = (quote) => quote.property || fallbackPropertyForCustomer(quote.customer);

const propertyLabel = (quote) => {
    const property = propertyForQuote(quote);
    if (!property) {
        return null;
    }
    return [property.street1, property.city, property.country].filter(Boolean).join(', ');
};

const deleteQuote = async (quote) => {
    try {
        router.delete(route('customer.quote.destroy', quote), {
            onSuccess: () => console.log('Quote deleted successfully!'),
            onError: (error) => console.error('Error deleting quote:', error),
        });
    } catch (error) {
        console.error('Error deleting quote:', error);
    }
};

const sendEmail = async (quote) => {
    try {
        router.post(route('quote.send.email', quote), {
            onSuccess: () => console.log('Email sent successfully!'),
            onError: (error) => console.error('Error sending email:', error),
        });
    } catch (error) {
        console.error('Error sending email:', error);
    }
};
</script>

<template>
    <div class="space-y-3">
        <div v-for="quote in quotes" :key="quote.id"
            class="rounded-sm border border-stone-200 border-l-4 bg-white p-4 shadow-sm dark:bg-neutral-800 dark:border-neutral-700"
            :class="statusAccentClass(quote.status)">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <Link
                        :href="route('customer.quote.show', quote)"
                        class="text-sm font-semibold text-stone-800 hover:underline dark:text-neutral-200"
                    >
                        {{ quote.number || 'Quote' }}
                    </Link>
                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                        Created {{ formatDate(quote.created_at) }}
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                        <span v-if="quote.job_title">{{ quote.job_title }}</span>
                        <span v-if="propertyLabel(quote)">{{ propertyLabel(quote) }}</span>
                    </div>
                </div>
                <div class="flex flex-col items-end gap-2">
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="statusPillClass(quote.status)">
                        {{ formatStatus(quote.status) }}
                    </span>
                    <span v-if="quote.total !== null && quote.total !== undefined"
                        class="text-sm font-semibold text-stone-800 dark:text-neutral-200">
                        {{ formatCurrency(quote.total) }}
                    </span>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-stone-500 dark:text-neutral-400">
                <div class="flex items-center gap-2 min-w-0">
                    <span v-if="quote.notes" class="truncate max-w-[240px]">{{ quote.notes }}</span>
                    <div v-if="quote.products?.length" class="flex items-center -space-x-2">
                        <img
                            v-for="product in quote.products"
                            :key="product.id"
                            class="size-6 rounded-sm border border-white object-cover dark:border-neutral-900"
                            :src="product.image_url || product.image"
                            alt="Product"
                        >
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <div class="hs-dropdown [--placement:bottom-right] relative inline-flex">
                        <button :id="`quote-actions-${quote.id}`" type="button"
                            class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                            aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                            <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="1" />
                                <circle cx="12" cy="5" r="1" />
                                <circle cx="12" cy="19" r="1" />
                            </svg>
                        </button>

                        <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-40 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                            role="menu" aria-orientation="vertical" :aria-labelledby="`quote-actions-${quote.id}`">
                            <div class="p-1">
                                <Link
                                    :href="route('customer.quote.edit', quote)"
                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-stone-800 hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                                >
                                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                        height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" />
                                        <path d="m15 5 4 4" />
                                    </svg>
                                    Edit
                                </Link>
                                <Link
                                    :href="route('customer.quote.show', quote)"
                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-stone-800 hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        class="lucide lucide-eye shrink-0 size-3.5">
                                        <path
                                            d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                    View
                                </Link>
                                <button type="button" @click="sendEmail(quote)"
                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-stone-800 hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:focus:bg-neutral-800">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round"
                                        class="lucide lucide-send shrink-0 size-3.5">
                                        <path
                                            d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z" />
                                        <path d="m21.854 2.147-10.94 10.939" />
                                    </svg>
                                    Send to client
                                </button>
                                <div class="my-1 border-t border-stone-200 dark:border-neutral-700"></div>
                                <button type="button"
                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] font-normal text-red-600 hover:bg-stone-100 focus:outline-none focus:bg-stone-100 dark:text-red-500 dark:hover:bg-neutral-800 dark:focus:bg-neutral-700"
                                    aria-haspopup="dialog" aria-expanded="false"
                                    :aria-controls="`quote-delete-${quote.id}`"
                                    :data-hs-overlay="`#quote-delete-${quote.id}`">
                                    <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                        height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 6h18" />
                                        <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" />
                                        <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" />
                                        <line x1="10" x2="10" y1="11" y2="17" />
                                        <line x1="14" x2="14" y1="11" y2="17" />
                                    </svg>
                                    Delete quote
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div :id="`quote-delete-${quote.id}`"
                class="hs-overlay hidden size-full fixed top-0 start-0 z-[80] overflow-x-hidden overflow-y-auto [--close-when-click-inside:true] pointer-events-none"
                role="dialog" tabindex="-1" :aria-labelledby="`quote-delete-label-${quote.id}`">
                <div
                    class="hs-overlay-open:mt-7 hs-overlay-open:opacity-100 hs-overlay-open:duration-500 mt-0 opacity-0 ease-out transition-all sm:max-w-md sm:w-full m-3 sm:mx-auto h-[calc(100%-3.5rem)] min-h-[calc(100%-3.5rem)] flex items-center">
                    <div
                        class="relative w-full max-h-full overflow-hidden flex flex-col bg-white rounded-sm pointer-events-auto dark:bg-neutral-800">
                        <div class="absolute top-3 end-3">
                            <button type="button"
                                class="size-8 inline-flex justify-center items-center gap-x-2 rounded-sm border border-transparent bg-stone-100 text-stone-800 hover:bg-stone-200 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-200 dark:bg-neutral-700 dark:hover:bg-neutral-600 dark:text-neutral-400 dark:focus:bg-neutral-600"
                                aria-label="Close" :data-hs-overlay="`#quote-delete-${quote.id}`">
                                <span class="sr-only">Close</span>
                                <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M18 6 6 18" />
                                    <path d="m6 6 12 12" />
                                </svg>
                            </button>
                        </div>

                        <div class="p-5 sm:p-10">
                            <h3 :id="`quote-delete-label-${quote.id}`" class="text-lg font-medium text-stone-800 dark:text-neutral-200">
                                Are you sure you want to delete this quote?
                            </h3>
                            <p class="mt-2 text-sm text-stone-500 dark:text-neutral-500">
                                This action is irreversible. If you want to just temporarily
                                disable this card, you
                                can freeze it in settings.
                            </p>
                        </div>

                        <div class="pb-5 px-5 sm:px-10 flex justify-center items-center gap-x-3">
                            <button type="button"
                                class="py-2.5 px-3 w-full inline-flex justify-center items-center gap-x-1.5 text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                :data-hs-overlay="`#quote-delete-${quote.id}`">
                                Cancel
                            </button>
                            <button @click="deleteQuote(quote)" type="button"
                                class="py-2.5 px-3 w-full inline-flex justify-center items-center gap-x-1.5 text-sm font-medium rounded-sm border border-transparent bg-green-500 text-white hover:bg-green-600 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-green-600"
                                :data-hs-overlay="`#quote-delete-${quote.id}`">
                                Confirm
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

