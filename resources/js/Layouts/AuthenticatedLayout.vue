<script setup>
import { ref, computed } from 'vue';
import Header from '@/Layouts/UI/Header.vue'; ``
import Sidebar from '@/Layouts/UI/Sidebar.vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    user: Object,
});

const page = usePage()

const flashSuccess = computed(
    () => page.props.flash.success
)
const flashWarning = computed(
    () => page.props.flash.warning
)
const flashError = computed(
    () => page.props.flash.error
)
</script>

<template>
    <!-- ========== HEADER ========== -->
    <Header />
    <!-- ========== END HEADER ========== -->

    <!-- ========== MAIN SIDEBAR ========== -->
    <Sidebar />
    <!-- ========== END MAIN SIDEBAR ========== -->

    <!-- ========== MAIN CONTENT ========== -->
    <main id="content" class="lg:ps-16 pt-[59px] lg:pt-0">
        <div class="p-2 sm:p-5 sm:py-0 md:pt-5 space-y-5">
            <slot />
        </div>
    </main>
    <!-- ========== END MAIN CONTENT ========== -->
</template>
