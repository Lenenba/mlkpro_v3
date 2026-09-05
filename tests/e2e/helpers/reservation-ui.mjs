import { installLocalAppUi } from './local-app-ui.mjs';

const rows = [
    {
        id: 91001,
        status: 'confirmed',
        source: 'public_booking',
        starts_at: '2031-11-18T14:30:00Z',
        ends_at: '2031-11-18T15:30:00Z',
        service: { id: 91, name: 'Brushing cheveux longs' },
        client: { id: 92, first_name: 'Jules', last_name: 'Roger', name: 'Jules Roger' },
        team_member: { id: 93, name: 'Emma Roy', user: { name: 'Emma Roy' } },
    },
    {
        id: 91002,
        status: 'confirmed',
        source: 'staff',
        starts_at: '2031-11-19T15:30:00Z',
        ends_at: '2031-11-19T16:30:00Z',
        service: { id: 94, name: 'Coupe femme' },
        client: { id: 95, first_name: 'Alice', last_name: 'Martin', name: 'Alice Martin' },
        team_member: { id: 93, name: 'Emma Roy', user: { name: 'Emma Roy' } },
    },
];

const fixtureNow = '2031-11-18T12:00:00Z';
const quickKeys = ['pending', 'today', 'upcoming', 'past', 'completed', 'no_show', 'cancelled'];
const localDay = (value) => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Toronto' }).format(new Date(value));
const multiCriteriaRows = [
    ...rows,
    {
        ...rows[0], id: 91003, status: 'pending',
        starts_at: '2031-11-18T16:00:00Z', ends_at: '2031-11-18T17:00:00Z',
        client: { id: 96, first_name: 'Marie', last_name: 'Dupont', name: 'Marie Dupont' },
    },
    {
        ...rows[0], id: 91004, status: 'pending',
        starts_at: '2031-11-17T16:00:00Z', ends_at: '2031-11-17T17:00:00Z',
        client: { id: 97, first_name: 'Paul', last_name: 'Bernard', name: 'Paul Bernard' },
        team_member: { id: 98, name: 'Sofia Diallo', user: { name: 'Sofia Diallo' } },
    },
    {
        ...rows[1], id: 91005, status: 'completed',
        starts_at: '2031-11-18T10:00:00Z', ends_at: '2031-11-18T11:00:00Z',
        client: { id: 99, first_name: 'Nora', last_name: 'Petit', name: 'Nora Petit' },
    },
    {
        ...rows[1], id: 91006, status: 'cancelled',
        client: { id: 100, first_name: 'Léo', last_name: 'Durand', name: 'Léo Durand' },
    },
];

const fixtureFilters = (url) => {
    const quickEntries = [...url.searchParams].filter(([key]) => /^quick_filters(?:\[\d*\])?$/u.test(key));
    const requestedQuick = quickEntries.length
        ? quickEntries.map(([, value]) => value)
        : [url.searchParams.get('quick')];

    return {
        search: '', status: '', team_member_id: '', service_id: '', date_from: '', date_to: '',
        scope: 'all', quick: '', sort: 'date_asc', per_page: 10, view_mode: 'calendar',
        calendar_view: 'week', calendar_date: '2031-11-18', data_tab: 'reservations',
        ...Object.fromEntries([...url.searchParams].filter(([key]) => !key.startsWith('quick_filters'))),
        quick_filters: [...new Set(requestedQuick.filter((value) => quickKeys.includes(value)))],
        quick_filter_mode: url.searchParams.get('quick_filter_mode') === 'any' ? 'any' : 'all',
    };
};

const matchesQuickFilter = (row, quick) => {
    if (quick === 'today') return localDay(row.starts_at) === localDay(fixtureNow);
    if (quick === 'past') return Date.parse(row.ends_at) < Date.parse(fixtureNow);
    if (quick === 'upcoming') {
        return Date.parse(row.starts_at) > Date.parse(fixtureNow)
            && ['pending', 'confirmed', 'rescheduled'].includes(row.status);
    }
    return row.status === quick;
};

const matchingRows = (sourceRows, filters, { includeQuick = true, includeStatusDate = true } = {}) => sourceRows.filter((row) => {
    if (!`${row.client.name} ${row.service.name}`.toLowerCase().includes(filters.search.toLowerCase())) return false;
    if (filters.service_id && String(row.service.id) !== String(filters.service_id)) return false;
    if (filters.team_member_id && String(row.team_member.id) !== String(filters.team_member_id)) return false;
    if (includeStatusDate) {
        if (filters.status && row.status !== filters.status) return false;
        if (filters.date_from && localDay(row.starts_at) < filters.date_from) return false;
        if (filters.date_to && localDay(row.starts_at) > filters.date_to) return false;
    }
    if (!includeQuick || !filters.quick_filters.length) return true;
    const matches = filters.quick_filters.map((quick) => matchesQuickFilter(row, quick));
    return filters.quick_filter_mode === 'any' ? matches.some(Boolean) : matches.every(Boolean);
});

