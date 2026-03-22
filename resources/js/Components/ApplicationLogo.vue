<script setup>
import { computed, useAttrs } from 'vue';

defineOptions({ inheritAttrs: false });

const attrs = useAttrs();
const logoClass = computed(() => ['inline-flex items-center justify-center h-10 w-32', attrs.class]);
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
            class="block h-full w-full object-contain dark:hidden"
        />
        <img
            src="/1.svg"
            alt="Malikia pro logo"
            class="hidden h-full w-full object-contain dark:block"
        />
    </span>
</template>
