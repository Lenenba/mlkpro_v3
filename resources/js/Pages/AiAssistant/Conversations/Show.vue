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
const showAllMessages = ref(false);

const pendingActions = computed(() => (props.conversation.actions || [])
    .filter((action) => action.status === 'pending'));
const completedActions = computed(() => (props.conversation.actions || [])
    .filter((action) => action.status !== 'pending'));
const messages = computed(() => props.conversation.messages || []);
const visibleMessages = computed(() => (
    showAllMessages.value ? messages.value : messages.value.slice(-5)
));
const hiddenMessagesCount = computed(() => Math.max(messages.value.length - visibleMessages.value.length, 0));

const statusLabels = {
    open: 'En cours',
    waiting_human: 'A valider',
    resolved: 'Resolue',
    abandoned: 'Abandonnee',
};

const senderLabels = {
    user: 'Client',
    assistant: 'Assistant',
    human: 'Equipe',
    system: 'Systeme',
};

const labelForStatus = (status) => statusLabels[status] || status || '-';
const labelForSender = (sender) => senderLabels[sender] || sender || '-';

const formatDate = (value) => {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('fr-CA', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};

const actionFields = (action) => {
    const payload = action.input_payload || {};
    return [
        ['Client', payload.contact_name],
        ['Email', payload.contact_email],
        ['Telephone', payload.contact_phone],
        ['Service', payload.service_name],
        ['Date', payload.starts_at ? formatDate(payload.starts_at) : null],
    ].filter(([, value]) => value);
};

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
                <header class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <Link
                            :href="route('admin.ai-assistant.conversations.index', { queue: 'review' })"
                            class="text-sm font-semibold text-emerald-700 hover:text-emerald-800 dark:text-emerald-300"
                        >
                            Retour inbox
                        </Link>
                        <h1 class="mt-2 text-xl font-semibold text-stone-900 dark:text-neutral-50">
                            {{ conversation.title || conversation.visitor_name || 'Conversation IA' }}
                        </h1>
                        <p class="mt-1 text-sm text-stone-500 dark:text-neutral-400">
                            {{ labelForStatus(conversation.status) }} · {{ conversation.intent || 'conversation' }} · {{ formatDate(conversation.updated_at || conversation.created_at) }}
                        </p>
                    </div>
                    <Link
                        :href="route('admin.ai-assistant.settings.edit')"
                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                    >
                        Reglages
                    </Link>
                </header>

                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">A valider</h2>
                            <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                {{ pendingActions.length }} action(s) en attente.
                            </p>
                        </div>
                        <span
                            class="rounded-full border px-2 py-1 text-xs font-semibold"
                            :class="pendingActions.length
                                ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100'
                                : 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100'"
                        >
                            {{ pendingActions.length ? 'Decision requise' : 'Rien a valider' }}
                        </span>
                    </div>

                    <p v-if="actionError" class="mt-3 rounded-sm border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        {{ actionError }}
                    </p>

                    <div class="mt-3 space-y-3">
                        <article
                            v-for="action in pendingActions"
                            :key="action.id"
                            class="rounded-sm border border-stone-200 bg-stone-50 p-3 dark:border-neutral-700 dark:bg-neutral-800"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <h3 class="text-sm font-semibold text-stone-900 dark:text-neutral-50">
                                        {{ action.label || action.action_type }}
                                    </h3>
                                    <p class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ action.preview || 'A verifier' }}
                                    </p>
                                </div>
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        class="rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                        @click="rejectAction(action)"
                                    >
                                        Rejeter
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-sm bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700"
                                        @click="approveAction(action)"
                                    >
                                        Approuver
                                    </button>
                                </div>
                            </div>
                            <dl v-if="actionFields(action).length" class="mt-3 grid gap-2 md:grid-cols-2">
                                <div
                                    v-for="[label, value] in actionFields(action)"
                                    :key="`${action.id}-${label}`"
                                    class="rounded-sm border border-stone-200 bg-white px-2 py-1.5 dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-neutral-400">{{ label }}</dt>
                                    <dd class="mt-0.5 truncate text-sm text-stone-800 dark:text-neutral-100">{{ value }}</dd>
                                </div>
                            </dl>
                        </article>
                        <p v-if="!pendingActions.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            Cette conversation ne contient aucune action en attente.
                        </p>
                    </div>
                </section>

                <section class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Conversation</h2>
                        <button
                            v-if="hiddenMessagesCount"
                            type="button"
                            class="text-xs font-semibold text-emerald-700 hover:text-emerald-800 dark:text-emerald-300"
                            @click="showAllMessages = !showAllMessages"
                        >
                            {{ showAllMessages ? 'Voir recent' : `Voir ${hiddenMessagesCount} ancien(s)` }}
                        </button>
                    </div>
                    <div class="mt-3 space-y-3">
                        <article
                            v-for="message in visibleMessages"
                            :key="message.id"
                            class="rounded-sm border px-3 py-2 text-sm"
                            :class="message.sender_type === 'user'
                                ? 'border-stone-200 bg-stone-50 text-stone-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100'
                                : 'border-emerald-100 bg-emerald-50 text-emerald-950 dark:border-emerald-500/30 dark:bg-emerald-950/30 dark:text-emerald-100'"
                        >
                            <div class="mb-1 flex flex-wrap items-center justify-between gap-2 text-xs font-semibold uppercase tracking-wide opacity-70">
                                <span>{{ labelForSender(message.sender_type) }}</span>
                                <span>{{ formatDate(message.created_at) }}</span>
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
                    <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">Historique actions</h2>
                    <div class="mt-3 space-y-2">
                        <div
                            v-for="action in completedActions"
                            :key="action.id"
                            class="rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="font-semibold text-stone-800 dark:text-neutral-100">
                                        {{ action.label || action.action_type }}
                                    </div>
                                    <div class="mt-1 text-xs text-stone-500 dark:text-neutral-400">
                                        {{ action.preview || 'A verifier' }}
                                    </div>
                                </div>
                                <span class="rounded-full bg-stone-100 px-2 py-1 text-[11px] font-semibold text-stone-600 dark:bg-neutral-800 dark:text-neutral-300">
                                    {{ action.status }}
                                </span>
                            </div>
                        </div>
                        <p v-if="!completedActions.length" class="text-sm text-stone-500 dark:text-neutral-400">
                            Aucune action terminee.
                        </p>
                    </div>
                </section>
            </aside>
        </div>
    </AuthenticatedLayout>
</template>
