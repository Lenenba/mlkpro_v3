<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DateTimePicker from '@/Components/DateTimePicker.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import InputError from '@/Components/InputError.vue';
import { humanizeDate } from '@/utils/date';
import { formatBytes } from '@/utils/media';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    ticket: {
        type: Object,
        required: true,
    },
    messages: {
        type: Array,
        default: () => [],
    },
    activity: {
        type: Array,
        default: () => [],
    },
    statuses: {
        type: Array,
        default: () => [],
    },
    priorities: {
        type: Array,
        default: () => [],
    },
    assignees: {
        type: Array,
        default: () => [],
    },
});

const { t } = useI18n();

const statusOptions = computed(() =>
    (props.statuses || []).map((status) => ({
        value: status,
        label: status,
    }))
);
const priorityOptions = computed(() =>
    (props.priorities || []).map((priority) => ({
        value: priority,
        label: priority,
    }))
);
const assigneeOptions = computed(() =>
    (props.assignees || []).map((assignee) => ({
        value: String(assignee.id),
        label: assignee.name || assignee.email,
    }))
);

const updateForm = useForm({
    status: props.ticket.status || 'open',
    priority: props.ticket.priority || 'normal',
    sla_due_at: props.ticket.sla_due_at ? props.ticket.sla_due_at.substring(0, 16) : '',
    tags: Array.isArray(props.ticket.tags) ? props.ticket.tags.join(', ') : '',
    assigned_to_user_id: props.ticket.assigned_to?.id ? String(props.ticket.assigned_to.id) : '',
});

const messageForm = useForm({
    body: '',
    is_internal: false,
    attachments: [],
});
const messageAttachmentInput = ref(null);

const submitUpdate = () => {
    updateForm.put(route('superadmin.support.update', props.ticket.id), {
        preserveScroll: true,
    });
};

const submitMessage = () => {
    messageForm.post(route('superadmin.support.messages.store', props.ticket.id), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            messageForm.reset('body', 'attachments');
            if (messageAttachmentInput.value) {
                messageAttachmentInput.value.value = '';
            }
        },
    });
};

const formatDate = (value) => humanizeDate(value);
const formatAbsoluteDate = (value) => {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    return date.toLocaleString();
};

const auditLabel = (entry) => {
    const key = `super_admin.support.audit.actions["${entry.action}"]`;
    const translated = t(key);
    return translated === key ? entry.action : translated;
};

const onMessageAttachmentChange = (event) => {
    const files = event?.target?.files ? Array.from(event.target.files) : [];
    messageForm.attachments = files;
};

const attachmentLabel = (media) => media?.original_name || t('super_admin.support.attachments.file');
const isImage = (media) => media?.mime?.startsWith('image/');
const attachmentMeta = (media) => {
    const parts = [];
    if (media?.mime) {
        parts.push(media.mime);
    }
    if (media?.size) {
        parts.push(formatBytes(media.size));
    }
    return parts.join(' · ') || t('super_admin.support.attachments.file');
};
</script>

