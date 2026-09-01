import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const source = (path) => readFileSync(resolve(path), 'utf8');
const messages = (locale) => JSON.parse(source(`resources/js/i18n/modules/${locale}/team.json`));
const teamTable = () => source('resources/js/Pages/Team/UI/TeamTable.vue');
const sectionBetween = (content, startMarker, endMarker) => {
    const start = content.indexOf(startMarker);
    const end = content.indexOf(endMarker, start);

    assert.notEqual(start, -1, `missing start marker: ${startMarker}`);
    assert.notEqual(end, -1, `missing end marker: ${endMarker}`);

    return content.slice(start, end);
};

test('team members offer persistent accessible table and card views without losing pagination', () => {
    const table = teamTable();
    const cardView = sectionBetween(
        table,
        '<div v-else class="space-y-3" data-testid="team-card-view">',
        '<Modal :title="t(\'team.dialogs.member_details\')"',
    );

    assert.match(table, /const teamViewModes = \['table', 'cards'\];/u);
    assert.match(table, /window\.localStorage\.getItem\('team_view_mode'\)/u);
    assert.match(table, /window\.localStorage\.setItem\('team_view_mode', mode\)/u);
    assert.match(table, /data-testid="team-view-table"[\s\S]*?:aria-pressed="viewMode === 'table'"/u);
    assert.match(table, /data-testid="team-view-cards"[\s\S]*?:aria-pressed="viewMode === 'cards'"/u);
    assert.match(table, /<AdminDataTable[\s\S]*?v-if="viewMode === 'table'"[\s\S]*?:rows="teamRows"[\s\S]*?:links="teamLinks"[\s\S]*?show-per-page[\s\S]*?:per-page="currentPerPage"/u);
    assert.match(table, /data-testid="team-card-grid"[\s\S]*?v-for="member in teamRows"/u);
    assert.match(table, /grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3/u);
    assert.match(cardView, /data-testid="team-card-footer"/u);
    assert.match(cardView, /v-if="hasMultipleTeamPages"[\s\S]*?<AdminPaginationLinks :links="teamLinks"/u);
    assert.match(cardView, /data-testid="team-card-per-page"[\s\S]*?@change="updatePerPage"/u);
    assert.match(cardView, /v-for="option in DATA_TABLE_PER_PAGE_OPTIONS"/u);
    assert.match(table, /const updatePerPage = \(event\) => \{[\s\S]*?per_page: nextPerPage,[\s\S]*?replace: true/u);
});

test('team cards expose useful member information and open details explicitly', () => {
    const table = teamTable();
    const cardView = sectionBetween(
        table,
        '<div v-else class="space-y-3" data-testid="team-card-view">',
        '<Modal :title="t(\'team.dialogs.member_details\')"',
    );
    const cardOpeningTag = cardView.match(/<article[\s\S]*?:aria-labelledby="`team-card-title-\$\{member\.id\}`"\s*>/u)?.[0] || '';

    assert.notEqual(cardOpeningTag, '');
    assert.doesNotMatch(cardOpeningTag, /@click=/u, 'nested actions must not make the whole member card clickable');
    assert.match(cardOpeningTag, /class="flex h-full flex-col/u);
    assert.match(cardView, /<div class="flex-1 space-y-3 p-4">/u);

    for (const memberDetail of [
        'memberAvatarUrl(member)',
        'memberDisplayName(member)',
        'member.user?.email',
        'companyRoleLabel(member)',
        'member.title',
        'member.phone',
        'statusLabel(member)',
        'formatDate(member.created_at)',
        'visiblePermissionLabels(member)',
    ]) {
        assert.match(cardView, new RegExp(memberDetail.replace(/[.*+?^${}()|[\]\\]/gu, '\\$&'), 'u'));
    }

    assert.match(cardView, /:data-testid="`team-card-open-\$\{member\.id\}`"[\s\S]*?@click="openDetailMember\(member\)"/u);
    assert.match(cardView, /<AdminDataTableActions :label="t\('team\.actions\.member_actions', \{ name: memberDisplayName\(member\) \}\)"[\s\S]*?openEditMember\(member\)[\s\S]*?deactivateMember\(member\)[\s\S]*?activateMember\(member\)/u);
    assert.match(cardView, /:aria-label="t\('team\.actions\.view_member', \{ name: memberDisplayName\(member\) \}\)"/u);
});

test('team member mutations are only offered when the matching permission is granted', () => {
    const table = teamTable();
    const tableView = sectionBetween(table, '<AdminDataTable\n            v-if="viewMode === \'table\'"', '<div v-else class="space-y-3"');
    const cardView = sectionBetween(
        table,
        '<div v-else class="space-y-3" data-testid="team-card-view">',
        '<Modal :title="t(\'team.dialogs.member_details\')"',
    );

    assert.match(table, /const canCreateTeamMembers = computed\(\(\) => hasPermission\('create_team_members'\)\);/u);
    assert.match(table, /const canUpdateTeamMembers = computed\(\(\) => hasPermission\('update_team_members'\)\);/u);
    assert.match(table, /const canDeactivateTeamMembers = computed\(\(\) => hasPermission\('deactivate_team_members'\)\);/u);
    assert.match(table, /<button v-if="canCreateTeamMembers"[^>]+data-hs-overlay="#hs-team-create"/u);
    assert.match(table, /<Modal v-if="canCreateTeamMembers"[^>]+hs-team-create/u);
    assert.match(table, /<Modal v-if="canUpdateTeamMembers"[^>]+hs-team-edit/u);
    assert.match(table, /const submitCreate = \(\) => \{[\s\S]*?!canCreateTeamMembers\.value/u);
    assert.match(table, /const submitEdit = \(\) => \{[\s\S]*?!canUpdateTeamMembers\.value/u);
    assert.match(table, /const deactivateMember = \(member\) => \{[\s\S]*?!canDeactivateTeamMembers\.value/u);
    assert.match(table, /const activateMember = \(member\) => \{[\s\S]*?!canUpdateTeamMembers\.value/u);

    for (const view of [tableView, cardView]) {
        assert.match(view, /v-if="canUpdateTeamMembers"[\s\S]*?openEditMember\(member\)/u);
        assert.match(view, /v-if="canDeactivateTeamMembers && member\.is_active"[\s\S]*?deactivateMember\(member\)/u);
        assert.match(view, /v-else-if="canUpdateTeamMembers && !member\.is_active"[\s\S]*?activateMember\(member\)/u);
        assert.match(view, /team\.actions\.member_actions', \{ name: memberDisplayName\(member\) \}/u);
    }
});

test('team performance links follow the same source and authorization contract as the backend', () => {
    const table = teamTable();

    assert.match(table, /const \{ hasAnyPermission, hasPermission \} = usePermissions\(\);/u);
    assert.match(table, /if \(features\.reservations\) \{[\s\S]*?return 'reservations';[\s\S]*?if \(features\.jobs \|\| features\.tasks\) \{[\s\S]*?return 'services';[\s\S]*?features\.sales \? 'products' : null/u);
    assert.match(table, /const canViewTeamPerformance = computed\(\(\) => \([\s\S]*?account\?\.is_owner[\s\S]*?team\?\.role === 'admin'[\s\S]*?reports\.team[\s\S]*?view_team_reports[\s\S]*?performanceMode\.value === 'products'[\s\S]*?view_sales_reports/u);
    assert.match(table, /const canViewOwnPerformance = computed\(\(\) => \{[\s\S]*?reservations\.view[\s\S]*?jobs\.view[\s\S]*?sales\.pos/u);
    assert.match(table, /const memberPerformanceUrl = \(member\) => \{[\s\S]*?account\?\.is_superadmin[\s\S]*?!accountFeatures\.value\.performance && !bypassesFeatureMiddleware[\s\S]*?isCurrentUser[\s\S]*?!canViewTeamPerformance\.value && !\(isCurrentUser && canViewOwnPerformance\.value\)[\s\S]*?performance\.employee\.show/u);
});

test('team table and card view copy is complete in every locale', () => {
    for (const locale of ['fr', 'en', 'es']) {
        const team = messages(locale).team;

        for (const key of ['label', 'table', 'cards']) {
            assert.equal(typeof team.view[key], 'string', `${locale}.team.view.${key}`);
            assert.notEqual(team.view[key].trim(), '', `${locale}.team.view.${key}`);
        }

        assert.equal(typeof team.actions.view_member, 'string', `${locale}.team.actions.view_member`);
        assert.match(team.actions.view_member, /\{name\}/u, `${locale} member detail label keeps name interpolation`);
        assert.equal(typeof team.actions.member_actions, 'string', `${locale}.team.actions.member_actions`);
        assert.match(team.actions.member_actions, /\{name\}/u, `${locale} member actions label keeps name interpolation`);
    }
});
