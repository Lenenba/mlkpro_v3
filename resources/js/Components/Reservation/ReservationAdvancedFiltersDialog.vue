<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import DatePicker from '@/Components/DatePicker.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import { crmButtonClass } from '@/utils/crmButtonStyles';
import { countReservationAdvancedFilters, createReservationAdvancedFilters } from '@/utils/reservationFilters';

const props = defineProps({
    show: Boolean,
    filters: { type: Object, default: () => ({}) },
    matchingCount: { type: Number, default: 0 },
    statusOptions: { type: Array, default: () => [] },
    serviceOptions: { type: Array, default: () => [] },
    teamOptions: { type: Array, default: () => [] },
    ownTeamMemberId: { type: String, default: '' },
});
const emit = defineEmits(['close', 'apply']);
const { t } = useI18n();
const draft = ref(createReservationAdvancedFilters(props.filters, props.ownTeamMemberId));
const firstControl = ref(null);
const draftCount = computed(() => countReservationAdvancedFilters({ ...draft.value, scope: props.filters.scope }));

watch(() => props.show, async (show) => {
    if (show) {
        draft.value = createReservationAdvancedFilters(props.filters, props.ownTeamMemberId);
        await nextTick();
        firstControl.value?.focus?.();
    }
});

const reset = () => {
    draft.value = createReservationAdvancedFilters({ scope: props.filters.scope }, props.ownTeamMemberId);
    nextTick(() => firstControl.value?.focus?.());
};
</script>

<template>
    <Modal
        :show="show"
        max-width="5xl"
        position="center"
        full-screen-mobile
        aria-labelledby="reservation-advanced-filters-title"
        aria-describedby="reservation-advanced-filters-description"
        @close="emit('close')"
    >
        <div id="reservation-advanced-filters" class="flex h-dvh flex-col sm:h-auto sm:max-h-[calc(100vh-3rem)]">
            <header class="flex shrink-0 items-start justify-between gap-4 border-b border-stone-200 px-4 py-4 sm:px-6 dark:border-neutral-700">
                <div class="min-w-0">
                    <h2 id="reservation-advanced-filters-title" class="text-base font-semibold text-stone-900 dark:text-neutral-100">
                        {{ t('reservations.advanced_filters.title') }}
                    </h2>
                    <p id="reservation-advanced-filters-description" class="mt-1 break-words text-xs text-stone-500 dark:text-neutral-400">
                        {{ t('reservations.advanced_filters.description') }}
                    </p>
                </div>
                <button
                    type="button"
                    class="inline-flex size-11 shrink-0 items-center justify-center rounded-sm text-stone-500 hover:bg-stone-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-neutral-400 dark:hover:bg-neutral-800"
                    :aria-label="t('reservations.advanced_filters.close')"
                    @click="emit('close')"
                >
                    <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            </header>

            <div class="min-h-0 flex-1 space-y-7 overflow-y-auto px-4 py-5 sm:px-6">
                <fieldset class="space-y-3">
                    <legend class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ t('reservations.title') }}</legend>
                    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                        <FloatingSelect ref="firstControl" v-model="draft.status" :options="statusOptions" :label="t('reservations.filters.status')" />
                        <FloatingSelect v-model="draft.service_id" :options="serviceOptions" :label="t('reservations.form.item')" />
                        <FloatingSelect
                            v-if="teamOptions.length > 1"
                            v-model="draft.team_member_id"
                            :options="teamOptions"
                            :label="t('planning.form.member')"
                            :disabled="filters.scope === 'mine'"
                        />
                    </div>
                </fieldset>
                <fieldset class="space-y-3">
                    <legend class="text-sm font-semibold text-stone-800 dark:text-neutral-100">{{ t('reservations.advanced_filters.period') }}</legend>
                    <div class="grid gap-3 md:grid-cols-2">
                        <DatePicker v-model="draft.date_from" :label="t('reservations.filters.date_from')" />
                        <DatePicker v-model="draft.date_to" :label="t('reservations.filters.date_to')" />
                    </div>
                </fieldset>
            </div>

            <footer class="sticky bottom-0 z-10 flex shrink-0 flex-col gap-3 border-t border-stone-200 bg-white px-4 py-3 shadow-[0_-8px_24px_rgba(0,0,0,0.06)] sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:border-neutral-700 dark:bg-neutral-900 dark:shadow-[0_-8px_24px_rgba(0,0,0,0.22)]">
                <p class="text-xs text-stone-500 dark:text-neutral-400" aria-live="polite">
                    {{ t('reservations.advanced_filters.draft_count', { count: draftCount }) }}
                    · {{ t('reservations.advanced_filters.current_results', { count: matchingCount }) }}
                </p>
                <div class="grid w-full grid-cols-1 gap-2 sm:flex sm:w-auto sm:flex-wrap sm:justify-end">
                    <button type="button" :class="[crmButtonClass('secondary', 'toolbar'), 'min-h-11 whitespace-nowrap']" @click="reset">
                        {{ t('reservations.advanced_filters.reset') }}
                    </button>
                    <button type="button" :class="[crmButtonClass('secondary', 'toolbar'), 'min-h-11 whitespace-nowrap']" @click="emit('close')">
                        {{ t('reservations.actions.cancel') }}
                    </button>
                    <button type="button" :class="[crmButtonClass('primary', 'toolbar'), 'min-h-11 whitespace-nowrap']" @click="emit('apply', { ...draft })">
                        {{ t('reservations.advanced_filters.apply') }}
                    </button>
                </div>
            </footer>
        </div>
    </Modal>
</template>
