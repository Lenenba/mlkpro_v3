<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const page = usePage();
const demoEnabled = computed(() => Boolean(page.props.demo?.enabled));
</script>

<template>
    <Head title="Try the demo" />
    <GuestLayout :card-class="'mt-6 w-full max-w-3xl rounded-sm border border-stone-200 bg-white px-6 py-6 shadow-md dark:border-neutral-700 dark:bg-neutral-900'">
        <div class="space-y-6">
            <div class="space-y-1">
                <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">Try the demo</h1>
                <p class="text-sm text-stone-600 dark:text-neutral-400">
                    Pick a demo workspace to explore the platform with preloaded data.
                </p>
            </div>

            <div v-if="!demoEnabled" class="rounded-sm border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                Demo mode is currently disabled.
            </div>

            <div v-else class="grid gap-4 md:grid-cols-3">
                <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Service demo</div>
                    <p class="mt-2 text-xs text-stone-600 dark:text-neutral-400">
                        Quotes, jobs, tasks, and invoices for a service-based workflow.
                    </p>
                    <Link
                        :href="route('demo.login', 'service')"
                        method="post"
                        as="button"
                        type="button"
                        class="mt-4 inline-flex w-full justify-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                    >
                        Launch service demo
                    </Link>
                </div>

                <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Product demo</div>
                    <p class="mt-2 text-xs text-stone-600 dark:text-neutral-400">
                        Products, stock, pricing, and sales documents ready to explore.
                    </p>
                    <Link
                        :href="route('demo.login', 'product')"
                        method="post"
                        as="button"
                        type="button"
                        class="mt-4 inline-flex w-full justify-center rounded-sm border border-transparent bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                    >
                        Launch product demo
                    </Link>
                </div>

                <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Guided demo</div>
                    <p class="mt-2 text-xs text-stone-600 dark:text-neutral-400">
                        Follow a step-by-step tour across key pages and features.
                    </p>
                    <Link
                        :href="route('demo.login', 'guided')"
                        method="post"
                        as="button"
                        type="button"
                        class="mt-4 inline-flex w-full justify-center rounded-sm border border-emerald-600 bg-white px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 dark:bg-neutral-900 dark:text-emerald-200"
                    >
                        Launch guided demo
                    </Link>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
