<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    conversation: {
        type: Object,
        required: true,
    },
});

const replyMessage = ref('');
const replyError = ref('');
const replyProcessing = ref(false);
const actionError = ref('');

const pendingActions = computed(() => (props.conversation.actions || [])
    .filter((action) => action.status === 'pending'));

const reloadConversation = () => {
    router.reload({
        only: ['conversation'],
        preserveScroll: true,
    });
};

const submitReply = async () => {
    const message = replyMessage.value.trim();
    if (!message || replyProcessing.value) {
        return;
    }

    replyProcessing.value = true;
    replyError.value = '';

    try {
        await axios.post(route('admin.ai-assistant.conversations.reply', props.conversation.id), {
            message,
        }, {
            headers: {
                Accept: 'application/json',
            },
        });
        replyMessage.value = '';
        reloadConversation();
    } catch (error) {
        replyError.value = error?.response?.data?.message || 'Impossible de sauvegarder la reponse.';
    } finally {
        replyProcessing.value = false;
    }
};

const approveAction = async (action) => {
    actionError.value = '';

    try {
        await axios.post(route('admin.ai-assistant.actions.approve', action.id), {}, {
            headers: {
                Accept: 'application/json',
            },
        });
        reloadConversation();
    } catch (error) {
        actionError.value = error?.response?.data?.message || 'Impossible d approuver cette action.';
    }
};

const rejectAction = async (action) => {
    actionError.value = '';

    try {
        await axios.post(route('admin.ai-assistant.actions.reject', action.id), {}, {
            headers: {
                Accept: 'application/json',
            },
        });
        reloadConversation();
    } catch (error) {
        actionError.value = error?.response?.data?.message || 'Impossible de rejeter cette action.';
    }
};
</script>

<template>
    <Head title="Conversation IA" />

    <AuthenticatedLayout>
        <div class="mx-auto grid w-[1200px] max-w-full gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
            <main class="space-y-4">
                <header class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <Link
                        :href="route('admin.ai-assistant.conversations.index')"
                        class="text-sm font-semibold text-emerald-700 hover:text-emerald-800 dark:text-emerald-300"
                    >
                        Retour inbox
                    </Link>
                    <h1 class="mt-2 text-xl font-semibold text-stone-900 dark:text-neutral-50">
                        {{ conversation.visitor_name || 'Conversation IA' }}
                    </h1>
                    <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                        {{ conversation.channel }} · {{ conversation.status }} · {{ conversation.public_uuid }}
                    </p>
                </header>

                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="space-y-3">
                        <article
                            v-for="message in conversation.messages"
                            :key="message.id"
                            class="rounded-sm border px-3 py-2 text-sm"
                            :class="message.sender_type === 'user'
                                ? 'border-stone-200 bg-stone-50 text-stone-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100'
                                : 'border-emerald-100 bg-emerald-50 text-emerald-950 dark:border-emerald-500/30 dark:bg-emerald-950/30 dark:text-emerald-100'"
                        >
                            <div class="mb-1 text-xs font-semibold uppercase tracking-wide opacity-70">
                                {{ message.sender_type }}
                            </div>
                            <p class="whitespace-pre-wrap">{{ message.content }}</p>
                        </article>
                    </div>
                </section>

                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Reponse humaine</h2>
                    <form class="mt-3 space-y-3" @submit.prevent="submitReply">
                        <FloatingTextarea v-model="replyMessage" label="Message" />
                        <InputError :message="replyError" />
                        <div class="flex justify-end">
                            <button
                                type="submit"
                                class="rounded-sm bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                                :disabled="replyProcessing || !replyMessage.trim()"
                            >
                                Envoyer
                            </button>
                        </div>
                    </form>
                </section>
            </main>

            <aside class="space-y-4">
                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Resume</h2>
                    <p class="mt-2 whitespace-pre-wrap text-sm text-stone-600 dark:text-neutral-300">
                        {{ conversation.summary || 'Aucun resume.' }}
                    </p>
                </section>

                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Actions pending</h2>
                    <p v-if="actionError" class="mt-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        {{ actionError }}
                    </p>
                    <div class="mt-3 space-y-2">
                        <div
                            v-for="action in pendingActions"
                            :key="action.id"
                            class="rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700"
                        >
                            <div class="font-semibold text-stone-800 dark:text-neutral-100">
                                {{ action.action_type }}
                            </div>
                            <div class="mt-2 flex justify-end gap-2">
                                <button
                                    type="button"
                                    class="rounded-sm border border-stone-200 px-2 py-1 text-xs font-semibold text-stone-700 dark:border-neutral-700 dark:text-neutral-200"
                                    @click="rejectAction(action)"
                                >
                                    Rejeter
                                </button>
                                <button
                                    type="button"
                                    class="rounded-sm bg-emerald-600 px-2 py-1 text-xs font-semibold text-white"
                                    @click="approveAction(action)"
                                >
                                    Approuver
                                </button>
                            </div>
                        </div>
                        <p v-if="!pendingActions.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            Aucune action pending.
                        </p>
                    </div>
                </section>
            </aside>
        </div>
    </AuthenticatedLayout>
</template>
