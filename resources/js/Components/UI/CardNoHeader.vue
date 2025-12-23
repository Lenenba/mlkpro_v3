<template>
    <div
        class="flex flex-col bg-white border shadow-sm rounded-sm dark:bg-neutral-900 dark:border-neutral-700 dark:shadow-neutral-700/70">
        <div class="border-b py-3 px-4 md:py-4 md:px-5 dark:border-neutral-700">
            <p class="mt-1 text-sm text-gray-800 dark:text-neutral-200">
                <slot name="title" />
            </p>
        </div>
        <div class="p-4 md:p-5">
            <slot />
        </div>
    </div>
</template>
