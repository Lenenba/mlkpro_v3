<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    campaign: { type: Object, required: true },
    eventStats: { type: Object, default: () => ({}) },
    clickNoConversion: { type: Array, default: () => [] },
    access: { type: Object, default: () => ({}) },
});

const canManage = computed(() => Boolean(props.access?.can_manage));
const runs = computed(() => props.campaign?.runs || []);
const events = computed(() =>
    Object.entries(props.eventStats || {}).map(([key, value]) => ({ key, value: Number(value || 0) }))
);

const conversionError = ref('');
const conversionBusy = ref(false);

const badgeClass = (status) => {
    if (status === 'running') return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300';
    if (status === 'scheduled') return 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300';
    if (status === 'completed') return 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300';
    if (status === 'failed' || status === 'canceled') return 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300';
    return 'bg-stone-100 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
};

const markConverted = async (recipient) => {
    if (!canManage.value || conversionBusy.value) return;

    const conversionType = prompt('Type de conversion', 'sale');
    if (!conversionType) return;
    const conversionIdRaw = prompt('ID de conversion');
    if (!conversionIdRaw) return;
    const conversionId = Number(conversionIdRaw);
    if (!Number.isInteger(conversionId) || conversionId <= 0) return;

    conversionBusy.value = true;
    conversionError.value = '';
    try {
        await axios.post(route('campaigns.conversions.store', props.campaign.id), {
            campaign_recipient_id: recipient.id,
            customer_id: recipient.customer_id,
            conversion_type: conversionType,
            conversion_id: conversionId,
        });
        router.reload({
            only: ['campaign', 'eventStats', 'clickNoConversion'],
            preserveScroll: true,
        });
    } catch (error) {
        conversionError.value = error?.response?.data?.message || error?.message || 'Erreur conversion.';
    } finally {
        conversionBusy.value = false;
    }
};
</script>

