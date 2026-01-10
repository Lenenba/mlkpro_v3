<script setup>
import { computed, ref, watch } from 'vue';
import draggable from 'vuedraggable';
import { prepareMediaFile, MEDIA_LIMITS } from '@/utils/media';
import { Link, router, useForm } from '@inertiajs/vue3';
import Modal from '@/Components/UI/Modal.vue';
import FloatingInput from '@/Components/FloatingInput.vue';
import FloatingNumberInput from '@/Components/FloatingNumberInput.vue';
import FloatingSelect from '@/Components/FloatingSelect.vue';
import FloatingTextarea from '@/Components/FloatingTextarea.vue';
import DatePicker from '@/Components/DatePicker.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import { humanizeDate } from '@/utils/date';

const props = defineProps({
    tasks: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    statuses: {
        type: Array,
        default: () => [],
    },
    teamMembers: {
        type: Array,
        default: () => [],
    },
    works: {
        type: Array,
        default: () => [],
    },
    materialProducts: {
        type: Array,
        default: () => [],
    },
    count: {
        type: Number,
        default: null,
    },
    canCreate: {
        type: Boolean,
        default: false,
    },
    canManage: {
        type: Boolean,
        default: false,
    },
    canDelete: {
        type: Boolean,
        default: false,
    },
    canEditStatus: {
        type: Boolean,
        default: false,
    },
});

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
});
const isLoading = ref(false);
const taskList = computed(() => Array.isArray(props.tasks?.data) ? props.tasks.data : []);
const allowedViews = ['board', 'schedule'];
const initialView = allowedViews.includes(props.filters?.view) ? props.filters.view : 'board';
const viewMode = ref(initialView);
const scheduleRangeOptions = [
    { id: 'week', label: 'This week' },
    { id: '2weeks', label: 'Next 2 weeks' },
    { id: 'month', label: 'This month' },
    { id: 'all', label: 'All tasks' },
];
const allowedScheduleRanges = scheduleRangeOptions.map((option) => option.id);
const scheduleRange = ref('week');

if (typeof window !== 'undefined') {
    const storedView = window.localStorage.getItem('task_view_mode');
    if (allowedViews.includes(storedView)) {
        viewMode.value = storedView;
    }
    const storedRange = window.localStorage.getItem('task_schedule_range');
    if (allowedScheduleRanges.includes(storedRange)) {
        scheduleRange.value = storedRange;
    }
}

const setViewMode = (mode) => {
    if (!allowedViews.includes(mode) || viewMode.value === mode) {
        return;
    }
    viewMode.value = mode;
    if (typeof window !== 'undefined') {
        window.localStorage.setItem('task_view_mode', mode);
    }
    isLoading.value = true;
    autoFilter();
};

const setScheduleRange = (range) => {
    if (!allowedScheduleRanges.includes(range)) {
        return;
    }
    if (scheduleRange.value !== range) {
        scheduleRange.value = range;
    }
    if (typeof window !== 'undefined') {
        window.localStorage.setItem('task_schedule_range', range);
    }
    clearSelectedDate();
};

const boardStatuses = computed(() =>
    props.statuses?.length ? props.statuses : ['todo', 'in_progress', 'done']
);

const boardTasks = ref({});

const toDateKey = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

const normalizeDateKey = (value) => {
    if (!value) {
        return null;
    }
    if (typeof value === 'string') {
        if (value.includes('T')) {
            return value.split('T')[0];
        }
        if (value.length >= 10) {
            return value.slice(0, 10);
        }
    }
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return null;
    }
    return toDateKey(parsed);
};

const formatScheduleLabel = (dateKey) => {
    if (!dateKey) {
        return '';
    }
    const date = new Date(`${dateKey}T00:00:00`);
    if (Number.isNaN(date.getTime())) {
        return dateKey;
    }
    return date.toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    });
};

const syncBoardTasks = () => {
    const grouped = {};
    const incomingMap = {};
    const fallbackStatus = boardStatuses.value[0] || 'todo';

    boardStatuses.value.forEach((status) => {
        grouped[status] = [];
        incomingMap[status] = new Map();
    });

    taskList.value.forEach((task) => {
        const status = boardStatuses.value.includes(task?.status) ? task.status : fallbackStatus;
        if (!incomingMap[status]) {
            return;
        }
        incomingMap[status].set(task.id, task);
    });

    boardStatuses.value.forEach((status) => {
        const ordered = [];
        const existing = boardTasks.value?.[status] || [];

        existing.forEach((task) => {
            const match = incomingMap[status].get(task.id);
            if (match) {
                ordered.push(match);
                incomingMap[status].delete(task.id);
            }
        });

        incomingMap[status].forEach((task) => {
            ordered.push(task);
        });

        grouped[status] = ordered;
    });

    boardTasks.value = grouped;
};

syncBoardTasks();

const startOfWeek = (date) => {
    const base = new Date(date);
    const day = (base.getDay() + 6) % 7;
    base.setDate(base.getDate() - day);
    base.setHours(0, 0, 0, 0);
    return base;
};

const scheduleRangeBounds = computed(() => {
    if (scheduleRange.value === 'all') {
        return null;
    }
    const today = new Date();
    const start = startOfWeek(today);
    let end = new Date(start);

    if (scheduleRange.value === 'week') {
        end.setDate(start.getDate() + 6);
    } else if (scheduleRange.value === '2weeks') {
        end.setDate(start.getDate() + 13);
    } else if (scheduleRange.value === 'month') {
        end = new Date(today.getFullYear(), today.getMonth() + 1, 0);
    }

    end.setHours(23, 59, 59, 999);
    return { start, end };
});

const isWithinScheduleRange = (dateKey) => {
    const bounds = scheduleRangeBounds.value;
    if (!bounds || !dateKey) {
        return Boolean(dateKey);
    }
    const date = new Date(`${dateKey}T00:00:00`);
    if (Number.isNaN(date.getTime())) {
        return false;
    }
    return date >= bounds.start && date <= bounds.end;
};

const scheduleGroups = computed(() => {
    const dated = new Map();
    const undated = [];
    const includeUndated = scheduleRange.value === 'all';

    taskList.value.forEach((task) => {
        const key = normalizeDateKey(task?.due_date);
        if (!key) {
            if (includeUndated) {
                undated.push(task);
            }
            return;
        }
        if (!isWithinScheduleRange(key)) {
            return;
        }
        if (!dated.has(key)) {
            dated.set(key, []);
        }
        dated.get(key).push(task);
    });

    const ordered = Array.from(dated.entries()).sort((a, b) => a[0].localeCompare(b[0]));
    return {
        dated: ordered.map(([key, items]) => ({ key, label: formatScheduleLabel(key), items })),
        undated,
    };
});

const selectedDateKey = ref(null);

const toggleSelectedDate = (dateKey) => {
    if (!dateKey) {
        return;
    }
    selectedDateKey.value = selectedDateKey.value === dateKey ? null : dateKey;
};

const clearSelectedDate = () => {
    selectedDateKey.value = null;
};

const selectedDateLabel = computed(() =>
    selectedDateKey.value ? formatScheduleLabel(selectedDateKey.value) : 'All dates'
);

const visibleScheduleGroups = computed(() => {
    if (!selectedDateKey.value) {
        return scheduleGroups.value;
    }
    const match = scheduleGroups.value.dated.find((group) => group.key === selectedDateKey.value);
    return {
        dated: match ? [match] : [],
        undated: [],
    };
});

const taskCountByDate = computed(() => {
    const map = new Map();
    taskList.value.forEach((task) => {
        const key = normalizeDateKey(task?.due_date);
        if (!key) {
            return;
        }
        if (!isWithinScheduleRange(key)) {
            return;
        }
        map.set(key, (map.get(key) || 0) + 1);
    });
    return map;
});

const calendarCursor = ref(new Date());
const weekDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const calendarLabel = computed(() =>
    calendarCursor.value.toLocaleDateString(undefined, {
        month: 'long',
        year: 'numeric',
    })
);

