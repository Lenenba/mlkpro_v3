<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import Header from './UI/Header.vue';
import Card from '@/Components/UI/Card.vue';
import ListGroup from '@/Components/UI/ListGroup.vue';
import { useMapToItem } from '@/Composables/useMapToItem';
import CardNoHeader from '@/Components/UI/CardNoHeader.vue';
import DescriptionList from '@/Components/UI/DescriptionList.vue';
import CardNav from '@/Components/UI/CardNav.vue';
import TabEmptyState from '@/Components/UI/TabEmptyState.vue';

const props = defineProps({
    customer: Object,
});

// Utiliser le composable pour mapper les données
const { mapToItem } = useMapToItem();
const mappedItems = props.customer.properties.map(mapToItem);

</script>
<template>

    <Head title="Customers" />
    <AuthenticatedLayout>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
            <div class="md:col-span-2">
                <Header :customer="customer" />
                <Card class="mt-5">
                    <template #title>
                        Properties
                    </template>
                    <ListGroup :items="mappedItems">
                        <template #logo>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" class="lucide lucide-house">
                                <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" />
                                <path
                                    d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                            </svg>
                        </template>
                    </ListGroup>
                </Card>
                <CardNav class="mt-5" :customer="customer"/>
                <card class="mt-5">
                    <template #title>
                        Schedule
                    </template>
                </card>
                <card class="mt-5">
                    <template #title>
                        Recent activity for this client
                    </template>
                </card>
            </div>
            <div>
                <CardNoHeader>
                    <template #title>
                        Contact information
                    </template>
                    <DescriptionList :item="customer" />
                </CardNoHeader>
                <CardNoHeader>
                    <template #title>
                        Tags
                    </template>
                </CardNoHeader>
                <CardNoHeader>
                    <template #title>
                        Last client interaction
                    </template>
                </CardNoHeader>
                <card class="mt-5">
                    <template #title>
                        Billing history
                    </template>
                </card>
                <card class="mt-5">
                    <template #title>
                        Internal notes
                    </template>
                </card>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
