<script setup>
import { computed, useAttrs } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    colorScheme: {
        type: String,
        default: 'auto',
        validator: (value) => ['auto', 'light', 'dark'].includes(value),
    },
});

const attrs = useAttrs();
const logoClass = computed(() => ['inline-flex items-center justify-center h-10 w-32', attrs.class]);
const lightLogoClass = computed(() => [
    'h-full w-full object-contain',
    props.colorScheme === 'auto' ? 'block dark:hidden' : props.colorScheme === 'light' ? 'block' : 'hidden',
]);
const darkLogoClass = computed(() => [
    'h-full w-full object-contain',
    props.colorScheme === 'auto' ? 'hidden dark:block' : props.colorScheme === 'dark' ? 'block' : 'hidden',
]);
const passthroughAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;
    return rest;
});
</script>

<template>
    <span v-bind="passthroughAttrs" :class="logoClass">
        <img
            src="/2.svg"
            alt="Malikia pro logo"
            :class="lightLogoClass"
        />
        <img
            src="/1.svg"
            alt="Malikia pro logo"
            :class="darkLogoClass"
        />
    </span>
</template>