<template>
    <Head :title="`Campagne #${campaign.id}`" />
    <AuthenticatedLayout>
        <div class="space-y-4">
            <section class="rounded-sm border border-stone-200 border-t-4 border-t-green-600 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">{{ campaign.name }}</h1>
                        <p class="text-sm text-stone-500 dark:text-neutral-400">
                            Type: {{ campaign.campaign_type || campaign.type }} | Mise à jour: {{ humanizeDate(campaign.updated_at) || '-' }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link :href="route('campaigns.index')" class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700">Retour</Link>
                        <Link v-if="canManage" :href="route('campaigns.edit', campaign.id)" class="rounded-sm border border-transparent bg-green-600 px-3 py-2 text-xs font-semibold text-white hover:bg-green-700">Modifier</Link>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">Statut</div>
                        <div class="mt-1">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="badgeClass(campaign.status)">{{ campaign.status }}</span>
                        </div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">Type planning</div>
                        <div class="mt-1 text-sm font-semibold text-stone-700 dark:text-neutral-200">{{ campaign.schedule_type || '-' }}</div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">Canaux actifs</div>
                        <div class="mt-1 text-sm font-semibold text-stone-700 dark:text-neutral-200">{{ (campaign.channels || []).filter((c) => c.is_enabled).length }}</div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">Offres</div>
                        <div class="mt-1 text-sm font-semibold text-stone-700 dark:text-neutral-200">{{ (campaign.offers || campaign.products || []).length }}</div>
                    </div>
                    <div class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="text-xs text-stone-500 dark:text-neutral-400">Runs</div>
                        <div class="mt-1 text-sm font-semibold text-stone-700 dark:text-neutral-200">{{ runs.length }}</div>
                    </div>
                </div>
            </section>

            <section class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Canaux</h2>
                    <div class="mt-3 space-y-2">
                        <div v-for="channel in campaign.channels || []" :key="`channel-${channel.id || channel.channel}`" class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="flex items-center justify-between">
                                <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">{{ channel.channel }}</div>
                                <span class="text-xs" :class="channel.is_enabled ? 'text-emerald-700 dark:text-emerald-300' : 'text-stone-500 dark:text-neutral-400'">
                                    {{ channel.is_enabled ? 'Actif' : 'Inactif' }}
                                </span>
                            </div>
                            <div v-if="channel.subject_template" class="mt-2 text-xs text-stone-600 dark:text-neutral-300">Sujet: {{ channel.subject_template }}</div>
                            <div v-if="channel.title_template" class="mt-1 text-xs text-stone-600 dark:text-neutral-300">Titre: {{ channel.title_template }}</div>
                            <div v-if="channel.body_template" class="mt-1 whitespace-pre-wrap text-xs text-stone-600 dark:text-neutral-300">{{ channel.body_template }}</div>
                        </div>
                    </div>
                </div>

                <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Événements</h2>
                    <div class="mt-3 space-y-2">
                        <div v-if="events.length === 0" class="rounded-sm border border-dashed border-stone-200 px-3 py-6 text-center text-xs text-stone-500 dark:border-neutral-700 dark:text-neutral-400">
                            Aucun événement pour le moment.
                        </div>
                        <div v-for="event in events" :key="`event-${event.key}`" class="flex items-center justify-between rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800">
                            <span class="text-stone-700 dark:text-neutral-200">{{ event.key }}</span>
                            <span class="font-semibold text-stone-700 dark:text-neutral-200">{{ event.value }}</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Runs récents</h2>
                </div>
                <div class="mt-3 space-y-2">
                    <div v-if="runs.length === 0" class="rounded-sm border border-dashed border-stone-200 px-3 py-6 text-center text-xs text-stone-500 dark:border-neutral-700 dark:text-neutral-400">
                        Aucun run.
                    </div>
                    <div v-for="run in runs" :key="`run-${run.id}`" class="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="space-x-2">
                            <span class="font-semibold text-stone-700 dark:text-neutral-200">#{{ run.id }}</span>
                            <span class="text-stone-600 dark:text-neutral-300">{{ run.trigger_type }}</span>
                            <span class="inline-flex rounded-full px-2 py-0.5 font-semibold" :class="badgeClass(run.status)">{{ run.status }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-stone-500 dark:text-neutral-400">{{ humanizeDate(run.created_at) || '-' }}</span>
                            <Link :href="route('campaign-runs.export', run.id)" class="rounded-sm border border-stone-200 bg-white px-2 py-1 font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-700">
                                Export CSV
                            </Link>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Clicks sans conversion</h2>
                <p v-if="conversionError" class="mt-2 text-xs text-rose-600">{{ conversionError }}</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-sm dark:divide-neutral-700">
                        <thead>
                            <tr class="text-left text-xs uppercase text-stone-500 dark:text-neutral-400">
                                <th class="px-3 py-2">Client</th>
                                <th class="px-3 py-2">Canal</th>
                                <th class="px-3 py-2">Destination</th>
                                <th class="px-3 py-2">Clicked</th>
                                <th class="px-3 py-2 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                            <tr v-if="clickNoConversion.length === 0">
                                <td colspan="5" class="px-3 py-6 text-center text-xs text-stone-500 dark:text-neutral-400">Aucun clic en attente de conversion.</td>
                            </tr>
                            <tr v-for="row in clickNoConversion" :key="`click-${row.id}`">
                                <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ row.customer?.company_name || `${row.customer?.first_name || ''} ${row.customer?.last_name || ''}`.trim() || '-' }}</td>
                                <td class="px-3 py-2 text-stone-700 dark:text-neutral-200">{{ row.channel }}</td>
                                <td class="px-3 py-2 text-stone-600 dark:text-neutral-300">{{ row.destination || '-' }}</td>
                                <td class="px-3 py-2 text-stone-600 dark:text-neutral-300">{{ humanizeDate(row.clicked_at) || '-' }}</td>
                                <td class="px-3 py-2 text-right">
                                    <button v-if="canManage" type="button" :disabled="conversionBusy" @click="markConverted(row)" class="rounded-sm border border-transparent bg-green-600 px-2 py-1 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60">
                                        Marquer conversion
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
