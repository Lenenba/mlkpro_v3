<script setup>
const props = defineProps({
    providerKey: {
        type: String,
        default: '',
    },
    sizeClass: {
        type: String,
        default: 'size-10',
    },
});
</script>

<template>
    <div
        class="inline-flex items-center justify-center rounded-2xl border border-white/70 bg-gradient-to-br shadow-sm"
        :class="[
            sizeClass,
            providerKey === 'apollo' ? 'from-sky-500 via-cyan-500 to-emerald-400 text-white' : '',
            providerKey === 'lusha' ? 'from-rose-500 via-orange-500 to-amber-300 text-white' : '',
            providerKey === 'uplead' ? 'from-slate-900 via-slate-700 to-slate-500 text-white' : '',
            !['apollo', 'lusha', 'uplead'].includes(providerKey) ? 'from-stone-300 to-stone-500 text-white' : '',
        ]"
    >
        <svg
            v-if="providerKey === 'apollo'"
            class="size-6"
            viewBox="0 0 24 24"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
        >
            <path d="M6 14.5L12 4l6 10.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M8.5 14.5h7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" />
            <path d="M12 14.5v5.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" />
        </svg>

        <svg
            v-else-if="providerKey === 'lusha'"
            class="size-6"
            viewBox="0 0 24 24"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
        >
            <path d="M7 5v9a5 5 0 0 0 10 0V5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M7 18h10" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" />
        </svg>

        <svg
            v-else-if="providerKey === 'uplead'"
            class="size-6"
            viewBox="0 0 24 24"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
        >
            <path d="M6 16V8l6 4 6-4v8" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M12 12v7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" />
        </svg>

        <span v-else class="text-sm font-semibold uppercase">
            {{ providerKey.slice(0, 1) || '?' }}
        </span>
    </div>
</template>
