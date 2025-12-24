<script setup>
import { onMounted, onUnmounted } from 'vue';

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    id: {
        type: String,
        required: true,
    },
});
const emit = defineEmits(['close']);
const close = () => {
    if (props.closeable) {
        emit('close');
    }
};

const closeOnEscape = (e) => {
    if (e.key === 'Escape') {
        e.preventDefault();

        if (props.show) {
            close();
        }
    }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));

onUnmounted(() => {
    document.removeEventListener('keydown', closeOnEscape);

    document.body.style.overflow = '';
});

</script>

<template>
    <!-- Add Project Modal -->
    <div :id="id"
        class="hs-overlay hidden size-full fixed top-0 start-0 z-[80] overflow-x-hidden overflow-y-auto [--close-when-click-inside:true] pointer-events-none"
        role="dialog" tabindex="-1" aria-labelledby="hs-pro-dasadpm-label">
        <div
            class="hs-overlay-open:mt-7 hs-overlay-open:opacity-100 hs-overlay-open:duration-500 mt-0 opacity-0 ease-out transition-all sm:max-w-7xl sm:w-full m-3 sm:mx-auto h-[calc(100%-3.5rem)] min-h-[calc(100%-3.5rem)] flex items-center">
            <div
                class="w-full max-h-full flex flex-col bg-white rounded-sm pointer-events-auto shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900">
                <!-- Header -->
                <div class="py-2.5 px-4 flex justify-between items-center border-b border-stone-200 dark:border-neutral-700">
                    <h3 id="hs-pro-dasadpm-label" class="font-medium text-stone-700 dark:text-neutral-200">
                        {{ title }}
                    </h3>
                    <button type="button"
                        class="size-8 inline-flex justify-center items-center gap-x-2 rounded-sm border border-transparent bg-stone-100 text-stone-700 hover:bg-stone-200 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-200 dark:bg-neutral-900 dark:hover:bg-neutral-700 dark:text-neutral-300 dark:focus:bg-neutral-700"
                        aria-label="Close" :data-hs-overlay="'#'+id">
                        <span class="sr-only">Close</span>
                        <svg class="shrink-0 size-4" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg>
                    </button>
                </div>
                <!-- End Header -->
                <div class="p-4">
                    <slot />
                </div>
            </div>
        </div>
    </div>
    <!-- End Add Project Modal -->
</template>