const moveCalendar = (offset) => {
    const next = new Date(calendarCursor.value);
    next.setDate(1);
    next.setMonth(next.getMonth() + offset);
    calendarCursor.value = next;
};

const calendarDays = computed(() => {
    const cursor = calendarCursor.value;
    const year = cursor.getFullYear();
    const month = cursor.getMonth();
    const startOfMonth = new Date(year, month, 1);
    const startOffset = (startOfMonth.getDay() + 6) % 7;
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysInPrevMonth = new Date(year, month, 0).getDate();
    const todayKey = toDateKey(new Date());
    const days = [];

    for (let i = 0; i < 42; i += 1) {
        const dayOffset = i - startOffset + 1;
        let date;
        let isCurrentMonth = true;

        if (dayOffset <= 0) {
            date = new Date(year, month - 1, daysInPrevMonth + dayOffset);
            isCurrentMonth = false;
        } else if (dayOffset > daysInMonth) {
            date = new Date(year, month + 1, dayOffset - daysInMonth);
            isCurrentMonth = false;
        } else {
            date = new Date(year, month, dayOffset);
        }

        const key = toDateKey(date);
        days.push({
            key,
            label: date.getDate(),
            isCurrentMonth,
            isToday: key === todayKey,
            count: taskCountByDate.value.get(key) || 0,
            isInRange: scheduleRange.value === 'all' ? true : isWithinScheduleRange(key),
        });
    }

    return days;
});

const filterPayload = () => {
    const payload = {
        search: filterForm.search,
        status: filterForm.status,
        view: viewMode.value,
    };

    Object.keys(payload).forEach((key) => {
        const value = payload[key];
        if (value === '' || value === null || value === undefined) {
            delete payload[key];
        }
    });

    return payload;
};

let filterTimeout;
const autoFilter = () => {
    if (filterTimeout) {
        clearTimeout(filterTimeout);
    }
    filterTimeout = setTimeout(() => {
        isLoading.value = true;
        router.get(route('task.index'), filterPayload(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                isLoading.value = false;
            },
        });
    }, 300);
};

watch(() => filterForm.search, autoFilter);
watch(() => filterForm.status, autoFilter);
watch([taskList, boardStatuses], syncBoardTasks, { immediate: true });
watch(taskList, (value) => {
    if (!selectedDateKey.value) {
        return;
    }
    const hasMatch = value.some((task) => normalizeDateKey(task?.due_date) === selectedDateKey.value);
    if (!hasMatch) {
        selectedDateKey.value = null;
    }
});

if (typeof window !== 'undefined' && allowedViews.includes(viewMode.value) && props.filters?.view !== viewMode.value) {
    autoFilter();
}

const clearFilters = () => {
    filterForm.search = '';
    filterForm.status = '';
    clearSelectedDate();
    autoFilter();
};

const statusLabel = (status) => {
    if (!status) {
        return '';
    }
    return String(status).replace('_', ' ');
};

const statusClasses = (status) => {
    switch (status) {
        case 'todo':
            return 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300';
        case 'in_progress':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-500/10 dark:text-blue-400';
        case 'done':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400';
        default:
            return 'bg-stone-200 text-stone-700 dark:bg-neutral-700 dark:text-neutral-300';
    }
};

const formatDate = (value) => humanizeDate(value) || String(value || '');

const canChangeStatus = computed(() => props.canManage || props.canEditStatus);
const dragHandle = computed(() => (canChangeStatus.value ? '.task-drag-handle' : null));

const workLabel = (work) => {
    if (!work) {
        return '';
    }
    const title = work.job_title || work.number || `Job #${work.id}`;
    const customerName = work.customer?.company_name
        || `${work.customer?.first_name || ''} ${work.customer?.last_name || ''}`.trim();
    return customerName ? `${title} - ${customerName}` : title;
};

const workOptions = computed(() =>
    (props.works || []).map((work) => ({
        id: work.id,
        name: workLabel(work),
    }))
);

const materialOptions = computed(() => [
    { id: '', name: 'Custom' },
    ...props.materialProducts.map((product) => ({
        id: product.id,
        name: product.name,
    })),
]);

const materialProductMap = computed(() => {
    const map = new Map();
    props.materialProducts.forEach((product) => {
        map.set(product.id, product);
    });
    return map;
});

const buildMaterial = (material = {}, index = 0) => ({
    id: material.id ?? null,
    product_id: material.product_id ?? '',
    label: material.label ?? '',
    description: material.description ?? '',
    unit: material.unit ?? '',
    quantity: material.quantity ?? 1,
    unit_price: material.unit_price ?? 0,
    billable: material.billable ?? true,
    sort_order: material.sort_order ?? index,
    source_service_id: material.source_service_id ?? null,
});

const mapTaskMaterials = (materials = []) =>
    materials.map((material, index) => buildMaterial(material, index));

const addMaterial = (form) => {
    form.materials.push(buildMaterial({}, form.materials.length));
};

const removeMaterial = (form, index) => {
    form.materials.splice(index, 1);
};

const applyMaterialDefaults = (material) => {
    if (!material.product_id) {
        return;
    }
    const product = materialProductMap.value.get(Number(material.product_id));
    if (!product) {
        return;
    }
    if (!material.label) {
        material.label = product.name;
    }
    if (!material.unit) {
        material.unit = product.unit || '';
    }
    if (!material.unit_price) {
        material.unit_price = product.price || 0;
    }
};

const normalizeMaterials = (materials) =>
    materials
        .map((material, index) => ({
            ...material,
            product_id: material.product_id || null,
            sort_order: index,
        }))
        .filter((material) => material.label || material.product_id);

const isTaskLocked = (task) => task?.status === 'done';

const createForm = useForm({
    work_id: '',
    standalone: false,
    title: '',
    description: '',
    status: 'todo',
    due_date: '',
    assigned_team_member_id: '',
    materials: [],
});

const normalizeWorkSelection = (form) => {
    if (form.standalone) {
        form.work_id = null;
    }

    if (!form.work_id) {
        form.work_id = null;
    }
};

const dispatchDemoEvent = (eventName, detail = {}) => {
    if (typeof window === 'undefined') {
        return;
    }
    window.dispatchEvent(new CustomEvent(eventName, { detail }));
};

watch(
    () => createForm.standalone,
    (value) => {
        if (value) {
            createForm.work_id = '';
        }
    }
);

watch(
    () => createForm.work_id,
    (value) => {
        if (value) {
            createForm.standalone = false;
        }
    }
);

const closeOverlay = (overlayId) => {
    if (window.HSOverlay) {
        window.HSOverlay.close(overlayId);
    }
};

const submitCreate = () => {
    if (createForm.processing) {
        return;
    }

    createForm.materials = normalizeMaterials(createForm.materials);
    normalizeWorkSelection(createForm);
    const assignedId = createForm.assigned_team_member_id;

    createForm.post(route('task.store'), {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset('work_id', 'standalone', 'title', 'description', 'due_date', 'assigned_team_member_id');
            createForm.status = 'todo';
            createForm.materials = [];
            closeOverlay('#hs-task-create');
            if (assignedId) {
                dispatchDemoEvent('demo:task_assigned', { assigned_team_member_id: assignedId });
            }
        },
    });
};

const editingTaskId = ref(null);
const editForm = useForm({
    work_id: '',
    standalone: false,
    title: '',
    description: '',
    status: 'todo',
    due_date: '',
    assigned_team_member_id: '',
    customer_id: null,
    product_id: null,
    materials: [],
});

watch(
    () => editForm.standalone,
    (value) => {
        if (value) {
            editForm.work_id = '';
        }
    }
);

watch(
    () => editForm.work_id,
    (value) => {
        if (value) {
            editForm.standalone = false;
        }
    }
);

