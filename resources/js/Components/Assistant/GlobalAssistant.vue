<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const page = usePage();

const assistantEnabled = computed(() => Boolean(page.props.assistant?.enabled));
const locale = computed(() => page.props.locale || 'fr');
const accountKey = computed(() => page.props.auth?.account?.owner_id || page.props.auth?.user?.id || 'guest');
const currentCustomer = computed(() => {
    const customer = page.props.customer
        || page.props.quote?.customer
        || page.props.work?.customer
        || page.props.invoice?.customer;
    if (!customer || !customer.id) {
        return null;
    }

    return {
        id: customer.id,
        first_name: customer.first_name || '',
        last_name: customer.last_name || '',
        company_name: customer.company_name || '',
        email: customer.email || '',
        phone: customer.phone || '',
    };
});
const currentQuote = computed(() => {
    const quote = page.props.quote;
    if (!quote || !quote.id) {
        return null;
    }

    return {
        id: quote.id,
        number: quote.number || '',
        status: quote.status || '',
        customer_id: quote.customer_id || quote.customer?.id || null,
        work_id: quote.work_id || null,
    };
});
const currentWork = computed(() => {
    const work = page.props.work;
    if (!work || !work.id) {
        return null;
    }

    return {
        id: work.id,
        number: work.number || '',
        status: work.status || '',
        customer_id: work.customer_id || work.customer?.id || null,
    };
});
const currentInvoice = computed(() => {
    const invoice = page.props.invoice;
    if (!invoice || !invoice.id) {
        return null;
    }

    return {
        id: invoice.id,
        number: invoice.number || '',
        status: invoice.status || '',
        customer_id: invoice.customer_id || invoice.customer?.id || null,
        work_id: invoice.work_id || invoice.work?.id || null,
    };
});

const isOpen = ref(false);
const isLoading = ref(false);
const isListening = ref(false);
const input = ref('');
const messages = ref([]);
const context = ref({});
const transcriptHint = ref('');
const recognitionRef = ref(null);

const storageKey = computed(() => `mlkpro_assistant_context_v2_${accountKey.value}`);
const messagesKey = computed(() => `mlkpro_assistant_messages_v2_${accountKey.value}`);

const speechSupported = computed(() => {
    return typeof window !== 'undefined' && (window.SpeechRecognition || window.webkitSpeechRecognition);
});

const scrollToBottom = async () => {
    await nextTick();
    const container = document.getElementById('assistant-messages');
    if (container) {
        container.scrollTop = container.scrollHeight;
    }
};

const persistState = () => {
    localStorage.setItem(storageKey.value, JSON.stringify(context.value || {}));
    localStorage.setItem(messagesKey.value, JSON.stringify(messages.value || []));
};

const restoreState = () => {
    try {
        const storedContext = localStorage.getItem(storageKey.value);
        const storedMessages = localStorage.getItem(messagesKey.value);
        context.value = storedContext ? JSON.parse(storedContext) : {};
        messages.value = storedMessages ? JSON.parse(storedMessages) : [];
    } catch (error) {
        context.value = {};
        messages.value = [];
    }
};

const addMessage = (role, content) => {
    messages.value.push({
        id: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
        role,
        content,
        ts: new Date().toISOString(),
    });
    scrollToBottom();
};

const formatAssistantMessage = (payload) => {
    let content = payload?.message || '';
    if (Array.isArray(payload?.questions) && payload.questions.length > 0) {
        const questionText = payload.questions.map((question) => `- ${question}`).join('\n');
        content = content ? `${content}\n${questionText}` : questionText;
    }
    return content || 'Assistant ready.';
};

const sendMessage = async () => {
    const trimmed = input.value.trim();
    if (!trimmed || isLoading.value) {
        return;
    }

    input.value = '';
    transcriptHint.value = '';
    addMessage('user', trimmed);
    isLoading.value = true;

    try {
        const requestContext = {
            ...(context.value || {}),
            ...(currentCustomer.value ? { current_customer: currentCustomer.value } : {}),
            ...(currentQuote.value ? { current_quote: currentQuote.value } : {}),
            ...(currentWork.value ? { current_work: currentWork.value } : {}),
            ...(currentInvoice.value ? { current_invoice: currentInvoice.value } : {}),
            page: {
                component: page.component,
                url: page.url,
            },
        };

        const response = await window.axios.post(route('assistant.message'), {
            message: trimmed,
            context: requestContext,
        });

        const payload = response?.data || {};
        if (payload.context !== undefined) {
            context.value = payload.context;
        }

        addMessage('assistant', formatAssistantMessage(payload));

        if (payload?.action?.type === 'quote_created' && payload?.action?.quote_id) {
            router.get(route('customer.quote.edit', payload.action.quote_id));
        }

        if (payload?.action?.type === 'work_created' && payload?.action?.work_id) {
            router.get(route('work.edit', payload.action.work_id));
        }

        if (payload?.action?.type === 'invoice_created' && payload?.action?.invoice_id) {
            router.get(route('invoice.show', payload.action.invoice_id));
        }
    } catch (error) {
        const status = error?.response?.status;
        const data = error?.response?.data || {};
        const message = data.message || (status ? 'Assistant indisponible. Reessayez plus tard.' : 'Assistant indisponible.');
        addMessage('assistant', message);
    } finally {
        isLoading.value = false;
        persistState();
    }
};

