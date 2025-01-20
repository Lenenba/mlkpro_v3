<script setup>
import { Link } from '@inertiajs/vue3';
const props = defineProps({
    type: String,
    customer: Object,
});
</script>
<template>
    <!-- Empty State -->
    <div class="p-5 min-h-[100px]  flex flex-col justify-center items-center text-center">
        <svg class="w-48 mx-auto mb-4" width="178" height="90" viewBox="0 0 178 90" fill="none"
            xmlns="http://www.w3.org/2000/svg">
            <rect x="27" y="50.5" width="124" height="39" rx="7.5" fill="currentColor"
                class="fill-white dark:fill-neutral-800" />
            <rect x="27" y="50.5" width="124" height="39" rx="7.5" stroke="currentColor"
                class="stroke-gray-50 dark:stroke-neutral-700/10" />
            <rect x="34.5" y="58" width="24" height="24" rx="4" fill="currentColor"
                class="fill-gray-50 dark:fill-neutral-700/30" />
            <rect x="66.5" y="61" width="60" height="6" rx="3" fill="currentColor"
                class="fill-gray-50 dark:fill-neutral-700/30" />
            <rect x="66.5" y="73" width="77" height="6" rx="3" fill="currentColor"
                class="fill-gray-50 dark:fill-neutral-700/30" />
            <rect x="19.5" y="28.5" width="139" height="39" rx="7.5" fill="currentColor"
                class="fill-white dark:fill-neutral-800" />
            <rect x="19.5" y="28.5" width="139" height="39" rx="7.5" stroke="currentColor"
                class="stroke-gray-100 dark:stroke-neutral-700/30" />
            <rect x="27" y="36" width="24" height="24" rx="4" fill="currentColor"
                class="fill-gray-100 dark:fill-neutral-700/70" />
            <rect x="59" y="39" width="60" height="6" rx="3" fill="currentColor"
                class="fill-gray-100 dark:fill-neutral-700/70" />
            <rect x="59" y="51" width="92" height="6" rx="3" fill="currentColor"
                class="fill-gray-100 dark:fill-neutral-700/70" />
            <g filter="url(#filter1)">
                <rect x="12" y="6" width="154" height="40" rx="8" fill="currentColor"
                    class="fill-white dark:fill-neutral-800" shape-rendering="crispEdges" />
                <rect x="12.5" y="6.5" width="153" height="39" rx="7.5" stroke="currentColor"
                    class="stroke-gray-100 dark:stroke-neutral-700/60" shape-rendering="crispEdges" />
                <rect x="20" y="14" width="24" height="24" rx="4" fill="currentColor"
                    class="fill-gray-200 dark:fill-neutral-700" />
                <rect x="52" y="17" width="60" height="6" rx="3" fill="currentColor"
                    class="fill-gray-200 dark:fill-neutral-700" />
                <rect x="52" y="29" width="106" height="6" rx="3" fill="currentColor"
                    class="fill-gray-200 dark:fill-neutral-700" />
            </g>
            <defs>
                <filter id="filter1" x="0" y="0" width="178" height="64" filterUnits="userSpaceOnUse"
                    color-interpolation-filters="sRGB">
                    <feFlood flood-opacity="0" result="BackgroundImageFix" />
                    <feColorMatrix in="SourceAlpha" type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
                        result="hardAlpha" />
                    <feOffset dy="6" />
                    <feGaussianBlur stdDeviation="6" />
                    <feComposite in2="hardAlpha" operator="out" />
                    <feColorMatrix type="matrix" values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0.03 0" />
                    <feBlend mode="normal" in2="BackgroundImageFix" result="effect1_dropShadow_1187_14810" />
                    <feBlend mode="normal" in="SourceGraphic" in2="effect1_dropShadow_1187_14810" result="shape" />
                </filter>
            </defs>
        </svg>

        <div class="max-w-sm mx-auto">
            <p class="mt-2 font-medium text-gray-800 dark:text-neutral-200">
                No {{ type }} data
            </p>
            <p class="mb-5 text-sm text-gray-500 dark:text-neutral-500">
                In the meantime, you can create new custom {{ type }} to monitor your most important
                metrics
            </p>
        </div>
        <Link :href="route('customer.quote.create', customer)">
            <button type="button"
                class="py-2 px-3 inline-flex items-center gap-x-2 text-sm font-medium rounded-lg border border-transparent bg-neutral-600 text-white hover:bg-neutral-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-neutral-500"
                data-hs-overlay="#hs-pro-atbetb">
                <svg class="hidden sm:block shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path d="M5 12h14" />
                    <path d="M12 5v14" />
                </svg>Create {{ type }}
            </button>
        </Link>
    </div>
    <!-- End Empty State -->
</template>
