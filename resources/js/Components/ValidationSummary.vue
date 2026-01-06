<script setup>
import { computed } from 'vue';

const props = defineProps({
    errors: {
        type: [Array, Object],
        default: () => ({}),
    },
    title: {
        type: String,
        default: 'Please review the highlighted fields.',
    },
    fallbackMessage: {
        type: String,
        default: 'Something went wrong. Please review the form and try again.',
    },
});

const isTechnicalMessage = (message) => {
    if (!message) {
        return false;
    }
    return /(sqlstate|integrity constraint|syntax error|pdoexception|database error)/i.test(message);
};

const rawMessages = computed(() => {
    if (Array.isArray(props.errors)) {
        return props.errors.map((item) => String(item)).filter(Boolean);
    }

    if (!props.errors || typeof props.errors !== 'object') {
        return [];
    }

    return Object.values(props.errors).flatMap((value) => {
        if (Array.isArray(value)) {
            return value.map((entry) => String(entry)).filter(Boolean);
        }
        if (value) {
            return [String(value)];
        }
        return [];
    });
});

const safeMessages = computed(() => rawMessages.value.filter((message) => !isTechnicalMessage(message)));

const displayMessages = computed(() => {
    if (safeMessages.value.length > 0) {
        return Array.from(new Set(safeMessages.value));
    }
    if (rawMessages.value.length > 0) {
        return [props.fallbackMessage];
    }
    return [];
});

const hasErrors = computed(() => displayMessages.value.length > 0);
</script>

<template>
    <div v-if="hasErrors" class="rounded-sm border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">
        <div class="font-semibold">{{ title }}</div>
        <ul class="mt-2 list-disc list-inside space-y-1">
            <li v-for="(message, index) in displayMessages" :key="index">
                {{ message }}
            </li>
        </ul>
    </div>
</template>