const fixtureRows = ({ paginate, multiCriteria }) => {
    if (multiCriteria) return multiCriteriaRows;
    return paginate ? [...rows, ...Array.from({ length: 10 }, (_, index) => ({
        ...rows[0], id: 92000 + index,
        client: { id: 93000 + index, first_name: 'Client', last_name: String(index), name: `Client ${index}` },
    }))] : rows;
};

const fixtureProps = (url, options) => {
    const { paginate } = options;
    const filters = fixtureFilters(url);
    const sourceRows = fixtureRows(options);
    const filteredRows = matchingRows(sourceRows, filters);
    const summaryRows = matchingRows(sourceRows, filters, { includeQuick: false, includeStatusDate: false });
    const perPage = Number(filters.per_page);
    const currentPage = Number(url.searchParams.get('page') || 1);
    const lastPage = Math.max(1, Math.ceil(filteredRows.length / perPage));
    const pageRows = filteredRows.slice((currentPage - 1) * perPage, currentPage * perPage);
    const links = paginate ? Array.from({ length: lastPage }, (_, index) => {
        const pageUrl = new URL(url);
        pageUrl.searchParams.set('page', String(index + 1));
        return { url: pageUrl.href, label: String(index + 1), active: currentPage === index + 1 };
    }) : [];

    return {
        locale: 'fr', errors: {}, flash: {},
        auth: {
            user: { id: 90001, name: 'Compte de test', email: 'browser@example.test' },
            account: {
                owner_id: 90001, is_owner: true, is_client: false,
                company: { name: 'Salon de test', type: 'services', features: { reservations: true } },
            },
        },
        assistant: { enabled: false },
        filters,
        reservations: {
            data: pageRows, total: filteredRows.length, per_page: perPage, current_page: currentPage,
            last_page: lastPage,
            from: pageRows.length ? (currentPage - 1) * perPage + 1 : null,
            to: pageRows.length ? (currentPage - 1) * perPage + pageRows.length : null,
            links,
        },
        reservationCount: filteredRows.length,
        events: [],
        statuses: ['pending', 'confirmed', 'completed', 'cancelled', 'no_show'],
        stats: {
            total: filteredRows.length,
            ...Object.fromEntries(['pending', 'confirmed', 'completed', 'cancelled', 'no_show'].map((status) => [
                status, filteredRows.filter((row) => row.status === status).length,
            ])),
        },
        quickCounts: {
            all: summaryRows.length,
            ...Object.fromEntries(quickKeys.map((quick) => [quick, summaryRows.filter((row) => matchesQuickFilter(row, quick)).length])),
        },
        performance: {}, waitlists: [], waitlistStats: {}, queueItems: [], queueStats: {},
        access: { can_view_all: true, can_manage: true },
        teamMembers: [{ id: 93, name: 'Emma Roy' }, { id: 98, name: 'Sofia Diallo' }],
        services: rows.map((row) => row.service),
        clients: sourceRows.map((row) => row.client),
        timezone: 'America/Toronto',
        defaults: {},
        settings: { queue_mode_enabled: true, waitlist_enabled: true },
    };
};

export async function installReservationUiFixture(page, { paginate = false, multiCriteria = false } = {}) {
    const options = { paginate, multiCriteria };
    if (multiCriteria) await page.clock.setFixedTime(new Date(fixtureNow));

    return installLocalAppUi(page, {
        origin: 'http://reservation-ui.test',
        pathname: '/app/reservations',
        component: 'Reservation/Index',
        props: (url) => fixtureProps(url, options),
        observations: { events: [] },
        intercept: async ({ route, request, url, ziggy, requests }) => {
            if (request.method() !== 'GET' || url.pathname !== `/${ziggy.routes['reservation.events'].uri}`) {
                return false;
            }
            requests.events.push(url);
            const filteredRows = matchingRows(fixtureRows(options), fixtureFilters(url));
            const start = Date.parse(url.searchParams.get('start'));
            const end = Date.parse(url.searchParams.get('end'));
            await route.fulfill({ json: {
                events: filteredRows.filter((row) => (
                    Date.parse(row.starts_at) < end && Date.parse(row.ends_at) > start
                )).map((row) => ({
                    id: row.id, title: `${row.service.name} · ${row.client.name}`,
                    start: row.starts_at, end: row.ends_at,
                    extendedProps: { status: row.status, service_name: row.service.name, client_name: row.client.name },
                })),
            } });
            return true;
        },
    });
}