const openEditTask = (task) => {
    if (!props.canManage) {
        return;
    }
    if (isTaskLocked(task)) {
        return;
    }

    editingTaskId.value = task.id;
    editForm.clearErrors();

    editForm.title = task.title || '';
    editForm.description = task.description || '';
    editForm.status = task.status || 'todo';
    editForm.due_date = task.due_date || '';
    editForm.assigned_team_member_id = task.assigned_team_member_id || '';
    editForm.work_id = task.work_id ?? '';
    editForm.standalone = !task.work_id;
    editForm.customer_id = task.customer_id ?? null;
    editForm.product_id = task.product_id ?? null;
    editForm.materials = mapTaskMaterials(task.materials || []);

    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-task-edit');
    }
};

const submitEdit = () => {
    if (!editingTaskId.value || editForm.processing) {
        return;
    }

    editForm.materials = normalizeMaterials(editForm.materials);
    normalizeWorkSelection(editForm);

    editForm.put(route('task.update', editingTaskId.value), {
        preserveScroll: true,
        onSuccess: () => {
            closeOverlay('#hs-task-edit');
        },
    });
};

const setTaskStatus = (task, status) => {
    if (!canChangeStatus.value || task.status === status || isTaskLocked(task)) {
        return;
    }
    const onSuccess = () => {
        if (status === 'done') {
            dispatchDemoEvent('demo:task_completed', { task_id: task.id });
        }
    };

    if (props.canManage) {
        router.put(
            route('task.update', task.id),
            {
                title: task.title || '',
                description: task.description || '',
                status,
                due_date: task.due_date || null,
                assigned_team_member_id: task.assigned_team_member_id ?? null,
                work_id: task.work_id ?? null,
                standalone: !task.work_id,
                customer_id: task.customer_id ?? null,
                product_id: task.product_id ?? null,
            },
            { preserveScroll: true, only: ['tasks', 'flash'], onSuccess }
        );
        return;
    }

    router.put(
        route('task.update', task.id),
        { status },
        { preserveScroll: true, only: ['tasks', 'flash'], onSuccess }
    );
};

const deleteTask = (task) => {
    if (!props.canDelete) {
        return;
    }
    if (!confirm(`Delete "${task.title}"?`)) {
        return;
    }

    router.delete(route('task.destroy', task.id), { preserveScroll: true });
};

const displayAssignee = (task) => task?.assignee?.user?.name || '-';

const dragInProgress = ref(false);
const lastDragAt = ref(0);
const detailsTask = ref(null);

const handleBoardStart = () => {
    dragInProgress.value = true;
};

const handleBoardEnd = () => {
    lastDragAt.value = Date.now();
    dragInProgress.value = false;
};

const handleBoardMove = (event) => {
    if (!canChangeStatus.value) {
        return false;
    }
    const task = event?.draggedContext?.element;
    return !isTaskLocked(task);
};

const handleBoardChange = (status, event) => {
    if (!event?.added?.element) {
        return;
    }
    const task = event.added.element;
    if (!task || task.status === status) {
        return;
    }
    task.status = status;
    if (canChangeStatus.value && !isTaskLocked(task)) {
        setTaskStatus(task, status);
    }
};

const openTaskDetails = (task) => {
    if (dragInProgress.value || Date.now() - lastDragAt.value < 200) {
        return;
    }
    detailsTask.value = task;
    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-task-details');
    }
};

const proofTaskId = ref(null);
const proofForm = useForm({
    type: 'execution',
    file: null,
    note: '',
});

const openProofUpload = (task) => {
    if (!canChangeStatus.value) {
        return;
    }

    proofTaskId.value = task.id;
    proofForm.reset();
    proofForm.clearErrors();

    if (window.HSOverlay) {
        window.HSOverlay.open('#hs-task-proof');
    }
};

const handleProofFile = async (event) => {
    const file = event.target.files?.[0] || null;
    proofForm.clearErrors('file');
    if (!file) {
        proofForm.file = null;
        return;
    }
    const result = await prepareMediaFile(file, {
        maxImageBytes: MEDIA_LIMITS.maxImageBytes,
        maxVideoBytes: MEDIA_LIMITS.maxVideoBytes,
    });
    if (result.error) {
        proofForm.setError('file', result.error);
        proofForm.file = null;
        return;
    }
    proofForm.file = result.file;
};

const submitProof = () => {
    if (!proofTaskId.value || proofForm.processing) {
        return;
    }

    proofForm.post(route('task.media.store', proofTaskId.value), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            closeOverlay('#hs-task-proof');
        },
    });
};
</script>

