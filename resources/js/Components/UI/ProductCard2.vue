<template>
    <!-- Products Card -->
    <div
        class="flex flex-col bg-white border border-stone-200 overflow-visible rounded-sm shadow-sm dark:bg-neutral-900 dark:border-neutral-700">
        <!-- Header -->
        <div
            class="py-3 px-5 flex justify-between items-center gap-x-5 border-b border-stone-200 dark:border-neutral-700">
            <h2 class="inline-block font-semibold text-stone-800 dark:text-neutral-200">
                <slot name="title" />
            </h2>
        </div>
        <!-- End Header -->

        <!-- Body -->
        <div class="p-5 space-y-4">
            <slot />
        </div>
        <!-- End Body -->
    </div>
    <!-- End Products Card -->
</template>