<template>
    <Head :title="`${$t('super_admin.support.title')} #${ticket.id}`" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <section class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div>
                    <h1 class="text-xl font-semibold text-stone-800 dark:text-neutral-100">
                        {{ $t('super_admin.support.show.title') }} #{{ ticket.id }}
                    </h1>
                    <p class="mt-1 text-sm text-stone-600 dark:text-neutral-400">
                        {{ ticket.title }}
                    </p>
                </div>
                <Link
                    :href="route('superadmin.support.index')"
                    class="inline-flex items-center rounded-sm border border-stone-200 px-3 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                >
                    {{ $t('super_admin.support.actions.back') }}
                </Link>
            </section>

            <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
                <div class="space-y-6">
                    <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.support.sections.conversation') }}
                        </h2>
                        <div class="mt-4 space-y-4">
                            <div v-if="!messages.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.support.empty_states.conversation') }}
                            </div>
                            <div v-for="message in messages" :key="message.id"
                                class="rounded-sm border border-stone-200 p-3 text-sm dark:border-neutral-700">
                                <div class="flex items-center justify-between text-xs text-stone-500 dark:text-neutral-400">
                                    <span>
                                        {{ message.user?.name || message.user?.email || $t('super_admin.common.unknown') }}
                                        <span v-if="message.is_internal" class="ms-2 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                                            {{ $t('super_admin.support.labels.internal') }}
                                        </span>
                                    </span>
                                    <span>{{ formatAbsoluteDate(message.created_at) }}</span>
                                </div>
                                <div v-if="message.body" class="message-body mt-2 text-stone-700 dark:text-neutral-200" v-html="message.body"></div>
                                <div v-if="message.media?.length" class="mt-3 flex flex-wrap gap-2 text-xs">
                                    <a v-for="media in message.media" :key="media.id" :href="media.url" target="_blank" rel="noopener"
                                        class="inline-flex items-center gap-2 rounded-sm border border-stone-200 bg-white px-2 py-1 text-[11px] text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                        <img v-if="isImage(media)" :src="media.url" :alt="attachmentLabel(media)" class="h-10 w-10 rounded-sm object-cover" loading="lazy" decoding="async" />
                                        <span v-else class="truncate">{{ attachmentLabel(media) }}</span>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <form class="mt-5 space-y-3" @submit.prevent="submitMessage">
                            <RichTextEditor
                                v-model="messageForm.body"
                                :label="$t('super_admin.support.form.message')"
                                :placeholder="$t('super_admin.support.form.message')"
                                :disabled="messageForm.processing"
                                :link-prompt="$t('super_admin.support.editor.link_prompt')"
                                :image-prompt="$t('super_admin.support.editor.image_prompt')"
                                :labels="{
                                    heading2: $t('super_admin.support.editor.heading_2'),
                                    heading3: $t('super_admin.support.editor.heading_3'),
                                    bold: $t('super_admin.support.editor.bold'),
                                    italic: $t('super_admin.support.editor.italic'),
                                    underline: $t('super_admin.support.editor.underline'),
                                    unorderedList: $t('super_admin.support.editor.unordered_list'),
                                    orderedList: $t('super_admin.support.editor.ordered_list'),
                                    quote: $t('super_admin.support.editor.quote'),
                                    codeBlock: $t('super_admin.support.editor.code_block'),
                                    horizontalRule: $t('super_admin.support.editor.horizontal_rule'),
                                    link: $t('super_admin.support.editor.link'),
                                    image: $t('super_admin.support.editor.image'),
                                    clear: $t('super_admin.support.editor.clear'),
                                }"
                            />
                            <div>
                                <label class="block text-xs text-stone-500 dark:text-neutral-400">
                                    {{ $t('super_admin.support.sections.attachments') }}
                                </label>
                                <input
                                    ref="messageAttachmentInput"
                                    type="file"
                                    multiple
                                    accept="image/*,application/pdf"
                                    class="mt-1 block w-full text-sm text-stone-600 file:me-4 file:rounded-sm file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-stone-700 hover:file:bg-stone-200 dark:text-neutral-300 dark:file:bg-neutral-800 dark:file:text-neutral-200 dark:hover:file:bg-neutral-700"
                                    @change="onMessageAttachmentChange"
                                />
                                <div v-if="messageForm.attachments?.length" class="mt-2 flex flex-wrap gap-2">
                                    <span v-for="file in messageForm.attachments" :key="file.name"
                                        class="inline-flex max-w-[200px] items-center gap-1 rounded-full border border-stone-200 bg-white px-2 py-0.5 text-[11px] text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                                        {{ file.name }}
                                    </span>
                                </div>
                            </div>
                            <label class="inline-flex items-center gap-2 text-xs text-stone-500 dark:text-neutral-400">
                                <input v-model="messageForm.is_internal" type="checkbox" class="rounded-sm border-stone-300 text-green-600 focus:ring-green-600 dark:border-neutral-700 dark:bg-neutral-900" />
                                {{ $t('super_admin.support.form.internal_message') }}
                            </label>
                            <InputError class="mt-1" :message="messageForm.errors.body" />
                            <InputError class="mt-1" :message="messageForm.errors.attachments" />
                            <InputError class="mt-1" :message="messageForm.errors['attachments.0']" />
                            <div class="flex justify-end">
                                <button type="submit"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700"
                                    :disabled="messageForm.processing">
                                    {{ $t('super_admin.support.actions.send_message') }}
                                </button>
                            </div>
                        </form>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.support.sections.history') }}
                        </h2>
                        <div class="mt-4 space-y-3">
                            <div v-if="!activity.length" class="text-sm text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.support.empty_states.history') }}
                            </div>
                            <div v-for="entry in activity" :key="entry.id" class="flex items-start justify-between gap-3 text-xs text-stone-600 dark:text-neutral-300">
                                <div>
                                    <div class="font-semibold text-stone-700 dark:text-neutral-200">{{ auditLabel(entry) }}</div>
                                    <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                                        {{ entry.user?.name || entry.user?.email || $t('super_admin.common.unknown') }}
                                    </div>
                                </div>
                                <div class="text-[11px] text-stone-400 dark:text-neutral-500">
                                    {{ formatAbsoluteDate(entry.created_at) }}
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <aside class="space-y-6">
                    <section class="rounded-sm border border-stone-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.support.sections.update') }}
                        </h3>
                        <form class="mt-4 space-y-3" @submit.prevent="submitUpdate">
                            <FloatingSelect
                                v-model="updateForm.assigned_to_user_id"
                                :label="$t('super_admin.support.form.assigned')"
                                :options="assigneeOptions"
                                :placeholder="$t('super_admin.support.form.select_assignee')"
                            />
                            <div class="grid gap-3 md:grid-cols-2">
                                <FloatingSelect v-model="updateForm.status" :label="$t('super_admin.support.form.status')" :options="statusOptions" />
                                <FloatingSelect v-model="updateForm.priority" :label="$t('super_admin.support.form.priority')" :options="priorityOptions" />
                            </div>
                            <div>
                                <DateTimePicker
                                    v-model="updateForm.sla_due_at"
                                    :label="$t('super_admin.support.form.sla_due')"
                                />
                            </div>
                            <FloatingInput v-model="updateForm.tags" :label="$t('super_admin.support.form.tags')" />
                            <InputError class="mt-1" :message="updateForm.errors.assigned_to_user_id" />
                            <InputError class="mt-1" :message="updateForm.errors.status" />
                            <InputError class="mt-1" :message="updateForm.errors.priority" />
                            <InputError class="mt-1" :message="updateForm.errors.sla_due_at" />
                            <InputError class="mt-1" :message="updateForm.errors.tags" />
                            <div class="flex justify-end">
                                <button type="submit"
                                    class="py-2 px-3 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700"
                                    :disabled="updateForm.processing">
                                    {{ $t('super_admin.support.actions.save_ticket') }}
                                </button>
                            </div>
                        </form>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-5 text-sm text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.support.sections.details') }}
                        </h3>
                        <div class="mt-3 space-y-2">
                            <div class="flex items-center justify-between">
                                <span>{{ $t('super_admin.support.labels.tenant') }}</span>
                                <span>{{ ticket.account?.company_name || ticket.account?.email }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('super_admin.support.labels.created_at') }}</span>
                                <span>{{ formatDate(ticket.created_at) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>{{ $t('super_admin.support.labels.assigned_to') }}</span>
                                <span>{{ ticket.assigned_to?.name || ticket.assigned_to?.email || $t('super_admin.common.not_available') }}</span>
                            </div>
                            <div class="flex items-center justify-between" v-if="ticket.sla_due_at">
                                <span>{{ $t('super_admin.support.labels.sla_due') }}</span>
                                <span>{{ formatAbsoluteDate(ticket.sla_due_at) }}</span>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-sm border border-stone-200 bg-white p-5 text-sm text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <h3 class="text-sm font-semibold text-stone-800 dark:text-neutral-100">
                            {{ $t('super_admin.support.sections.attachments') }}
                        </h3>
                        <div class="mt-3 space-y-2">
                            <div v-if="!ticket.media?.length" class="text-xs text-stone-500 dark:text-neutral-400">
                                {{ $t('super_admin.support.empty_states.attachments') }}
                            </div>
                            <div v-else class="grid gap-2">
                                <a v-for="media in ticket.media" :key="media.id" :href="media.url" target="_blank" rel="noopener"
                                    class="group flex items-center gap-3 rounded-sm border border-stone-200 bg-white px-2 py-2 text-xs text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800">
                                    <div class="flex h-12 w-12 items-center justify-center overflow-hidden rounded-sm bg-stone-100 text-stone-500 dark:bg-neutral-800 dark:text-neutral-300">
                                        <img v-if="isImage(media)" :src="media.url" :alt="attachmentLabel(media)" class="h-full w-full object-cover" loading="lazy" decoding="async" />
                                        <svg v-else class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                d="M19.5 14.25v3A2.25 2.25 0 0 1 17.25 19.5h-10.5A2.25 2.25 0 0 1 4.5 17.25v-10.5A2.25 2.25 0 0 1 6.75 4.5h3" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                d="M14.25 2.25h5.25v5.25" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                d="M19.5 2.25l-9.75 9.75" />
                                        </svg>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="truncate text-xs font-semibold text-stone-700 group-hover:text-stone-900 dark:text-neutral-200 dark:group-hover:text-white">
                                            {{ attachmentLabel(media) }}
                                        </div>
                                        <div class="text-[11px] text-stone-500 dark:text-neutral-400">
                                            {{ attachmentMeta(media) }}
                                        </div>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.message-body :deep(p) {
    margin-bottom: 0.5rem;
}

.message-body :deep(div) {
    margin-bottom: 0.5rem;
}

.message-body :deep(h2) {
    margin: 0.6rem 0 0.4rem;
    font-size: 0.95rem;
    font-weight: 600;
}

.message-body :deep(h3) {
    margin: 0.5rem 0 0.3rem;
    font-size: 0.85rem;
    font-weight: 600;
}

.message-body :deep(ul) {
    list-style: disc;
    padding-left: 1.25rem;
}

.message-body :deep(ol) {
    list-style: decimal;
    padding-left: 1.25rem;
}

.message-body :deep(blockquote) {
    border-left: 2px solid rgba(120, 113, 108, 0.3);
    padding-left: 0.75rem;
    color: inherit;
    opacity: 0.85;
}

.message-body :deep(pre) {
    margin-top: 0.5rem;
    border-radius: 0.125rem;
    background: rgba(120, 113, 108, 0.12);
    padding: 0.6rem;
    font-size: 0.75rem;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
    overflow-x: auto;
}

.message-body :deep(code) {
    border-radius: 0.125rem;
    background: rgba(120, 113, 108, 0.12);
    padding: 0.1rem 0.3rem;
    font-size: 0.75rem;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
}

.message-body :deep(hr) {
    margin: 0.75rem 0;
    border-color: rgba(120, 113, 108, 0.2);
}

.message-body :deep(img) {
    max-width: 100%;
    border-radius: 0.125rem;
}

.message-body :deep(a) {
    color: #16a34a;
    text-decoration: underline;
}
</style>