<template>
    <div
        class="p-5 space-y-4 flex flex-col border-t-4 border-t-zinc-600 bg-white border border-stone-200 shadow-sm rounded-sm dark:bg-neutral-800 dark:border-neutral-700">
        <div class="space-y-3">
            <div class="flex flex-col lg:flex-row lg:items-center gap-2">
                <div class="flex-1">
                    <div class="relative">
                        <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none z-20 ps-3.5">
                            <svg class="shrink-0 size-4 text-stone-500 dark:text-neutral-400"
                                xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" />
                                <path d="m21 21-4.3-4.3" />
                            </svg>
                        </div>
                        <input type="text" v-model="filterForm.search" data-testid="demo-task-search"
                            class="py-[7px] ps-10 pe-8 block w-full bg-white border border-stone-200 rounded-sm text-sm placeholder:text-stone-500 focus:border-green-500 focus:ring-green-600 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:placeholder:text-neutral-400 dark:focus:ring-neutral-600"
                            placeholder="Search tasks">
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 justify-end">
                    <div class="inline-flex items-center rounded-sm border border-stone-200 bg-white p-0.5 text-xs font-semibold text-stone-600 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300">
                        <button
                            type="button"
                            @click="setViewMode('board')"
                            class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5"
                            :class="viewMode === 'board'
                                ? 'bg-green-600 text-white shadow-sm dark:bg-white dark:text-stone-900'
                                : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
                        >
                            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="7" height="7" rx="1" />
                                <rect x="14" y="3" width="7" height="7" rx="1" />
                                <rect x="14" y="14" width="7" height="7" rx="1" />
                                <rect x="3" y="14" width="7" height="7" rx="1" />
                            </svg>
                            Board
                        </button>
                        <button
                            type="button"
                            @click="setViewMode('schedule')"
                            class="inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5"
                            :class="viewMode === 'schedule'
                                ? 'bg-green-600 text-white shadow-sm dark:bg-white dark:text-stone-900'
                                : 'text-stone-600 hover:text-stone-800 dark:text-neutral-300 dark:hover:text-neutral-100'"
                        >
                            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" />
                                <path d="M8 2v4" />
                                <path d="M16 2v4" />
                                <path d="M3 10h18" />
                            </svg>
                            Schedule
                        </button>
                    </div>
                    <select v-model="filterForm.status"
                        class="py-2 ps-3 pe-8 bg-white border border-stone-200 rounded-sm text-sm text-stone-700 focus:border-green-500 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:focus:ring-neutral-600">
                        <option value="">All statuses</option>
                        <option v-for="status in statuses" :key="status" :value="status">
                            {{ statusLabel(status) }}
                        </option>
                    </select>

                    <button type="button" @click="clearFilters"
                        class="py-2 px-3 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-700 action-feedback">
                        Clear
                    </button>

                    <button v-if="canCreate" type="button" data-hs-overlay="#hs-task-create" data-testid="demo-task-add"
                        class="py-2 px-2.5 inline-flex items-center gap-x-1.5 text-xs font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:ring-2 focus:ring-green-500 action-feedback">
                        + Add task
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="viewMode === 'board'"
            class="overflow-x-auto"
        >
            <div v-if="isLoading" class="min-w-[720px] grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="status in boardStatuses"
                    :key="`skeleton-${status}`"
                    class="flex flex-col rounded-sm border border-stone-200 bg-stone-50 shadow-sm dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div class="flex items-center justify-between border-b border-stone-200 px-3 py-2 dark:border-neutral-700">
                        <span class="text-xs font-semibold uppercase text-stone-400 dark:text-neutral-500">
                            {{ statusLabel(status) || status }}
                        </span>
                        <div class="h-4 w-8 rounded-sm bg-stone-200 dark:bg-neutral-800"></div>
                    </div>
                    <div class="space-y-2 p-3 animate-pulse">
                        <div v-for="row in 3" :key="`skeleton-${status}-${row}`"
                            class="rounded-sm border border-stone-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-800">
                            <div class="flex items-start gap-2">
                                <div class="size-6 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="flex-1 space-y-2">
                                    <div class="h-3 w-3/4 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    <div class="h-3 w-1/2 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <div class="h-4 w-14 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="h-4 w-20 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                                <div class="h-4 w-16 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else class="min-w-[720px] grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="status in boardStatuses"
                    :key="status"
                    class="flex flex-col rounded-sm border border-stone-200 bg-stone-50 shadow-sm transition-colors duration-150 dark:border-neutral-700 dark:bg-neutral-900"
                >
                    <div class="flex items-center justify-between border-b border-stone-200 px-3 py-2 dark:border-neutral-700">
                        <span class="text-xs font-semibold uppercase text-stone-500 dark:text-neutral-400">
                            {{ statusLabel(status) || status }}
                        </span>
                        <span class="rounded-full bg-stone-200 px-2 py-0.5 text-[11px] font-semibold text-stone-600 dark:bg-neutral-800 dark:text-neutral-300">
                            {{ boardTasks[status]?.length || 0 }}
                        </span>
                    </div>
                    <draggable
                        :list="boardTasks[status]"
                        item-key="id"
                        group="task-board"
                        :animation="180"
                        ghost-class="task-drag-ghost"
                        chosen-class="task-drag-chosen"
                        drag-class="task-drag-dragging"
                        :empty-insert-threshold="36"
                        :scroll="true"
                        :scroll-sensitivity="80"
                        :scroll-speed="12"
                        :bubble-scroll="true"
                        :disabled="!canChangeStatus"
                        :move="handleBoardMove"
                        :handle="dragHandle"
                        class="flex-1 space-y-2 p-3 min-h-[140px]"
                        @start="handleBoardStart"
                        @end="handleBoardEnd"
                        @change="handleBoardChange(status, $event)"
                    >
                        <template #item="{ element: task }">
                            <div
                                class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm transition-shadow transition-transform duration-150 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-800 select-none"
                                :class="[
                                    isTaskLocked(task) ? 'opacity-70' : '',
                                    'cursor-pointer',
                                ]"
                                role="button"
                                tabindex="0"
                                @click="openTaskDetails(task)"
                                @keydown.enter.prevent="openTaskDetails(task)"
                            >
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-start gap-2 min-w-0">
                                    <span
                                        v-if="canChangeStatus && !isTaskLocked(task)"
                                        class="task-drag-handle mt-0.5 inline-flex size-6 items-center justify-center rounded-sm border border-stone-200 bg-stone-50 text-stone-400 hover:text-stone-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-500 cursor-grab active:cursor-grabbing"
                                        @click.stop
                                    >
                                        <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                            <circle cx="7" cy="5" r="1.5" />
                                            <circle cx="7" cy="12" r="1.5" />
                                            <circle cx="7" cy="19" r="1.5" />
                                            <circle cx="17" cy="5" r="1.5" />
                                            <circle cx="17" cy="12" r="1.5" />
                                            <circle cx="17" cy="19" r="1.5" />
                                        </svg>
                                    </span>
                                    <div class="min-w-0">
                                        <button
                                            type="button"
                                            class="text-left text-sm font-semibold text-stone-800 hover:text-emerald-700 dark:text-neutral-100 dark:hover:text-emerald-300 line-clamp-2"
                                        >
                                            {{ task.title }}
                                        </button>
                                        <p
                                            v-if="task.description"
                                            class="mt-1 text-xs text-stone-500 dark:text-neutral-400 line-clamp-2"
                                        >
                                            {{ task.description }}
                                        </p>
                                    </div>
                                </div>
                                <div
                                    class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex"
                                    @click.stop
                                >
                                    <button type="button"
                                        class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                        aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                            height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="1" />
                                            <circle cx="12" cy="5" r="1" />
                                            <circle cx="12" cy="19" r="1" />
                                        </svg>
                                    </button>

                                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-44 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                        role="menu" aria-orientation="vertical">
                                        <div class="p-1">
                                            <div class="px-2 py-1 text-[11px] uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                                                Set status
                                            </div>
                                            <button type="button" :disabled="!canChangeStatus || task.status === 'todo' || isTaskLocked(task)"
                                                @click="setTaskStatus(task, 'todo')"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                To do
                                            </button>
                                            <button type="button" :disabled="!canChangeStatus || task.status === 'in_progress' || isTaskLocked(task)"
                                                @click="setTaskStatus(task, 'in_progress')"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                In progress
                                            </button>
                                            <button type="button" :disabled="!canChangeStatus || task.status === 'done' || isTaskLocked(task)" data-testid="demo-task-mark-done"
                                                @click="setTaskStatus(task, 'done')"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                Done
                                            </button>

                                            <template v-if="canManage || canDelete">
                                                <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                            </template>

                                            <button v-if="canManage" type="button" @click="openEditTask(task)"
                                                :disabled="isTaskLocked(task)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                Edit
                                            </button>
                                            <button v-if="canChangeStatus" type="button" @click="openProofUpload(task)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                Add proof
                                            </button>
                                            <button v-if="canDelete" type="button" @click="deleteTask(task)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback" data-tone="danger">
                                                Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center gap-2 text-[11px] text-stone-500 dark:text-neutral-400">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 font-semibold"
                                    :class="statusClasses(task.status)">
                                    {{ statusLabel(task.status) || 'todo' }}
                                </span>
                                <span class="inline-flex items-center gap-1">
                                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <rect x="3" y="4" width="18" height="18" rx="2" />
                                        <path d="M8 2v4" />
                                        <path d="M16 2v4" />
                                        <path d="M3 10h18" />
                                    </svg>
                                    {{ formatDate(task.due_date) || 'No due date' }}
                                </span>
                                <span class="inline-flex items-center gap-1">
                                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                        <circle cx="12" cy="7" r="4" />
                                    </svg>
                                    {{ displayAssignee(task) }}
                                </span>
                            </div>
                        </div>
                    </template>
                    <template #footer>
                        <div
                            v-if="!boardTasks[status]?.length"
                            class="rounded-sm border border-dashed border-stone-200 bg-white px-3 py-6 text-center text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400"
                        >
                            No tasks
                        </div>
                    </template>
                </draggable>
            </div>
        </div>
    </div>

    <div
        v-else-if="viewMode === 'schedule'"
        class="grid gap-4 lg:grid-cols-[2fr_1fr]"
    >
        <template v-if="isLoading">
            <div class="space-y-3">
                <div class="rounded-sm border border-stone-200 bg-white px-3 py-3 dark:border-neutral-700 dark:bg-neutral-800">
                    <div class="flex items-center justify-between animate-pulse">
                        <div class="h-4 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                        <div class="h-6 w-32 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                    </div>
                </div>
                <div class="space-y-2 animate-pulse">
                    <div v-for="row in 4" :key="`schedule-skeleton-${row}`"
                        class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                        <div class="h-3 w-1/2 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                        <div class="mt-2 h-3 w-2/3 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <div class="h-4 w-16 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-4 w-20 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                            <div class="h-4 w-14 rounded-full bg-stone-200 dark:bg-neutral-700"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-center justify-between animate-pulse">
                    <div class="size-8 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                    <div class="h-4 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                    <div class="size-8 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                </div>
                <div class="mt-4 grid grid-cols-7 gap-2 animate-pulse">
                    <div v-for="day in 14" :key="`calendar-skeleton-${day}`"
                        class="h-6 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                </div>
            </div>
        </template>
        <template v-else>
            <div class="space-y-4">
                <div v-if="taskList.length" class="flex flex-wrap items-center justify-between gap-2 rounded-sm border border-stone-200 bg-white px-3 py-2 text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                    <div class="flex items-center gap-2">
                        <span class="font-semibold text-stone-700 dark:text-neutral-200">Schedule</span>
                        <span class="rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-semibold text-stone-600 dark:bg-neutral-700 dark:text-neutral-200">
                            {{ selectedDateLabel }}
                        </span>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="text-[11px] uppercase text-stone-400 dark:text-neutral-500">Range</label>
                        <select
                            v-model="scheduleRange"
                            @change="setScheduleRange(scheduleRange)"
                            class="rounded-sm border border-stone-200 bg-white px-2 py-1 text-xs text-stone-700 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        >
                            <option v-for="option in scheduleRangeOptions" :key="option.id" :value="option.id">
                                {{ option.label }}
                            </option>
                        </select>
                        <button
                            v-if="selectedDateKey"
                            type="button"
                            @click="clearSelectedDate"
                            class="inline-flex items-center gap-1 text-emerald-600 hover:text-emerald-700"
                        >
                            Show all
                        </button>
                    </div>
                </div>
                <div v-if="!taskList.length" class="rounded-sm border border-dashed border-stone-200 bg-white px-3 py-6 text-center text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                    No tasks yet.
                </div>
                <div
                    v-else-if="!visibleScheduleGroups.dated.length && !visibleScheduleGroups.undated.length && !selectedDateKey"
                    class="rounded-sm border border-dashed border-stone-200 bg-white px-3 py-6 text-center text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400"
                >
                    No tasks in this range.
                </div>
                <div
                    v-if="taskList.length && selectedDateKey && !visibleScheduleGroups.dated.length"
                    class="rounded-sm border border-dashed border-stone-200 bg-white px-3 py-6 text-center text-xs text-stone-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400"
                >
                    No tasks scheduled for {{ selectedDateLabel }}.
                </div>
                <div v-for="group in visibleScheduleGroups.dated" :key="group.key" class="space-y-2">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
                            {{ group.label }}
                        </h3>
                        <span class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ group.items.length }} tasks
                        </span>
                    </div>
                    <div class="space-y-2">
                        <div
                            v-for="task in group.items"
                            :key="task.id"
                            class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-800"
                            :class="[
                                isTaskLocked(task) ? 'opacity-70' : '',
                                'cursor-pointer',
                            ]"
                            role="button"
                            tabindex="0"
                            @click="openTaskDetails(task)"
                            @keydown.enter.prevent="openTaskDetails(task)"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <button
                                        type="button"
                                        class="text-left text-sm font-semibold text-stone-800 hover:text-emerald-700 dark:text-neutral-100 dark:hover:text-emerald-300 line-clamp-2"
                                    >
                                        {{ task.title }}
                                    </button>
                                    <p v-if="task.description" class="mt-1 text-xs text-stone-500 dark:text-neutral-400 line-clamp-2">
                                        {{ task.description }}
                                    </p>
                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px] text-stone-500 dark:text-neutral-400">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 font-semibold"
                                            :class="statusClasses(task.status)">
                                            {{ statusLabel(task.status) || 'todo' }}
                                        </span>
                                        <span class="inline-flex items-center gap-1">
                                            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round">
                                                <rect x="3" y="4" width="18" height="18" rx="2" />
                                                <path d="M8 2v4" />
                                                <path d="M16 2v4" />
                                                <path d="M3 10h18" />
                                            </svg>
                                            {{ formatDate(task.due_date) || 'No due date' }}
                                        </span>
                                        <span class="inline-flex items-center gap-1">
                                            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round">
                                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                                <circle cx="12" cy="7" r="4" />
                                            </svg>
                                            {{ displayAssignee(task) }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-[11px] text-stone-400 dark:text-neutral-500">
                                        {{ formatDate(task.created_at) }}
                                    </span>
                                    <div
                                        class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex"
                                        @click.stop
                                    >
                                        <button type="button"
                                            class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                            <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="1" />
                                                <circle cx="12" cy="5" r="1" />
                                                <circle cx="12" cy="19" r="1" />
                                            </svg>
                                        </button>

                                        <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-44 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                            role="menu" aria-orientation="vertical">
                                            <div class="p-1">
                                                <div class="px-2 py-1 text-[11px] uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                                                    Set status
                                                </div>
                                                <button type="button" :disabled="!canChangeStatus || task.status === 'todo' || isTaskLocked(task)"
                                                    @click="setTaskStatus(task, 'todo')"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                    To do
                                                </button>
                                                <button type="button" :disabled="!canChangeStatus || task.status === 'in_progress' || isTaskLocked(task)"
                                                    @click="setTaskStatus(task, 'in_progress')"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                    In progress
                                                </button>
                                                <button type="button" :disabled="!canChangeStatus || task.status === 'done' || isTaskLocked(task)" data-testid="demo-task-mark-done"
                                                    @click="setTaskStatus(task, 'done')"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                    Done
                                                </button>

                                                <template v-if="canManage || canDelete">
                                                    <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                                </template>

                                                <button v-if="canManage" type="button" @click="openEditTask(task)"
                                                    :disabled="isTaskLocked(task)"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                    Edit
                                                </button>
                                                <button v-if="canChangeStatus" type="button" @click="openProofUpload(task)"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                    Add proof
                                                </button>
                                                <button v-if="canDelete" type="button" @click="deleteTask(task)"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback" data-tone="danger">
                                                    Delete
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-if="visibleScheduleGroups.undated.length" class="space-y-2">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
                            No due date
                        </h3>
                        <span class="text-xs text-stone-500 dark:text-neutral-400">
                            {{ visibleScheduleGroups.undated.length }} tasks
                        </span>
                    </div>
                    <div class="space-y-2">
                        <div
                            v-for="task in visibleScheduleGroups.undated"
                            :key="task.id"
                            class="rounded-sm border border-stone-200 bg-white p-3 shadow-sm dark:border-neutral-700 dark:bg-neutral-800"
                            :class="[
                                isTaskLocked(task) ? 'opacity-70' : '',
                                'cursor-pointer',
                            ]"
                            role="button"
                            tabindex="0"
                            @click="openTaskDetails(task)"
                            @keydown.enter.prevent="openTaskDetails(task)"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <button
                                        type="button"
                                        class="text-left text-sm font-semibold text-stone-800 hover:text-emerald-700 dark:text-neutral-100 dark:hover:text-emerald-300 line-clamp-2"
                                    >
                                        {{ task.title }}
                                    </button>
                                    <p v-if="task.description" class="mt-1 text-xs text-stone-500 dark:text-neutral-400 line-clamp-2">
                                        {{ task.description }}
                                    </p>
                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px] text-stone-500 dark:text-neutral-400">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 font-semibold"
                                            :class="statusClasses(task.status)">
                                            {{ statusLabel(task.status) || 'todo' }}
                                        </span>
                                        <span class="inline-flex items-center gap-1">
                                            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round">
                                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                                <circle cx="12" cy="7" r="4" />
                                            </svg>
                                            {{ displayAssignee(task) }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-[11px] text-stone-400 dark:text-neutral-500">
                                        {{ formatDate(task.created_at) }}
                                    </span>
                                    <div
                                        class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex"
                                        @click.stop
                                    >
                                        <button type="button"
                                            class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                            aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                            <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                                height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="1" />
                                                <circle cx="12" cy="5" r="1" />
                                                <circle cx="12" cy="19" r="1" />
                                            </svg>
                                        </button>

                                        <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-44 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                            role="menu" aria-orientation="vertical">
                                            <div class="p-1">
                                                <div class="px-2 py-1 text-[11px] uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                                                    Set status
                                                </div>
                                                <button type="button" :disabled="!canChangeStatus || task.status === 'todo' || isTaskLocked(task)"
                                                    @click="setTaskStatus(task, 'todo')"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                    To do
                                                </button>
                                                <button type="button" :disabled="!canChangeStatus || task.status === 'in_progress' || isTaskLocked(task)"
                                                    @click="setTaskStatus(task, 'in_progress')"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                    In progress
                                                </button>
                                                <button type="button" :disabled="!canChangeStatus || task.status === 'done' || isTaskLocked(task)" data-testid="demo-task-mark-done"
                                                    @click="setTaskStatus(task, 'done')"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                    Done
                                                </button>

                                                <template v-if="canManage || canDelete">
                                                    <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                                </template>

                                                <button v-if="canManage" type="button" @click="openEditTask(task)"
                                                    :disabled="isTaskLocked(task)"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                    Edit
                                                </button>
                                                <button v-if="canChangeStatus" type="button" @click="openProofUpload(task)"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                    Add proof
                                                </button>
                                                <button v-if="canDelete" type="button" @click="deleteTask(task)"
                                                    class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback" data-tone="danger">
                                                    Delete
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="rounded-sm border border-stone-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex items-center justify-between">
                    <button
                        type="button"
                        @click="moveCalendar(-1)"
                        class="size-8 inline-flex items-center justify-center rounded-sm border border-stone-200 text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    >
                        <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m15 18-6-6 6-6" />
                        </svg>
                    </button>
                    <div class="text-sm font-semibold text-stone-700 dark:text-neutral-200">
                        {{ calendarLabel }}
                    </div>
                    <button
                        type="button"
                        @click="moveCalendar(1)"
                        class="size-8 inline-flex items-center justify-center rounded-sm border border-stone-200 text-stone-600 hover:bg-stone-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    >
                        <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m9 18 6-6-6-6" />
                        </svg>
                    </button>
                </div>
                <div class="mt-3 grid grid-cols-7 text-[11px] uppercase text-stone-400 dark:text-neutral-500">
                    <span v-for="day in weekDays" :key="day" class="py-1 text-center">
                        {{ day }}
                    </span>
                </div>
                <div class="mt-2 grid grid-cols-7 gap-1">
                    <div v-for="day in calendarDays" :key="day.key" class="flex flex-col items-center gap-1">
                        <button
                            type="button"
                            @click="toggleSelectedDate(day.key)"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-medium transition"
                            :class="[
                                day.isCurrentMonth ? 'text-stone-700 dark:text-neutral-200' : 'text-stone-300 dark:text-neutral-600',
                                selectedDateKey === day.key
                                    ? 'bg-stone-900 text-white dark:bg-white dark:text-stone-900'
                                    : (day.isToday ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : ''),
                                day.isInRange ? '' : 'opacity-40 cursor-not-allowed',
                            ]"
                            :aria-pressed="selectedDateKey === day.key"
                            :title="day.count ? `${day.count} task${day.count === 1 ? '' : 's'}` : ''"
                            :disabled="!day.isInRange"
                        >
                            {{ day.label }}
                        </button>
                        <span v-if="day.count" class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                    </div>
                </div>
                <div class="mt-3 text-xs text-stone-500 dark:text-neutral-400">
                    <span class="inline-flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                        Tasks due
                    </span>
                </div>
            </div>
        </template>
    </div>

        <div v-else
            class="overflow-x-auto [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-track]:bg-stone-100 [&::-webkit-scrollbar-thumb]:bg-stone-300 dark:[&::-webkit-scrollbar-track]:bg-neutral-700 dark:[&::-webkit-scrollbar-thumb]:bg-neutral-500">
            <div class="min-w-full inline-block align-middle">
                <table class="min-w-full divide-y divide-stone-200 dark:divide-neutral-700">
                    <thead>
                        <tr>
                            <th scope="col" class="min-w-[260px]">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Task
                                </div>
                            </th>
                            <th scope="col" class="min-w-36">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Status
                                </div>
                            </th>
                            <th scope="col" class="min-w-32">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Due
                                </div>
                            </th>
                            <th scope="col" class="min-w-44">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Assignee
                                </div>
                            </th>
                            <th scope="col" class="min-w-32">
                                <div class="px-5 py-2.5 text-start text-sm font-normal text-stone-500 dark:text-neutral-500">
                                    Created
                                </div>
                            </th>
                            <th scope="col"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-stone-200 dark:divide-neutral-700">
                        <template v-if="isLoading">
                            <tr v-for="row in 6" :key="`skeleton-${row}`">
                                <td colspan="7" class="px-4 py-3">
                                    <div class="grid grid-cols-5 gap-4 animate-pulse">
                                        <div class="h-3 w-32 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-28 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-24 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-20 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                        <div class="h-3 w-16 rounded-sm bg-stone-200 dark:bg-neutral-700"></div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <template v-else>
                        <tr v-for="task in tasks.data" :key="task.id">
                            <td class="size-px whitespace-nowrap px-4 py-2 text-start">
                                <div class="flex flex-col">
                                    <Link
                                        :href="`/tasks/${task.id}`"
                                        class="text-sm font-medium text-stone-800 hover:text-emerald-700 dark:text-neutral-200 dark:hover:text-emerald-300"
                                    >
                                        {{ task.title }}
                                    </Link>
                                    <span v-if="task.description" class="text-xs text-stone-500 dark:text-neutral-500 line-clamp-1">
                                        {{ task.description }}
                                    </span>
                                </div>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="py-1.5 px-2 inline-flex items-center text-xs font-medium rounded-full"
                                    :class="statusClasses(task.status)">
                                    {{ statusLabel(task.status) || 'todo' }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ formatDate(task.due_date) || '-' }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-sm text-stone-600 dark:text-neutral-300">
                                    {{ displayAssignee(task) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2">
                                <span class="text-xs text-stone-500 dark:text-neutral-500">
                                    {{ formatDate(task.created_at) }}
                                </span>
                            </td>
                            <td class="size-px whitespace-nowrap px-4 py-2 text-end">
                                <div
                                    class="hs-dropdown [--auto-close:inside] [--placement:bottom-right] relative inline-flex">
                                    <button type="button"
                                        class="size-7 inline-flex justify-center items-center gap-x-2 rounded-sm border border-stone-200 bg-white text-stone-800 shadow-sm hover:bg-stone-50 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:focus:bg-neutral-700"
                                        aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24"
                                            height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="1" />
                                            <circle cx="12" cy="5" r="1" />
                                            <circle cx="12" cy="19" r="1" />
                                        </svg>
                                    </button>

                                    <div class="hs-dropdown-menu hs-dropdown-open:opacity-100 w-44 transition-[opacity,margin] duration opacity-0 hidden z-10 bg-white rounded-sm shadow-[0_10px_40px_10px_rgba(0,0,0,0.08)] dark:shadow-[0_10px_40px_10px_rgba(0,0,0,0.2)] dark:bg-neutral-900"
                                        role="menu" aria-orientation="vertical">
                                        <div class="p-1">
                                            <div class="px-2 py-1 text-[11px] uppercase tracking-wide text-stone-400 dark:text-neutral-500">
                                                Set status
                                            </div>
                                            <button type="button" :disabled="!canChangeStatus || task.status === 'todo' || isTaskLocked(task)"
                                                @click="setTaskStatus(task, 'todo')"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                To do
                                            </button>
                                            <button type="button" :disabled="!canChangeStatus || task.status === 'in_progress' || isTaskLocked(task)"
                                                @click="setTaskStatus(task, 'in_progress')"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                In progress
                                            </button>
                                            <button type="button" :disabled="!canChangeStatus || task.status === 'done' || isTaskLocked(task)" data-testid="demo-task-mark-done"
                                                @click="setTaskStatus(task, 'done')"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                Done
                                            </button>

                                            <template v-if="canManage || canDelete">
                                                <div class="my-1 border-t border-stone-200 dark:border-neutral-800"></div>
                                            </template>

                                            <button v-if="canManage" type="button" @click="openEditTask(task)"
                                                :disabled="isTaskLocked(task)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                Edit
                                            </button>
                                            <button v-if="canChangeStatus" type="button" @click="openProofUpload(task)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-stone-800 hover:bg-stone-100 dark:text-neutral-300 dark:hover:bg-neutral-800 action-feedback">
                                                Add proof
                                            </button>
                                            <button v-if="canDelete" type="button" @click="deleteTask(task)"
                                                class="w-full flex items-center gap-x-3 py-1.5 px-2 rounded-sm text-[13px] text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-neutral-800 action-feedback" data-tone="danger">
                                                Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="taskList.length > 0" class="mt-5 flex flex-wrap justify-between items-center gap-2">
            <p class="text-sm text-stone-800 dark:text-neutral-200">
                <span class="font-medium"> {{ count ?? taskList.length }} </span>
                <span class="text-stone-500 dark:text-neutral-500"> results</span>
            </p>

            <nav class="flex justify-end items-center gap-x-1" aria-label="Pagination">
                <Link :href="tasks.prev_page_url" v-if="tasks.prev_page_url">
                    <button type="button"
                        class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                        aria-label="Previous">
                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="m15 18-6-6 6-6" />
                        </svg>
                        <span class="sr-only">Previous</span>
                    </button>
                </Link>
                <div class="flex items-center gap-x-1">
                    <span
                        class="min-h-[38px] min-w-[38px] flex justify-center items-center bg-stone-100 text-stone-800 py-2 px-3 text-sm rounded-sm disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-700 dark:text-white"
                        aria-current="page">{{ tasks.from }}</span>
                    <span
                        class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">of</span>
                    <span class="min-h-[38px] flex justify-center items-center text-stone-500 py-2 px-1.5 text-sm dark:text-neutral-500">
                        {{ tasks.to }}
                    </span>
                </div>

                <Link :href="tasks.next_page_url" v-if="tasks.next_page_url">
                    <button type="button"
                        class="min-h-[38px] min-w-[38px] py-2 px-2.5 inline-flex justify-center items-center gap-x-2 text-sm rounded-sm text-stone-800 hover:bg-stone-100 disabled:opacity-50 disabled:pointer-events-none focus:outline-none focus:bg-stone-100 dark:text-white dark:hover:bg-white/10 dark:focus:bg-neutral-700"
                        aria-label="Next">
                        <span class="sr-only">Next</span>
                        <svg class="shrink-0 size-3.5" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="m9 18 6-6-6-6" />
                        </svg>
                    </button>
                </Link>
            </nav>
        </div>
    </div>

    <Modal v-if="detailsTask" :title="detailsTask.title || 'Task details'" :id="'hs-task-details'">
        <div class="space-y-4 text-sm text-stone-700 dark:text-neutral-200">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs uppercase text-stone-400 dark:text-neutral-500">Status</span>
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
                    :class="statusClasses(detailsTask.status)">
                    {{ statusLabel(detailsTask.status) || 'todo' }}
                </span>
                <span class="text-xs text-stone-500 dark:text-neutral-400">
                    Due {{ formatDate(detailsTask.due_date) || '-' }}
                </span>
                <span class="text-xs text-stone-500 dark:text-neutral-400">
                    Created {{ formatDate(detailsTask.created_at) }}
                </span>
            </div>

            <div>
                <div class="text-xs uppercase text-stone-400 dark:text-neutral-500">Assignee</div>
                <div class="mt-1 text-sm text-stone-700 dark:text-neutral-200">
                    {{ displayAssignee(detailsTask) }}
                </div>
            </div>

            <div>
                <div class="text-xs uppercase text-stone-400 dark:text-neutral-500">Description</div>
                <p class="mt-1 whitespace-pre-wrap text-sm text-stone-700 dark:text-neutral-200">
                    {{ detailsTask.description || 'No description.' }}
                </p>
            </div>

            <div>
                <div class="text-xs uppercase text-stone-400 dark:text-neutral-500">Materials</div>
                <div v-if="detailsTask.materials?.length" class="mt-2 space-y-2">
                    <div
                        v-for="material in detailsTask.materials"
                        :key="material.id || material.label"
                        class="flex items-center justify-between rounded-sm border border-stone-200 bg-stone-50 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-800"
                    >
                        <span class="text-stone-700 dark:text-neutral-200">
                            {{ material.label || material.product?.name || 'Material' }}
                        </span>
                        <span class="text-stone-500 dark:text-neutral-400">
                            {{ material.quantity || 0 }} {{ material.unit || material.product?.unit || '' }}
                        </span>
                    </div>
                </div>
                <p v-else class="mt-2 text-xs text-stone-500 dark:text-neutral-400">
                    No materials.
                </p>
            </div>
        </div>

        <div class="mt-5 flex flex-wrap items-center justify-end gap-2">
            <Link
                v-if="detailsTask?.id"
                :href="`/tasks/${detailsTask.id}`"
                class="py-2 px-3 inline-flex items-center text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 action-feedback"
            >
                Open full task
            </Link>
            <button
                type="button"
                data-hs-overlay="#hs-task-details"
                class="py-2 px-3 inline-flex items-center text-xs font-medium rounded-sm border border-transparent bg-stone-900 text-white hover:bg-stone-800 dark:bg-neutral-100 dark:text-stone-900 action-feedback"
            >
                Close
            </button>
        </div>
    </Modal>

    <Modal v-if="canCreate" :title="'Add task'" :id="'hs-task-create'">
        <form class="space-y-4" @submit.prevent="submitCreate">
            <div>
                <FloatingInput v-model="createForm.title" label="Title" :required="true" />
                <InputError class="mt-1" :message="createForm.errors.title" />
            </div>

            <div>
                <FloatingTextarea v-model="createForm.description" label="Description (optional)" />
                <InputError class="mt-1" :message="createForm.errors.description" />
            </div>

            <div>
                <label class="block text-xs text-stone-500 dark:text-neutral-400">Job</label>
                <select
                    v-model="createForm.work_id"
                    :disabled="createForm.standalone"
                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 disabled:bg-stone-100 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:disabled:bg-neutral-800"
                >
                    <option value="">Select a job</option>
                    <option v-for="work in workOptions" :key="work.id" :value="work.id">
                        {{ work.name }}
                    </option>
                </select>
                <InputError class="mt-1" :message="createForm.errors.work_id" />
                <label class="mt-2 flex items-center gap-2">
                    <Checkbox v-model:checked="createForm.standalone" />
                    <span class="text-xs text-stone-600 dark:text-neutral-400">Task ponctuelle (sans job)</span>
                </label>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-stone-500 dark:text-neutral-400">Status</label>
                    <select v-model="createForm.status"
                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option v-for="status in statuses" :key="status" :value="status">
                            {{ statusLabel(status) }}
                        </option>
                    </select>
                    <InputError class="mt-1" :message="createForm.errors.status" />
                </div>
                <div>
                    <DatePicker v-model="createForm.due_date" label="Due date" />
                    <InputError class="mt-1" :message="createForm.errors.due_date" />
                </div>
                <div v-if="teamMembers.length" class="md:col-span-2">
                    <label class="block text-xs text-stone-500 dark:text-neutral-400">Assignee</label>
                    <select v-model="createForm.assigned_team_member_id"
                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">Unassigned</option>
                        <option v-for="member in teamMembers" :key="member.id" :value="member.id">
                            {{ member.user?.name || `Member #${member.id}` }} ({{ member.role }})
                        </option>
                    </select>
                    <InputError class="mt-1" :message="createForm.errors.assigned_team_member_id" />
                </div>
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-500">Materials</p>
                    <button type="button" @click="addMaterial(createForm)"
                        class="py-1.5 px-2.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 action-feedback">
                        Add material
                    </button>
                </div>
                <div v-if="createForm.materials.length" class="space-y-3">
                    <div v-for="(material, index) in createForm.materials" :key="material.id || index"
                        class="rounded-sm border border-stone-200 bg-stone-50 p-3 space-y-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <FloatingSelect
                                v-model="material.product_id"
                                :options="materialOptions"
                                label="Product"
                                @update:modelValue="applyMaterialDefaults(material)"
                            />
                            <FloatingInput v-model="material.label" label="Label" />
                            <FloatingNumberInput v-model="material.quantity" label="Quantity" :step="0.01" />
                            <FloatingNumberInput v-model="material.unit_price" label="Unit price" :step="0.01" />
                            <FloatingInput v-model="material.unit" label="Unit" />
                            <div class="flex items-center gap-2 p-2 rounded-sm border border-stone-200 bg-white dark:bg-neutral-900 dark:border-neutral-700">
                                <Checkbox v-model:checked="material.billable" />
                                <span class="text-sm text-stone-600 dark:text-neutral-400">Billable</span>
                            </div>
                        </div>
                        <FloatingTextarea v-model="material.description" label="Description (optional)" />
                        <div class="flex justify-end">
                            <button type="button" @click="removeMaterial(createForm, index)"
                                class="py-1.5 px-2.5 text-xs font-medium rounded-sm border border-red-200 bg-white text-red-600 hover:bg-red-50 dark:bg-neutral-800 dark:border-red-500/40 dark:text-red-400 action-feedback" data-tone="danger">
                                Remove
                            </button>
                        </div>
                    </div>
                </div>
                <p v-else class="text-xs text-stone-500 dark:text-neutral-500">
                    No materials yet.
                </p>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" data-hs-overlay="#hs-task-create"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 action-feedback">
                    Cancel
                </button>
                <button type="submit" :disabled="createForm.processing"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 action-feedback">
                    Create
                </button>
            </div>
        </form>
    </Modal>

    <Modal v-if="canManage" :title="'Edit task'" :id="'hs-task-edit'">
        <form class="space-y-4" @submit.prevent="submitEdit">
            <div>
                <FloatingInput v-model="editForm.title" label="Title" :required="true" />
                <InputError class="mt-1" :message="editForm.errors.title" />
            </div>

            <div>
                <FloatingTextarea v-model="editForm.description" label="Description (optional)" />
                <InputError class="mt-1" :message="editForm.errors.description" />
            </div>

            <div>
                <label class="block text-xs text-stone-500 dark:text-neutral-400">Job</label>
                <select
                    v-model="editForm.work_id"
                    :disabled="editForm.standalone"
                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 disabled:bg-stone-100 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200 dark:disabled:bg-neutral-800"
                >
                    <option value="">Select a job</option>
                    <option v-for="work in workOptions" :key="work.id" :value="work.id">
                        {{ work.name }}
                    </option>
                </select>
                <InputError class="mt-1" :message="editForm.errors.work_id" />
                <label class="mt-2 flex items-center gap-2">
                    <Checkbox v-model:checked="editForm.standalone" />
                    <span class="text-xs text-stone-600 dark:text-neutral-400">Task ponctuelle (sans job)</span>
                </label>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-stone-500 dark:text-neutral-400">Status</label>
                    <select v-model="editForm.status"
                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option v-for="status in statuses" :key="status" :value="status">
                            {{ statusLabel(status) }}
                        </option>
                    </select>
                    <InputError class="mt-1" :message="editForm.errors.status" />
                </div>
                <div>
                    <DatePicker v-model="editForm.due_date" label="Due date" />
                    <InputError class="mt-1" :message="editForm.errors.due_date" />
                </div>
                <div v-if="teamMembers.length" class="md:col-span-2">
                    <label class="block text-xs text-stone-500 dark:text-neutral-400">Assignee</label>
                    <select v-model="editForm.assigned_team_member_id"
                        class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                        <option value="">Unassigned</option>
                        <option v-for="member in teamMembers" :key="member.id" :value="member.id">
                            {{ member.user?.name || `Member #${member.id}` }} ({{ member.role }})
                        </option>
                    </select>
                    <InputError class="mt-1" :message="editForm.errors.assigned_team_member_id" />
                </div>
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <p class="text-xs uppercase tracking-wide text-stone-500 dark:text-neutral-500">Materials</p>
                    <button type="button" @click="addMaterial(editForm)"
                        class="py-1.5 px-2.5 text-xs font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 action-feedback">
                        Add material
                    </button>
                </div>
                <div v-if="editForm.materials.length" class="space-y-3">
                    <div v-for="(material, index) in editForm.materials" :key="material.id || index"
                        class="rounded-sm border border-stone-200 bg-stone-50 p-3 space-y-3 dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <FloatingSelect
                                v-model="material.product_id"
                                :options="materialOptions"
                                label="Product"
                                @update:modelValue="applyMaterialDefaults(material)"
                            />
                            <FloatingInput v-model="material.label" label="Label" />
                            <FloatingNumberInput v-model="material.quantity" label="Quantity" :step="0.01" />
                            <FloatingNumberInput v-model="material.unit_price" label="Unit price" :step="0.01" />
                            <FloatingInput v-model="material.unit" label="Unit" />
                            <div class="flex items-center gap-2 p-2 rounded-sm border border-stone-200 bg-white dark:bg-neutral-900 dark:border-neutral-700">
                                <Checkbox v-model:checked="material.billable" />
                                <span class="text-sm text-stone-600 dark:text-neutral-400">Billable</span>
                            </div>
                        </div>
                        <FloatingTextarea v-model="material.description" label="Description (optional)" />
                        <div class="flex justify-end">
                            <button type="button" @click="removeMaterial(editForm, index)"
                                class="py-1.5 px-2.5 text-xs font-medium rounded-sm border border-red-200 bg-white text-red-600 hover:bg-red-50 dark:bg-neutral-800 dark:border-red-500/40 dark:text-red-400 action-feedback" data-tone="danger">
                                Remove
                            </button>
                        </div>
                    </div>
                </div>
                <p v-else class="text-xs text-stone-500 dark:text-neutral-500">
                    No materials yet.
                </p>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" data-hs-overlay="#hs-task-edit"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 action-feedback">
                    Cancel
                </button>
                <button type="submit" :disabled="editForm.processing"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 action-feedback">
                    Save
                </button>
            </div>
        </form>
    </Modal>

    <Modal v-if="canChangeStatus" :title="'Add task proof'" :id="'hs-task-proof'">
        <form class="space-y-4" @submit.prevent="submitProof">
            <div>
                <label class="block text-xs text-stone-500 dark:text-neutral-400">Type</label>
                <select v-model="proofForm.type"
                    class="mt-1 block w-full rounded-sm border-stone-200 text-sm focus:border-green-600 focus:ring-green-600 dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-200">
                    <option value="execution">Execution</option>
                    <option value="completion">Completion</option>
                    <option value="other">Other</option>
                </select>
                <InputError class="mt-1" :message="proofForm.errors.type" />
            </div>

            <div>
                <label class="block text-xs text-stone-500 dark:text-neutral-400">File (photo or video)</label>
                <input type="file" @change="handleProofFile" accept="image/*,video/*"
                    class="mt-1 block w-full text-sm text-stone-600 file:mr-4 file:py-2 file:px-3 file:rounded-sm file:border-0 file:text-sm file:font-medium file:bg-stone-100 file:text-stone-700 hover:file:bg-stone-200 dark:text-neutral-300 dark:file:bg-neutral-800 dark:file:text-neutral-200" />
                <InputError class="mt-1" :message="proofForm.errors.file" />
            </div>

            <div>
                <FloatingInput v-model="proofForm.note" label="Note (optional)" />
                <InputError class="mt-1" :message="proofForm.errors.note" />
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" data-hs-overlay="#hs-task-proof"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-stone-200 bg-white text-stone-700 hover:bg-stone-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-200 action-feedback">
                    Cancel
                </button>
                <button type="submit" :disabled="proofForm.processing"
                    class="py-2 px-3 inline-flex items-center text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white hover:bg-green-700 disabled:opacity-50 action-feedback">
                    Upload
                </button>
            </div>
        </form>
    </Modal>
</template>


