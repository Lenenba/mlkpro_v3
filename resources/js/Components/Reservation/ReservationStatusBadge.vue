<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    reservationStatusBadgeClass,
    reservationStatusDotClasses,
} from '@/Components/Reservation/status';

const props = defineProps({
    status: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        default: '',
    },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md'].includes(value),
    },
    showDot: {
        type: Boolean,
        default: true,
    },
});

const { t } = useI18n();

const normalizedStatus = computed(() => String(props.status || '').trim().toLowerCase());

const humanize = (value) =>
    String(value || '')
        .replaceAll('_', ' ')
        .replace(/\b\p{L}/gu, (letter) => letter.toLocaleUpperCase());

const statusLabel = computed(() => {
    if (props.label.trim()) {
        return props.label.trim();
    }

    const key = `reservations.status.${normalizedStatus.value || 'unknown'}`;
    const translated = t(key);

    return translated === key
        ? humanize(normalizedStatus.value || 'unknown')
        : translated;
});

const sizeClass = computed(() =>
    props.size === 'sm' ? 'gap-1.5 px-2 py-0.5 text-[0.6875rem]' : 'gap-2 px-2.5 py-1 text-xs',
);
</script>

<template>
    <span
        class="inline-flex max-w-full items-center rounded-full font-semibold leading-5"
        :class="[reservationStatusBadgeClass(normalizedStatus), sizeClass]"
        :aria-label="statusLabel"
    >
        <span
            v-if="showDot"
            aria-hidden="true"
            class="h-1.5 w-1.5 shrink-0 rounded-full"
            :class="reservationStatusDotClasses(normalizedStatus)"
        />
        <span class="truncate">{{ statusLabel }}</span>
    </span>
</template>
