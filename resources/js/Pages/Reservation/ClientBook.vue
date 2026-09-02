<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { CalendarPlus2 } from 'lucide-vue-next';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ClientPortalTabs from '@/Components/Portal/ClientPortalTabs.vue';
import ClientBookingJourney from '@/Components/Reservation/ClientBookingJourney.vue';

const { t } = useI18n();

const props = defineProps({
    timezone: {
        type: String,
        default: 'UTC',
    },
    teamMembers: {
        type: Array,
        default: () => [],
    },
    services: {
        type: Array,
        default: () => [],
    },
    client: {
        type: Object,
        default: () => ({}),
    },
    upcomingReservations: {
        type: Array,
        default: () => [],
    },
    waitlistEntries: {
        type: Array,
        default: () => [],
    },
    queueTickets: {
        type: Array,
        default: () => [],
    },
    capabilities: {
        type: Object,
        default: () => ({
            view: false,
            manage: false,
        }),
    },
    settings: {
        type: Object,
        default: () => ({}),
    },
});

const serviceTabs = computed(() => ([
    ...(props.capabilities?.view ? [{
        id: 'reservations',
        label: t('reservations.client.index.title'),
        description: t('reservations.client.index.subtitle'),
        href: route('client.reservations.index'),
        badge: props.upcomingReservations.length,
        active: false,
    }] : []),
    {
        id: 'book',
        label: t('reservations.client.book.title'),
        description: t('reservations.client.book.subtitle'),
        href: route('client.reservations.book'),
        badge: props.services.length,
        active: true,
    },
]));
</script>

<template>
    <Head :title="$t('reservations.client.book.title')" />
    <AuthenticatedLayout>
        <div class="w-full min-w-0 max-w-full space-y-4">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-green-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-sm bg-green-100 text-green-700 dark:bg-green-500/10 dark:text-green-300">
                        <CalendarPlus2 class="size-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('reservations.client.book.title') }}
                        </h1>
                        <p class="mt-1 max-w-3xl text-sm text-stone-500 dark:text-neutral-400">
                            {{ $t('reservations.client.book.subtitle') }}
                        </p>
                    </div>
                </div>
            </section>

            <ClientPortalTabs
                :tabs="serviceTabs"
                :aria-label="$t('nav.reservations')"
                :columns="serviceTabs.length"
            />

            <ClientBookingJourney
                :timezone="timezone"
                :team-members="teamMembers"
                :services="services"
                :client="client"
                :capabilities="capabilities"
                :settings="settings"
            />
        </div>
    </AuthenticatedLayout>
</template>
