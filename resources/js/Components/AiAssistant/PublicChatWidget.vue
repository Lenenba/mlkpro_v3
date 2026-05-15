<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { AlertCircle, Bot, Loader2, MessageCircle, Send, X } from 'lucide-vue-next';

const props = defineProps({
    companyName: {
        type: String,
        required: true,
    },
    companySlug: {
        type: String,
        required: true,
    },
    companyLogoUrl: {
        type: String,
        default: '',
    },
    assistantName: {
        type: String,
        default: 'Malikia AI Assistant',
    },
    endpoints: {
        type: Object,
        required: true,
    },
    channel: {
        type: String,
        default: 'web_chat',
    },
    mode: {
        type: String,
        default: 'page',
    },
    initialMetadata: {
        type: Object,
        default: () => ({}),
    },
    visitorName: {
        type: String,
        default: '',
    },
    visitorEmail: {
        type: String,
        default: '',
    },
    visitorPhone: {
        type: String,
        default: '',
    },
});

const isFloating = computed(() => props.mode === 'floating');
const isOpen = ref(!isFloating.value);
const loadingConversation = ref(false);
const sending = ref(false);
const errorMessage = ref('');
const conversationUuid = ref('');
const messages = ref([]);
const draftMessage = ref('');
const transcriptRef = ref(null);

const canSend = computed(() => draftMessage.value.trim().length > 0 && !sending.value && !loadingConversation.value);
const hasConversation = computed(() => conversationUuid.value !== '');

const messageEndpoint = computed(() => {
    const endpoint = String(props.endpoints?.message || '');

    return endpoint.replace('__conversation__', conversationUuid.value);
});

const normalizeMessage = (message) => ({
    sender_type: message?.sender_type || 'assistant',
    content: String(message?.content || ''),
    created_at: message?.created_at || new Date().toISOString(),
});

const scrollToEnd = async () => {
    await nextTick();
    if (transcriptRef.value) {
        transcriptRef.value.scrollTop = transcriptRef.value.scrollHeight;
    }
};

