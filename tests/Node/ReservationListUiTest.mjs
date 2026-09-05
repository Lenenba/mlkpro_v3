import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import { normalizeDataTablePerPage } from '../../resources/js/Components/DataTable/pagination.js';
import { reservationReloadProps } from '../../resources/js/utils/reservationNavigation.js';
import {
    nextReservationListSort,
    reservationListAllowedStatusTransitions,
    reservationListCanDelete,
    reservationListCanEdit,
    reservationListCanUpdateStatus,
    reservationListCanView,
    reservationListClient,
    reservationListEntityName,
    reservationListImageSource,
    reservationListServiceName,
    reservationListQuickStatusAction,
    reservationListSecondaryStatusActions,
    reservationListSourceKey,
    reservationListSortColumn,
    reservationListSortDirection,
    reservationListSortValue,
    reservationListTeamMember,
} from '../../resources/js/utils/reservationList.js';

const source = (path) => readFileSync(resolve(path), 'utf8');
const messages = (locale) => JSON.parse(source(`resources/js/i18n/modules/${locale}/reservations.json`));

test('reservation pagination uses the same partial reload scope on desktop and mobile', () => {
    const component = source('resources/js/Components/Reservation/ReservationListTable.vue');
    const table = source('resources/js/Components/DataTable/AdminDataTable.vue');
    const pagination = source('resources/js/Components/DataTable/AdminPaginationLinks.vue');

    assert.match(component, /const paginationOnly = reservationReloadProps\(\{ tab: 'reservations', view: 'list', reason: 'ordering' \}\)/u);
    assert.match(component, /<AdminDataTable\b[^>]*:pagination-only="paginationOnly"/u);
    assert.match(component, /<AdminPaginationLinks\b[^>]*:only="paginationOnly"/u);
    assert.match(table, /<AdminPaginationLinks\b[^>]*:only="paginationOnly"/u);
    assert.match(table, /paginationOnly:\s*\{\s*type: Array,\s*default: \(\) => \[\]/u);
    assert.match(pagination, /only:\s*\{\s*type: Array,\s*default: \(\) => \[\]/u);
    assert.match(pagination, /<Link\b[^>]*:only="only"/u);
});

test('desktop page size retains calendar and search state while limiting requested reservation props', () => {
    const table = source('resources/js/Components/DataTable/AdminDataTable.vue');
    const action = table.match(/const updatePerPage = \(event\) => \{[\s\S]*?\n\};/u)?.[0];
    assert.ok(action, 'the table must expose its page size action');
    const makeAction = new Function('props', 'router', 'emit', 'normalizeDataTablePerPage', 'normalizedPerPage', 'window', `${action}\nreturn updatePerPage;`);

    for (const paginationOnly of [[], reservationReloadProps({ tab: 'reservations', view: 'list', reason: 'ordering' })]) {
        const visits = [];
        const emitted = [];
        const updatePerPage = makeAction(
            { paginationOnly },
            { get: (...args) => visits.push(args) },
            (...args) => emitted.push(args),
            normalizeDataTablePerPage,
            { value: 10 },
            { location: { href: 'https://malikia.test/app/reservations?search=Jules&view_mode=list&calendar_view=week&calendar_date=2031-11-15&page=3' } },
        );

        updatePerPage({ target: { value: '25' } });

        assert.deepEqual(emitted, [['update:perPage', 25]]);
        assert.equal(visits.length, 1);
        const [href, data, options] = visits[0];
        const url = new URL(href, 'https://malikia.test');
        assert.equal(url.searchParams.get('search'), 'Jules');
        assert.equal(url.searchParams.get('calendar_view'), 'week');
        assert.equal(url.searchParams.get('calendar_date'), '2031-11-15');
        assert.equal(url.searchParams.get('per_page'), '25');
        assert.equal(url.searchParams.has('page'), false);
        assert.deepEqual(data, {});
        assert.deepEqual(options, { preserveState: true, preserveScroll: true, replace: true, only: paginationOnly });
    }
});

test('reservation list normalizers support enriched and legacy DTOs safely', () => {
    const enriched = {
        service: {
            display_name: 'Ignored display name',
            name: 'Signature colour',
            has_image: true,
            image_url: 'https://cdn.example.test/colour.webp',
        },
        client: {
            display_name: 'Sophie Benali',
            avatar_url: '/storage/clients/sophie.webp',
        },
        team_member: {
            display_name: 'Maya Koné',
            avatar_url: 'javascript:alert(1)',
        },
        source: 'public_booking',
        capabilities: { can_view: true, can_edit: true, can_delete: false },
    };

    assert.equal(reservationListServiceName(enriched), 'Signature colour');
    assert.equal(reservationListEntityName(reservationListClient(enriched)), 'Sophie Benali');
    assert.equal(reservationListEntityName(reservationListTeamMember(enriched)), 'Maya Koné');
    assert.equal(reservationListImageSource(enriched.service, { requireImageFlag: true }), 'https://cdn.example.test/colour.webp');
    assert.equal(reservationListImageSource(enriched.client), '/storage/clients/sophie.webp');
    assert.equal(reservationListImageSource(enriched.team_member), '');
    assert.equal(reservationListSourceKey(enriched), 'public_booking');
    assert.equal(reservationListCanView(enriched), true);
    assert.equal(reservationListCanEdit(enriched, true), true);
    assert.equal(reservationListCanDelete(enriched, true), false);
    assert.equal(reservationListCanEdit(enriched, false), false);
    assert.equal(reservationListImageSource({ has_image: false, image_url: '/placeholder.webp' }, { requireImageFlag: true }), '');
    assert.equal(reservationListImageSource({ image_url: '/enriched-service.webp' }, { requireImageFlag: true }), '/enriched-service.webp');

    const legacy = {
        service_name: 'Classic cut',
        prospect: { contact_name: 'Alex Martin' },
        teamMember: { user: { name: 'Sam Lee' } },
        booking_source: 'internal',
        permissions: { can_edit: false, can_delete: true },
    };

    assert.equal(reservationListServiceName(legacy), 'Classic cut');
    assert.equal(reservationListEntityName(reservationListClient(legacy)), 'Alex Martin');
    assert.equal(reservationListEntityName(reservationListTeamMember(legacy)), 'Sam Lee');
    assert.equal(reservationListSourceKey(legacy), 'staff');
    assert.equal(reservationListCanEdit(legacy, true), false);
    assert.equal(reservationListCanDelete(legacy, true), true);
    assert.equal(reservationListCanView({ permissions: { can_view: false } }), false);
    assert.equal(reservationListCanEdit({ permissions: { can_view: false, can_edit: true } }, true), false);
    assert.equal(reservationListCanDelete({ capabilities: { can_view: false, can_delete: true } }, true), false);
    assert.equal(reservationListCanView({}), true, 'legacy rows stay viewable unless explicitly denied');
});

test('reservation list sorting cycles every exposed column in both directions', () => {
    for (const column of ['date', 'status', 'client', 'service', 'team_member']) {
        const ascending = nextReservationListSort('unrelated_asc', column);
        const descending = nextReservationListSort(ascending, column);
        const ascendingAgain = nextReservationListSort(descending, column);

        assert.equal(ascending, `${column}_asc`, `${column}: first click`);
        assert.equal(descending, `${column}_desc`, `${column}: second click`);
        assert.equal(ascendingAgain, `${column}_asc`, `${column}: third click`);
    }

    assert.equal(nextReservationListSort('status', 'status'), 'status_desc', 'legacy status sort');
    assert.equal(nextReservationListSort('service_asc', 'unsupported'), 'date_asc', 'invalid columns fail closed');
    assert.equal(reservationListSortColumn('team_member_desc'), 'team_member');
    assert.equal(reservationListSortColumn('unsupported_desc'), 'date');
    assert.equal(reservationListSortDirection('client_desc'), 'desc');
    assert.equal(reservationListSortDirection('status'), 'asc');
    assert.equal(reservationListSortValue('service', 'desc'), 'service_desc');
    assert.equal(reservationListSortValue('unsupported', 'sideways'), 'date_asc');
});

test('reservation list exposes one contextual primary status action and keeps alternatives secondary', () => {
    const now = new Date('2026-09-03T16:00:00.000Z');
    const pending = {
        id: 11,
        status: 'pending',
        ends_at: '2026-09-04T16:00:00.000Z',
        permissions: {
            can_view: true,
            can_update_status: true,
            allowed_status_transitions: ['confirmed', 'cancelled'],
        },
    };

    assert.equal(reservationListCanUpdateStatus(pending), true);
    assert.deepEqual(reservationListAllowedStatusTransitions(pending), ['confirmed', 'cancelled']);
    assert.deepEqual(
        reservationListQuickStatusAction(pending, now),
        { status: 'confirmed', labelKey: 'confirm', destructive: false },
    );
    assert.deepEqual(
        reservationListSecondaryStatusActions(pending, now),
        [{ status: 'cancelled', labelKey: 'cancel', destructive: true }],
    );

    const past = {
        ...pending,
        id: 12,
        status: 'confirmed',
        ends_at: '2026-09-03T15:00:00.000Z',
        permissions: {
            ...pending.permissions,
            allowed_status_transitions: ['completed', 'no_show', 'cancelled'],
        },
    };

    assert.equal(reservationListQuickStatusAction(past, now)?.status, 'completed');
    assert.deepEqual(
        reservationListSecondaryStatusActions(past, now).map((action) => action.status),
        ['no_show', 'cancelled'],
    );

    const cancelled = {
        ...pending,
        id: 13,
        status: 'cancelled',
        permissions: {
            ...pending.permissions,
            allowed_status_transitions: ['confirmed'],
        },
    };

    assert.deepEqual(
        reservationListQuickStatusAction(cancelled, now),
        { status: 'confirmed', labelKey: 'confirm', destructive: false },
    );
    assert.equal(reservationListQuickStatusAction({ ...pending, permissions: { can_update_status: false } }, now), null);
});

test('reservation list copy is complete in French, English, and Spanish', () => {
    const requiredListKeys = [
        'title',
        'subtitle',
        'count',
        'timezone',
        'reference',
        'unavailable',
        'unassigned',
        'open',
        'actions_for',
        'service_image_alt',
        'loading',
        'error_title',
        'retry',
        'mobile_controls',
        'sort_by',
        'sort_direction',
        'sort_ascending',
        'sort_descending',
        'rows_per_page',
        'empty_title',
        'empty_description',
        'empty_filtered',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const reservationMessages = messages(locale).reservations;

        for (const key of requiredListKeys) {
            assert.equal(typeof reservationMessages.list?.[key], 'string', `${locale}:reservations.list.${key}`);
            assert.notEqual(reservationMessages.list[key].trim(), '', `${locale}:reservations.list.${key}`);
        }

        assert.match(reservationMessages.list.count, /\{count\}/u, `${locale}:count interpolation`);
        assert.match(reservationMessages.list.timezone, /\{timezone\}/u, `${locale}:timezone interpolation`);
        assert.match(reservationMessages.list.reference, /\{id\}/u, `${locale}:reference interpolation`);
        assert.match(reservationMessages.list.open, /\{service\}/u, `${locale}:service interpolation`);
        assert.match(reservationMessages.list.open, /\{client\}/u, `${locale}:client interpolation`);
        assert.equal(typeof reservationMessages.table?.source, 'string', `${locale}:reservations.table.source`);
        assert.equal(typeof reservationMessages.errors?.load_list, 'string', `${locale}:reservations.errors.load_list`);
        for (const key of ['label', 'all', 'pending', 'today', 'upcoming', 'past', 'completed', 'no_show', 'cancelled']) {
            assert.equal(typeof reservationMessages.quick?.[key], 'string', `${locale}:reservations.quick.${key}`);
            assert.notEqual(reservationMessages.quick[key].trim(), '', `${locale}:reservations.quick.${key}`);
        }
        assert.match(reservationMessages.actions?.cancel_confirm || '', /\{reference\}/u, `${locale}:cancel confirmation interpolation`);
    }
});

test('reservation list is flat, responsive, accessible, and resilient', () => {
    const component = source('resources/js/Components/Reservation/ReservationListTable.vue');
    const index = source('resources/js/Pages/Reservation/Index.vue');
    const normalizers = source('resources/js/utils/reservationList.js');

    assert.match(component, /data-reservation-list-table/u);
    assert.match(component, /total:\s*\{[\s\S]*?type:\s*Number,[\s\S]*?default:\s*null/u);
    assert.match(component, /props\.total \?\? normalizedRows\.value\.length/u);
    assert.match(component, /class="hidden lg:block"/u);
    assert.match(component, /class="space-y-3 p-3 lg:hidden"/u);
    assert.match(component, /data-reservation-mobile-toolbar/u);
    assert.match(component, /data-testid="reservation-mobile-sort-column"/u);
    assert.match(component, /data-testid="reservation-mobile-sort-asc"/u);
    assert.match(component, /data-testid="reservation-mobile-sort-desc"/u);
    assert.match(component, /data-testid="reservation-mobile-per-page"/u);
    assert.match(component, /emit\('set-sort', reservationListSortValue/u);
    assert.match(component, /emit\('per-page', normalizeDataTablePerPage/u);
    assert.match(component, /ReservationStatusBadge/u);
    assert.match(component, /reservation\.outcome_review_required_at/u);
    assert.match(component, /reservations\.outcome_review\.badge/u);
    assert.match(component, /EntityAvatar/u);
    assert.match(component, /reservationListServiceName/u);
    assert.match(component, /reservationListSourceKey/u);
    assert.match(component, /:src="serviceImage\(reservation\)"/u);
    assert.match(component, /@error="markServiceImageFailed\(reservation\)"/u);
    assert.match(component, /loading="lazy"/u);
    assert.match(component, /decoding="async"/u);
    assert.match(component, /role="status"/u);
    assert.match(component, /aria-live="polite"/u);
    assert.match(component, /role="alert"/u);
    assert.match(component, /:aria-busy="loading \? 'true' : 'false'"/u);
    assert.match(component, /data-reservation-list-loading-overlay/u);
    assert.match(component, /v-if="loading && normalizedRows\.length"/u);
    assert.doesNotMatch(component, /v-else-if="loading"/u);
    assert.match(component, /sourceRows\.value\.filter\(\(reservation\) => reservationListCanView\(reservation\)\)/u);
    assert.match(component, /const openReservation = \(reservation\) => \{[\s\S]*?reservationListCanView\(reservation\)/u);
    assert.match(component, /focus-visible:ring/u);
    assert.match(component, /motion-reduce:animate-none/u);
    assert.match(component, /dark:bg-/u);
    assert.match(component, /reservation-actions-trigger-/u);
    assert.match(component, /reservationListQuickStatusAction/u);
    assert.match(component, /reservationListSecondaryStatusActions/u);
    assert.match(component, /emit\('transition-status', reservation, action\.status\)/u);
    assert.match(component, /statusActionError/u);
    assert.match(component, /emit\('sort', 'service'\)/u);
    assert.match(component, /emit\('sort', 'client'\)/u);
    assert.match(component, /emit\('sort', 'team_member'\)/u);
    for (const column of ['date', 'status', 'client', 'service', 'team_member']) {
        assert.match(component, new RegExp(`:aria-sort="columnAriaSort\\('${column}'\\)"`, 'u'));
    }
    assert.match(normalizers, /capabilities/u);
    assert.doesNotMatch(component, /(?:bg-)?gradient-|(?:^|\s)(?:from|via|to)-(?:emerald|teal|amber|stone|neutral|white)/mu);

    assert.match(index, /import ReservationListTable/u);
    assert.match(index, /<ReservationListTable[\s\S]*?:timezone="timezone"/u);
    assert.match(index, /:can-manage="canManageReservationActions"/u);
    assert.match(index, /:loading="listLoading"/u);
    assert.match(index, /:error="listError"/u);
    assert.match(index, /@clear-filters="clearFilters"/u);
    assert.match(index, /data-testid="reservation-quick-filters"/u);
    assert.match(index, /<AdminQuickFilters[\s\S]*?:selected-values="filterForm\.quick_filters"/u);
    assert.match(index, /reservationFilterPayload\(filterForm\)/u);
    assert.match(index, /only:\s*reservationReloadProps\(/u);
    assert.match(index, /@transition-status="updateReservationStatusFromList"/u);
    assert.match(index, /listError\.value = t\('reservations\.errors\.load_list'\)/u);
    assert.match(index, /let listRequestSequence = 0/u);
    assert.match(index, /const requestSequence = \+\+listRequestSequence/u);
    assert.match(index, /requestSequence === listRequestSequence/u);
    assert.match(index, /const setReservationSort = \(column\) =>/u);
    assert.match(index, /nextReservationListSort\(filterForm\.sort, column\)/u);
    assert.match(index, /@sort="setReservationSort"/u);
    assert.match(index, /const setReservationSortValue = \(sort\) =>/u);
    assert.match(index, /@set-sort="setReservationSortValue"/u);
    assert.match(index, /const setReservationPerPage = \(perPage\) =>/u);
    assert.match(index, /refreshList\(\{ per_page: normalizedPerPage, reason: 'ordering' \}\)/u);
    assert.match(index, /@per-page="setReservationPerPage"/u);
    assert.match(index, /if \(!id \|\| !reservationListCanView\(reservation\)\)/u);
});