const toggleOpen = () => {
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        scrollToBottom();
    }
};

const clearConversation = () => {
    context.value = {};
    messages.value = [];
    localStorage.removeItem(storageKey.value);
    localStorage.removeItem(messagesKey.value);
};

const stopListening = () => {
    if (recognitionRef.value) {
        recognitionRef.value.stop();
    }
    recognitionRef.value = null;
    isListening.value = false;
    transcriptHint.value = '';
};

const startListening = () => {
    if (!speechSupported.value || isListening.value) {
        return;
    }

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    const recognition = new SpeechRecognition();
    recognition.lang = locale.value === 'fr' ? 'fr-FR' : 'en-US';
    recognition.interimResults = false;
    recognition.maxAlternatives = 1;

    recognition.onresult = (event) => {
        const transcript = event?.results?.[0]?.[0]?.transcript || '';
        input.value = `${input.value} ${transcript}`.trim();
    };

    recognition.onerror = () => {
        stopListening();
    };

    recognition.onend = () => {
        stopListening();
    };

    recognition.start();
    recognitionRef.value = recognition;
    isListening.value = true;
    transcriptHint.value = 'Listening...';
};

const toggleListening = () => {
    if (isListening.value) {
        stopListening();
        return;
    }
    startListening();
};

watch([messages, context], persistState, { deep: true });
watch(accountKey, () => {
    context.value = {};
    messages.value = [];
    restoreState();
});

onMounted(() => {
    restoreState();
});
</script>

<template>
    <div v-if="assistantEnabled" class="fixed bottom-5 right-5 z-50">
        <button
            type="button"
            class="flex items-center justify-center w-12 h-12 rounded-full bg-emerald-600 text-white shadow-lg hover:bg-emerald-700"
            @click="toggleOpen"
            aria-label="Assistant"
            data-testid="assistant-toggle"
        >
            <span class="text-lg font-semibold">AI</span>
        </button>
    </div>

    <div
        v-if="assistantEnabled && isOpen"
        class="fixed bottom-20 right-5 z-50 w-[360px] max-w-[calc(100vw-2rem)] h-[520px] bg-white border border-stone-200 rounded-lg shadow-xl flex flex-col"
        data-testid="assistant-panel"
    >
        <div class="flex items-center justify-between px-4 py-3 border-b border-stone-200">
            <div>
                <div class="text-sm font-semibold text-stone-800">Assistant</div>
                <div class="text-xs text-stone-500">Workflow only (no UI changes)</div>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="text-xs text-stone-500 hover:text-stone-800"
                    @click="clearConversation"
                    aria-label="Clear conversation"
                >
                    Clear
                </button>
                <button
                    type="button"
                    class="text-stone-400 hover:text-stone-700"
                    @click="toggleOpen"
                    aria-label="Close assistant"
                >
                    ✕
                </button>
            </div>
        </div>

        <div id="assistant-messages" class="flex-1 overflow-y-auto px-4 py-3 space-y-3 text-sm">
            <div v-if="messages.length === 0" class="text-stone-400">
                Try: "Create a quote for Acme with Service A at 120 and add taxes."
            </div>
            <div v-for="message in messages" :key="message.id" class="flex">
                <div
                    class="max-w-[80%] px-3 py-2 rounded-lg whitespace-pre-line"
                    :class="message.role === 'user'
                        ? 'ms-auto bg-emerald-600 text-white'
                        : 'me-auto bg-stone-100 text-stone-700'"
                >
                    {{ message.content }}
                </div>
            </div>
            <div v-if="isLoading" class="text-xs text-stone-400">Assistant is thinking...</div>
        </div>

        <div class="border-t border-stone-200 p-3 space-y-2">
            <div class="flex items-center gap-2">
                <button
                    v-if="speechSupported"
                    type="button"
                    class="w-9 h-9 rounded-full border border-stone-200 flex items-center justify-center text-stone-600 hover:text-stone-900"
                    :class="isListening ? 'bg-emerald-100 border-emerald-300 text-emerald-700' : ''"
                    @click="toggleListening"
                    aria-label="Voice input"
                >
                    <svg
                        class="w-4 h-4"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M12 1a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3Z" />
                        <path d="M19 10v1a7 7 0 0 1-14 0v-1" />
                        <path d="M12 18v4" />
                        <path d="M8 22h8" />
                    </svg>
                </button>
                <input
                    v-model="input"
                    type="text"
                    class="flex-1 rounded-md border border-stone-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500"
                    placeholder="Type a request..."
                    @keydown.enter.prevent="sendMessage"
                    data-testid="assistant-input"
                />
                <button
                    type="button"
                    class="px-3 py-2 rounded-md bg-emerald-600 text-white text-sm hover:bg-emerald-700 disabled:opacity-50"
                    :disabled="isLoading || input.trim() === ''"
                    @click="sendMessage"
                >
                    Send
                </button>
            </div>
            <div v-if="transcriptHint" class="text-xs text-stone-400">{{ transcriptHint }}</div>
        </div>
    </div>
</template>
