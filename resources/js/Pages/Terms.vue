<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: {
        type: Boolean,
        default: true,
    },
    canRegister: {
        type: Boolean,
        default: true,
    },
});
</script>

<template>
    <Head title="Terms and Conditions" />

    <div class="min-h-screen bg-stone-50 text-stone-900 dark:bg-neutral-900 dark:text-neutral-100">
        <header class="mx-auto flex w-full max-w-6xl items-center justify-between px-4 py-6">
            <Link :href="route('welcome')" class="flex items-center gap-3">
                <ApplicationLogo class="h-10 w-10" />
                <div class="leading-tight">
                    <div class="text-sm font-semibold">MLK Pro</div>
                    <div class="text-xs text-stone-500 dark:text-neutral-400">Business management suite</div>
                </div>
            </Link>

            <div class="flex items-center gap-2">
                <Link v-if="canLogin" :href="route('login')"
                    class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-stone-800 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
                    Sign in
                </Link>
                <Link v-if="canRegister" :href="route('register')"
                    class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">
                    Create account
                </Link>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl px-4 pb-16 pt-8">
            <section
                class="rounded-sm border border-stone-200 bg-white p-6 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                <div class="space-y-6">
                    <div class="space-y-2">
                        <div class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-400">
                            Terms and Conditions
                        </div>
                        <h1 class="text-2xl font-semibold tracking-tight text-stone-900 dark:text-neutral-100">
                            Terms of Use for MLK Pro
                        </h1>
                        <p class="text-sm text-stone-600 dark:text-neutral-400">
                            Last update: 2025-01-01. This page is a template and should be reviewed before publication.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div class="space-y-6">
                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">1. Scope</h2>
                                <p class="text-sm text-stone-600 dark:text-neutral-300">
                                    These terms govern access to MLK Pro, a platform that helps businesses manage
                                    customers, quotes, jobs, tasks, invoices, products, and plan scans.
                                </p>
                            </div>

                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">2. Accounts</h2>
                                <ul class="space-y-2 text-sm text-stone-600 dark:text-neutral-300">
                                    <li>Accounts are created and managed by a business owner or admin.</li>
                                    <li>You are responsible for keeping credentials secure.</li>
                                    <li>Roles and permissions limit access to data and actions.</li>
                                </ul>
                            </div>

                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">3. Data ownership</h2>
                                <p class="text-sm text-stone-600 dark:text-neutral-300">
                                    Your company retains ownership of its data. By using MLK Pro, you grant the
                                    platform permission to store, process, and display your data as needed to provide
                                    the service.
                                </p>
                            </div>

                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">4. Quotes and pricing data</h2>
                                <ul class="space-y-2 text-sm text-stone-600 dark:text-neutral-300">
                                    <li>Quotes are estimates and may vary based on site conditions and scope changes.</li>
                                    <li>Price lookups use third-party sources; accuracy is not guaranteed.</li>
                                    <li>You must validate supplier pricing before accepting or sending a quote.</li>
                                </ul>
                            </div>
                        </div>

                        <div class="space-y-6">
                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">5. Billing and payments</h2>
                                <p class="text-sm text-stone-600 dark:text-neutral-300">
                                    Subscription fees, taxes, and payment terms are displayed in your billing settings.
                                    Unpaid invoices may result in limited access until resolved.
                                </p>
                            </div>

                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">6. Acceptable use</h2>
                                <ul class="space-y-2 text-sm text-stone-600 dark:text-neutral-300">
                                    <li>Do not upload unlawful content or misuse customer data.</li>
                                    <li>Do not attempt to disrupt or reverse engineer the service.</li>
                                    <li>Comply with applicable laws and client agreements.</li>
                                </ul>
                            </div>

                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">7. Availability</h2>
                                <p class="text-sm text-stone-600 dark:text-neutral-300">
                                    The platform is provided on an "as is" basis. We may perform maintenance or updates
                                    that temporarily impact availability.
                                </p>
                            </div>

                            <div class="space-y-2">
                                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">8. Limitation of liability</h2>
                                <p class="text-sm text-stone-600 dark:text-neutral-300">
                                    MLK Pro is not liable for indirect damages, lost profits, or data loss beyond the
                                    limits required by law. Use the service at your own risk.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div class="space-y-2">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">9. Termination</h2>
                            <p class="text-sm text-stone-600 dark:text-neutral-300">
                                You can cancel your subscription at any time. We may suspend access for violations of
                                these terms or non-payment.
                            </p>
                        </div>

                        <div class="space-y-2">
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">10. Changes</h2>
                            <p class="text-sm text-stone-600 dark:text-neutral-300">
                                We may update these terms. Continued use after changes means you accept the updated
                                terms.
                            </p>
                        </div>
                    </div>

                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-4 text-sm text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        Questions? Contact your account owner or use the in-app support channel.
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-stone-200 py-8 text-center text-xs text-stone-500 dark:border-neutral-800 dark:text-neutral-400">
            © {{ new Date().getFullYear() }} MLK Pro
        </footer>
    </div>
</template>
