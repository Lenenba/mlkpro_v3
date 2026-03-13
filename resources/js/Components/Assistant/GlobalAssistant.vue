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
const voiceReplyEnabled = ref(false);
const autoSendVoice = ref(false);
const lastAssistantMessage = ref('');
const pendingAutoSend = ref(false);

const storageKey = computed(() => `mlkpro_assistant_context_v2_${accountKey.value}`);
const messagesKey = computed(() => `mlkpro_assistant_messages_v2_${accountKey.value}`);
const voiceReplyKey = computed(() => `mlkpro_assistant_voice_reply_v2_${accountKey.value}`);
const autoSendKey = computed(() => `mlkpro_assistant_voice_autosend_v2_${accountKey.value}`);

const speechSupported = computed(() => {
    return typeof window !== 'undefined' && (window.SpeechRecognition || window.webkitSpeechRecognition);
});
const speechOutputSupported = computed(() => {
    return typeof window !== 'undefined'
        && 'speechSynthesis' in window
        && typeof window.SpeechSynthesisUtterance !== 'undefined';
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
    localStorage.setItem(voiceReplyKey.value, voiceReplyEnabled.value ? '1' : '0');
    localStorage.setItem(autoSendKey.value, autoSendVoice.value ? '1' : '0');
};

const restoreState = () => {
    try {
        const storedContext = localStorage.getItem(storageKey.value);
        const storedMessages = localStorage.getItem(messagesKey.value);
        const parsedMessages = storedMessages ? JSON.parse(storedMessages) : [];
        context.value = storedContext ? JSON.parse(storedContext) : {};
        messages.value = Array.isArray(parsedMessages)
            ? parsedMessages.map((message) => ({
                id: message?.id || `${Date.now()}-${Math.random().toString(16).slice(2)}`,
                role: message?.role || 'assistant',
                content: typeof message?.content === 'string' ? message.content : '',
                ts: message?.ts || new Date().toISOString(),
                meta: message?.meta && typeof message.meta === 'object' ? message.meta : null,
            }))
            : [];
        voiceReplyEnabled.value = localStorage.getItem(voiceReplyKey.value) === '1';
        autoSendVoice.value = localStorage.getItem(autoSendKey.value) === '1';
        const lastAssistant = [...messages.value].reverse().find((message) => message.role === 'assistant');
        lastAssistantMessage.value = lastAssistant?.content || '';
    } catch (error) {
        context.value = {};
        messages.value = [];
        voiceReplyEnabled.value = false;
        autoSendVoice.value = false;
        lastAssistantMessage.value = '';
    }
};

const addMessage = (role, content, meta = null) => {
    messages.value.push({
        id: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
        role,
        content,
        ts: new Date().toISOString(),
        meta,
    });
    if (role === 'assistant') {
        lastAssistantMessage.value = content || '';
    }
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

const buildAssistantMeta = (payload) => {
    if (payload?.campaign_review && typeof payload.campaign_review === 'object') {
        return {
            campaignReview: payload.campaign_review,
        };
    }

    return null;
};

const trimPreviewText = (value, limit = 120) => {
    const text = String(value || '').replace(/\s+/g, ' ').trim();

    if (text.length <= limit) {
        return text;
    }

    return `${text.slice(0, limit - 3)}...`;
};

const summarizePreviewPayload = (payload) => {
    const previews = Array.isArray(payload?.previews) ? payload.previews : [];
    const estimated = Number(payload?.estimated?.total_eligible || 0);
    const lines = [`Apercu campagne pret. Audience estimee: ${estimated} contacts.`];
    const seenChannels = new Set();

    previews.forEach((preview) => {
        const channel = String(preview?.channel || '').toUpperCase();
        if (!channel || seenChannels.has(channel)) {
            return;
        }

        seenChannels.add(channel);
        const headline = trimPreviewText(preview?.subject || preview?.title || preview?.body || '', 90);
        if (headline) {
            lines.push(`${channel}: ${headline}`);
        }
    });

    return lines.join('\n');
};

const summarizeTestSendPayload = (payload) => {
    const results = Array.isArray(payload?.results) ? payload.results : [];
    if (results.length === 0) {
        return 'Aucun envoi de test n a ete retourne.';
    }

    const lines = ['Envoi de test lance:'];
    results.forEach((result) => {
        const channel = String(result?.channel || '').toUpperCase();
        if (result?.ok) {
            lines.push(`- ${channel}: ok`);
            return;
        }

        const reason = String(result?.reason || 'erreur');
        lines.push(`- ${channel}: ${reason}`);
    });

    return lines.join('\n');
};

const summarizeCampaignActionError = (error, fallback) => {
    return error?.response?.data?.message || fallback;
};

const runCampaignReviewAction = async (step, review) => {
    const campaignId = Number(step?.campaign_id || review?.campaign_id || 0);
    if (!campaignId || isLoading.value) {
        return;
    }

    if (step?.type === 'open_campaign_draft') {
        router.get(route('campaigns.edit', campaignId));
        return;
    }

    if (step?.type === 'view_campaign') {
        router.get(route('campaigns.show', campaignId));
        return;
    }

    isLoading.value = true;

    try {
        if (step?.type === 'preview_campaign') {
            const response = await window.axios.post(route('campaigns.preview', campaignId), {
                sample_size: 2,
            });
            const message = summarizePreviewPayload(response?.data || {});
            addMessage('assistant', message);
            if (voiceReplyEnabled.value) {
                speakMessage(message);
            }
            return;
        }

        if (step?.type === 'test_send_campaign') {
            const response = await window.axios.post(route('campaigns.test-send', campaignId), {
                channels: Array.isArray(step?.channels) ? step.channels : [],
            });
            const message = summarizeTestSendPayload(response?.data || {});
            addMessage('assistant', message);
            if (voiceReplyEnabled.value) {
                speakMessage(message);
            }
        }
    } catch (error) {
        const message = summarizeCampaignActionError(error, 'Action campagne indisponible pour le moment.');
        addMessage('assistant', message);
        if (voiceReplyEnabled.value) {
            speakMessage(message);
        }
    } finally {
        isLoading.value = false;
        persistState();
    }
};

const sanitizeSpeechText = (content) => {
    const cleaned = String(content || '')
        .replace(/^- /gm, '')
        .replace(/\s+/g, ' ')
        .trim();

    if (cleaned.length > 500) {
        return `${cleaned.slice(0, 500)}...`;
    }

    return cleaned;
};

const speakMessage = (content) => {
    if (!speechOutputSupported.value || !voiceReplyEnabled.value) {
        return;
    }
    const text = sanitizeSpeechText(content);
    if (!text) {
        return;
    }

    window.speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = locale.value === 'fr' ? 'fr-FR' : 'en-US';
    utterance.rate = 1;
    utterance.pitch = 1;
    window.speechSynthesis.speak(utterance);
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

        const assistantMessage = formatAssistantMessage(payload);
        const assistantMeta = buildAssistantMeta(payload);
        addMessage('assistant', assistantMessage, assistantMeta);
        if (voiceReplyEnabled.value) {
            speakMessage(assistantMessage);
        }

        if (payload?.action?.type === 'quote_created' && payload?.action?.quote_id) {
            router.get(route('customer.quote.edit', payload.action.quote_id));
        }

        if (payload?.action?.type === 'work_created' && payload?.action?.work_id) {
            router.get(route('work.edit', payload.action.work_id));
        }

        if (payload?.action?.type === 'invoice_created' && payload?.action?.invoice_id) {
            router.get(route('invoice.show', payload.action.invoice_id));
        }

        if (payload?.action?.type === 'campaign_draft_ready' && payload?.action?.campaign_id && !payload?.campaign_review) {
            router.get(route('campaigns.edit', payload.action.campaign_id));
        }
    } catch (error) {
        const status = error?.response?.status;
        const data = error?.response?.data || {};
        const message = data.message || (status ? 'Assistant indisponible. Reessayez plus tard.' : 'Assistant indisponible.');
        addMessage('assistant', message);
        if (voiceReplyEnabled.value) {
            speakMessage(message);
        }
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
    lastAssistantMessage.value = '';
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
    pendingAutoSend.value = false;
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
        if (autoSendVoice.value) {
            pendingAutoSend.value = true;
        }
    };

    recognition.onerror = () => {
        stopListening();
    };

    recognition.onend = () => {
        if (pendingAutoSend.value && input.value.trim() !== '') {
            pendingAutoSend.value = false;
            sendMessage();
        }
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
    lastAssistantMessage.value = '';
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
                <div class="text-xs text-stone-500">Workflows et brouillons campagne</div>
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
                <div>Try: "Create a quote for Acme with Service A at 120 and add taxes."</div>
                <div class="mt-2 text-xs text-stone-400">Voice commands:</div>
                <div class="text-xs text-stone-400 space-y-1">
                    <div>- "Cree un devis pour Acme avec Service A a 120."</div>
                    <div>- "Cree un job pour Jean Demo pour demain 9h."</div>
                    <div>- "Cree un membre Marc avec droits devis ecriture."</div>
                    <div>- "Prepare une campagne pour relancer mes anciens clients."</div>
                </div>
            </div>
            <div v-for="message in messages" :key="message.id" class="flex">
                <div
                    class="max-w-[88%] px-3 py-2 rounded-lg"
                    :class="message.role === 'user'
                        ? 'ms-auto bg-emerald-600 text-white'
                        : 'me-auto bg-stone-100 text-stone-700'"
                >
                    <div v-if="message.content" class="whitespace-pre-line">
                        {{ message.content }}
                    </div>
                    <div
                        v-if="message.role === 'assistant' && message.meta?.campaignReview"
                        class="mt-3 rounded-md border border-stone-200 bg-white p-3 text-xs text-stone-700 space-y-3"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-[10px] font-semibold uppercase tracking-[0.18em] text-emerald-700">
                                    Brouillon campagne
                                </div>
                                <div class="mt-1 text-sm font-semibold text-stone-900">
                                    {{ message.meta.campaignReview.title }}
                                </div>
                                <div class="text-[11px] text-stone-500">
                                    {{ message.meta.campaignReview.subtitle }}
                                </div>
                            </div>
                            <div class="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-semibold uppercase text-emerald-700">
                                {{ message.meta.campaignReview.status_label }}
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div
                                v-for="item in message.meta.campaignReview.summary || []"
                                :key="`${message.id}-${item.label}`"
                                class="rounded-md bg-stone-50 px-2 py-2"
                            >
                                <div class="text-[10px] uppercase tracking-wide text-stone-500">{{ item.label }}</div>
                                <div class="mt-1 text-xs font-semibold text-stone-800">{{ item.value }}</div>
                            </div>
                        </div>

                        <div v-if="(message.meta.campaignReview.deduced || []).length > 0" class="space-y-2">
                            <div class="text-[10px] font-semibold uppercase tracking-[0.18em] text-stone-500">Deduit</div>
                            <div
                                v-for="item in message.meta.campaignReview.deduced"
                                :key="`${message.id}-deduced-${item.label}`"
                                class="rounded-md border border-stone-200 px-2 py-2"
                            >
                                <div class="text-xs font-semibold text-stone-800">{{ item.label }}: {{ item.value }}</div>
                                <div class="mt-1 text-[11px] text-stone-500">{{ item.reason }}</div>
                            </div>
                        </div>

                        <div v-if="(message.meta.campaignReview.proposed || []).length > 0" class="space-y-2">
                            <div class="text-[10px] font-semibold uppercase tracking-[0.18em] text-stone-500">Propose</div>
                            <div
                                v-for="item in message.meta.campaignReview.proposed"
                                :key="`${message.id}-proposed-${item.label}`"
                                class="rounded-md border border-stone-200 px-2 py-2"
                            >
                                <div class="text-xs font-semibold text-stone-800">{{ item.label }}: {{ item.value }}</div>
                                <div class="mt-1 text-[11px] text-stone-500">{{ item.reason }}</div>
                            </div>
                        </div>

                        <div v-if="(message.meta.campaignReview.needs_confirmation || []).length > 0" class="space-y-2">
                            <div class="text-[10px] font-semibold uppercase tracking-[0.18em] text-amber-600">A confirmer</div>
                            <div
                                v-for="item in message.meta.campaignReview.needs_confirmation"
                                :key="`${message.id}-confirmation-${item.label}`"
                                class="rounded-md border border-amber-200 bg-amber-50 px-2 py-2"
                            >
                                <div class="text-xs font-semibold text-stone-800">{{ item.label }}: {{ item.value }}</div>
                                <div class="mt-1 text-[11px] text-stone-600">{{ item.reason }}</div>
                            </div>
                        </div>

                        <div v-if="(message.meta.campaignReview.next_steps || []).length > 0" class="space-y-2">
                            <div class="text-[10px] font-semibold uppercase tracking-[0.18em] text-stone-500">Actions rapides</div>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="step in message.meta.campaignReview.next_steps"
                                    :key="`${message.id}-step-${step.type}`"
                                    type="button"
                                    class="rounded-md border border-stone-200 bg-stone-50 px-2 py-1 text-[11px] font-medium text-stone-700 hover:bg-stone-100 disabled:opacity-50"
                                    :disabled="isLoading"
                                    @click="runCampaignReviewAction(step, message.meta.campaignReview)"
                                >
                                    {{ step.label }}
                                </button>
                            </div>
                        </div>
                    </div>
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
            <div class="flex items-center gap-3 text-[11px] text-stone-500">
                <label v-if="speechOutputSupported" class="inline-flex items-center gap-1">
                    <input v-model="voiceReplyEnabled" type="checkbox" class="rounded border-stone-300" />
                    Voice replies
                </label>
                <label v-if="speechSupported" class="inline-flex items-center gap-1">
                    <input v-model="autoSendVoice" type="checkbox" class="rounded border-stone-300" />
                    Auto-send voice
                </label>
                <button
                    v-if="speechOutputSupported && lastAssistantMessage"
                    type="button"
                    class="text-[11px] text-stone-500 hover:text-stone-800"
                    @click="speakMessage(lastAssistantMessage)"
                >
                    Replay
                </button>
            </div>
            <div v-if="transcriptHint" class="text-xs text-stone-400">{{ transcriptHint }}</div>
        </div>
    </div>
</template>
