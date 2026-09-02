<script setup>
import {
    Award,
    CalendarDays,
    LayoutDashboard,
    PackageCheck,
    ReceiptText,
    ShoppingBag,
} from 'lucide-vue-next';
import LinkAncor from '@/Components/UI/LinkAncor.vue';

defineProps({
    item: {
        type: Object,
        required: true,
    },
    active: {
        type: Boolean,
        default: false,
    },
});

const icons = {
    dashboard: LayoutDashboard,
    calendar: CalendarDays,
    orders: ShoppingBag,
    invoices: ReceiptText,
    packages: PackageCheck,
    loyalty: Award,
};
</script>

<template>
    <LinkAncor
        :label="$t(item.labelKey)"
        :href="item.routeName"
        :tone="item.tone"
        :active="active"
        mobile-expanded
    >
        <template #icon>
            <component :is="icons[item.icon]" class="size-6" aria-hidden="true" />
        </template>
    </LinkAncor>
</template>