const startConversation = async () => {
    if (hasConversation.value || loadingConversation.value) {
        return;
    }

    loadingConversation.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.post(props.endpoints.create, {
            company: props.companySlug,
            channel: props.channel,
            visitor_name: props.visitorName || undefined,
            visitor_email: props.visitorEmail || undefined,
            visitor_phone: props.visitorPhone || undefined,
            metadata: props.initialMetadata || {},
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        conversationUuid.value = response?.data?.conversation?.uuid || '';
        messages.value = response?.data?.message
            ? [normalizeMessage(response.data.message)]
            : [];
        await scrollToEnd();
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Assistant indisponible pour le moment.';
    } finally {
        loadingConversation.value = false;
    }
};

const openWidget = async () => {
    isOpen.value = true;
    await startConversation();
};

const closeWidget = () => {
    if (isFloating.value) {
        isOpen.value = false;
    }
};

const sendMessage = async () => {
    const message = draftMessage.value.trim();
    if (!message || sending.value) {
        return;
    }

    if (!hasConversation.value) {
        await startConversation();
    }

    if (!hasConversation.value || !messageEndpoint.value) {
        return;
    }

    sending.value = true;
    errorMessage.value = '';
    draftMessage.value = '';

    try {
        const response = await axios.post(messageEndpoint.value, {
            message,
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        messages.value = [
            ...messages.value,
            ...(response?.data?.messages || []).map(normalizeMessage),
        ];
        await scrollToEnd();
    } catch (error) {
        draftMessage.value = message;
        errorMessage.value = error?.response?.data?.message || 'Message non envoye. Reessayez dans un instant.';
    } finally {
        sending.value = false;
    }
};

const handleSubmit = () => {
    sendMessage();
};

watch(isOpen, (opened) => {
    if (opened) {
        startConversation();
    }
});

onMounted(() => {
    if (!isFloating.value) {
        startConversation();
    }
});
</script>

<template>
    <div>
        <button
            v-if="isFloating && !isOpen"
            type="button"
            class="fixed bottom-5 right-5 z-50 inline-flex items-center gap-2 rounded-sm bg-emerald-700 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-900/20 transition hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
            data-testid="public-ai-chat-open"
            @click="openWidget"
        >
            <MessageCircle class="size-5" />
            Assistant
        </button>

        <section
            v-if="isOpen"
            :class="isFloating
                ? 'fixed bottom-5 right-5 z-50 flex h-[min(680px,calc(100vh-2.5rem))] w-[min(420px,calc(100vw-2rem))] flex-col overflow-hidden rounded-sm border border-stone-200 bg-white shadow-2xl shadow-stone-900/20'
                : 'flex min-h-[640px] w-full flex-col overflow-hidden rounded-sm border border-stone-200 bg-white shadow-sm'"
            data-testid="public-ai-chat-panel"
        >
            <header class="flex items-center justify-between gap-3 border-b border-stone-200 bg-stone-950 px-4 py-3 text-white">
                <div class="flex min-w-0 items-center gap-3">
                    <img
                        v-if="companyLogoUrl"
                        :src="companyLogoUrl"
                        :alt="companyName"
                        class="size-9 rounded-sm object-cover"
                    >
                    <span v-else class="flex size-9 items-center justify-center rounded-sm bg-emerald-600 text-sm font-semibold">
                        {{ String(companyName || 'M').slice(0, 1) }}
                    </span>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-semibold">{{ assistantName }}</div>
                        <div class="truncate text-xs text-stone-300">{{ companyName }}</div>
                    </div>
                </div>
                <button
                    v-if="isFloating"
                    type="button"
                    class="inline-flex size-8 items-center justify-center rounded-sm text-stone-300 hover:bg-white/10 hover:text-white"
                    aria-label="Fermer"
                    @click="closeWidget"
                >
                    <X class="size-4" />
                </button>
            </header>

            <div ref="transcriptRef" class="flex-1 space-y-3 overflow-y-auto bg-stone-50 px-4 py-4">
                <div v-if="loadingConversation && !messages.length" class="flex items-center gap-2 text-sm text-stone-500">
                    <Loader2 class="size-4 animate-spin text-emerald-700" />
                    Connexion...
                </div>

                <article
                    v-for="(message, index) in messages"
                    :key="`${message.created_at}-${index}`"
                    class="flex"
                    :class="message.sender_type === 'user' ? 'justify-end' : 'justify-start'"
                >
                    <div
                        class="max-w-[86%] rounded-sm px-3 py-2 text-sm leading-6 shadow-sm"
                        :class="message.sender_type === 'user'
                            ? 'bg-emerald-700 text-white'
                            : 'border border-stone-200 bg-white text-stone-800'"
                    >
                        <div v-if="message.sender_type !== 'user'" class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-emerald-700">
                            <Bot class="size-3.5" />
                            Assistant
                        </div>
                        <p class="whitespace-pre-wrap">{{ message.content }}</p>
                    </div>
                </article>

                <div v-if="sending" class="flex items-center gap-2 text-sm text-stone-500">
                    <Loader2 class="size-4 animate-spin text-emerald-700" />
                    Reponse...
                </div>
            </div>

            <div v-if="errorMessage" class="border-t border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                <span class="inline-flex items-start gap-2">
                    <AlertCircle class="mt-0.5 size-4 shrink-0" />
                    <span>{{ errorMessage }}</span>
                </span>
            </div>

            <form class="border-t border-stone-200 bg-white p-3" @submit.prevent="handleSubmit">
                <div class="flex items-end gap-2">
                    <textarea
                        v-model="draftMessage"
                        rows="2"
                        class="min-h-[44px] flex-1 resize-none rounded-sm border-stone-200 text-sm focus:border-emerald-600 focus:ring-emerald-600"
                        placeholder="Ecrivez votre message..."
                        data-testid="public-ai-chat-input"
                        @keydown.enter.exact.prevent="sendMessage"
                    />
                    <button
                        type="submit"
                        class="inline-flex size-11 shrink-0 items-center justify-center rounded-sm bg-emerald-700 text-white transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!canSend"
                        aria-label="Envoyer"
                        data-testid="public-ai-chat-send"
                    >
                        <Loader2 v-if="sending" class="size-5 animate-spin" />
                        <Send v-else class="size-5" />
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
