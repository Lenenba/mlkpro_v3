import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import {
    buildProspectAssigneeChartData,
    buildProspectSourceChartData,
    buildProspectStatusChartData,
    buildRequestSourceChartData,
} from '../../resources/js/utils/requestAnalyticsCharts.js';

const read = (path) => readFileSync(resolve(path), 'utf8');
const emptyChartData = {
    categories: [],
    series: [],
    details: [],
};

test('prospect status chart preserves ordered exact counts, including zero, without mutating input', () => {
    const rows = [
        { status: 'REQ_NEW', label: 'New', total: 7 },
        { status: 'REQ_CONTACTED', label: 'Contacted', total: 0 },
        { status: 'REQ_WON', label: 'Won', total: 2 },
    ];
    const originalRows = structuredClone(rows);

    const chart = buildProspectStatusChartData(rows, {
        labelForStatus: (status, row) => `${row.label} (${status})`,
        totalLabel: 'Prospects',
    });

    assert.deepEqual(chart, {
        categories: ['New (REQ_NEW)', 'Contacted (REQ_CONTACTED)', 'Won (REQ_WON)'],
        series: [{ name: 'Prospects', data: [7, 0, 2] }],
        details: [
            { key: 'REQ_NEW', category: 'New (REQ_NEW)', total: 7 },
            { key: 'REQ_CONTACTED', category: 'Contacted (REQ_CONTACTED)', total: 0 },
            { key: 'REQ_WON', category: 'Won (REQ_WON)', total: 2 },
        ],
    });
    assert.deepEqual(rows, originalRows);
});

test('prospect source chart exposes exact grouped values and operational details', () => {
    const chart = buildProspectSourceChartData([
        { source: 'web_form', total: 10, converted: 4, won: 3, lost: 2, rate: 40 },
        { source: 'phone', total: 0, converted: 0, won: 0, lost: 0, rate: 0 },
    ], {
        labelForSource: (source) => source === 'web_form' ? 'Web form' : 'Phone',
        totalLabel: 'Prospects',
        convertedLabel: 'Converted',
    });

    assert.deepEqual(chart.categories, ['Web form', 'Phone']);
    assert.deepEqual(chart.series, [
        { name: 'Prospects', data: [10, 0] },
        { name: 'Converted', data: [4, 0] },
    ]);
    assert.deepEqual(chart.details, [
        {
            key: 'web_form',
            category: 'Web form',
            total: 10,
            converted: 4,
            won: 3,
            lost: 2,
            rate: 40,
        },
        {
            key: 'phone',
            category: 'Phone',
            total: 0,
            converted: 0,
            won: 0,
            lost: 0,
            rate: 0,
        },
    ]);
});

test('prospect assignee chart preserves exact workload and follow-up pressure', () => {
    const chart = buildProspectAssigneeChartData([
        {
            assignee_id: 12,
            name: 'Maya',
            total: 8,
            due_today: 2,
            overdue: 3,
            won: 1,
            lost: 2,
            converted: 2,
        },
        {
            assignee_id: null,
            name: null,
            total: 2,
            due_today: 0,
            overdue: 1,
            won: 0,
            lost: 0,
            converted: 0,
        },
    ], {
        labelForAssignee: (key, row) => row.name || (key === 'unassigned' ? 'Unassigned' : key),
        totalLabel: 'Prospects',
        overdueLabel: 'Overdue',
    });

    assert.deepEqual(chart, {
        categories: ['Maya', 'Unassigned'],
        series: [
            { name: 'Prospects', data: [8, 2] },
            { name: 'Overdue', data: [3, 1] },
        ],
        details: [
            {
                key: '12',
                category: 'Maya',
                total: 8,
                dueToday: 2,
                overdue: 3,
                won: 1,
                lost: 2,
                converted: 2,
            },
            {
                key: 'unassigned',
                category: 'Unassigned',
                total: 2,
                dueToday: 0,
                overdue: 1,
                won: 0,
                lost: 0,
                converted: 0,
            },
        ],
    });
});

test('prospect assignee chart rejects incomplete or inconsistent workloads', () => {
    const validRow = {
        assignee_id: 12,
        name: 'Maya',
        total: 8,
        due_today: 2,
        overdue: 3,
        won: 1,
        lost: 2,
        converted: 2,
    };
    const invalidRows = [
        [{ ...validRow, total: '8' }],
        [{ ...validRow, due_today: 9 }],
        [{ ...validRow, overdue: 9 }],
        [{ ...validRow, won: 5, lost: 2, converted: 2 }],
        [{ ...validRow, converted: 9 }],
        [{ ...validRow, assignee_id: '12' }],
        [{ ...validRow, name: '' }],
        [{ ...validRow }, { ...validRow }],
    ];

    for (const rows of invalidRows) {
        assert.deepEqual(buildProspectAssigneeChartData(rows), emptyChartData);
    }
});

test('prospect assignee chart preserves follow-ups that are both due today and overdue', () => {
    const chart = buildProspectAssigneeChartData([{
        assignee_id: 12,
        name: 'Maya',
        total: 1,
        due_today: 1,
        overdue: 1,
        won: 0,
        lost: 0,
        converted: 0,
    }]);

    assert.deepEqual(chart.categories, ['Maya']);
    assert.deepEqual(chart.series, [
        { name: 'Prospects', data: [1] },
        { name: 'Overdue', data: [1] },
    ]);
    assert.equal(chart.details[0].dueToday, 1);
});

test('prospect assignee chart preserves distinct members who share the same display name', () => {
    const row = {
        name: 'Maya',
        total: 2,
        due_today: 0,
        overdue: 1,
        won: 0,
        lost: 0,
        converted: 0,
    };
    const chart = buildProspectAssigneeChartData([
        { ...row, assignee_id: 12 },
        { ...row, assignee_id: 13 },
    ], {
        labelForAssignee: (key, assignee) => assignee.name,
        totalLabel: 'Prospects',
        overdueLabel: 'Overdue',
    });

    assert.deepEqual(chart.categories, ['Maya (#12)', 'Maya (#13)']);
    assert.deepEqual(chart.series, [
        { name: 'Prospects', data: [2, 2] },
        { name: 'Overdue', data: [1, 1] },
    ]);
    assert.deepEqual(chart.details.map((item) => item.key), ['12', '13']);
});

test('prospect charts reject an entire incoherent dataset instead of drawing partial or reconstructed values', () => {
    const invalidStatusRows = [
        [{ status: 'REQ_NEW', total: '3' }],
        [{ status: 'REQ_NEW', total: -1 }],
        [{ status: 'REQ_NEW', total: 1.5 }],
        [{ status: 123, total: 1 }],
        [{ status: 'REQ_NEW', total: 1 }, { status: 'REQ_NEW', total: 2 }],
        [{ status: 'REQ_NEW', total: 1 }, { status: 'REQ_WON', total: 2 }],
    ];

    for (const rows of invalidStatusRows) {
        const chart = buildProspectStatusChartData(rows, {
            labelForStatus: () => 'Duplicate label',
        });

        assert.deepEqual(chart, emptyChartData);
    }

    const validRow = {
        source: 'web_form',
        total: 10,
        converted: 4,
        won: 3,
        lost: 2,
        rate: 40,
    };
    const invalidSourceRows = [
        [{ ...validRow }, { source: 'phone', total: 5, converted: 2, won: 1, lost: 1 }],
        [{ ...validRow, total: '10' }],
        [{ ...validRow, converted: 11, rate: 110 }],
        [{ ...validRow, won: 5, lost: 3 }],
        [{ ...validRow, rate: 39.9 }],
        [{ ...validRow, source: 123 }],
        [{ ...validRow }, { ...validRow }],
    ];

    for (const rows of invalidSourceRows) {
        assert.deepEqual(buildProspectSourceChartData(rows), emptyChartData);
    }
});

test('request source chart preserves exact rates and rejects mismatched ratios', () => {
    const chart = buildRequestSourceChartData([
        { source: 'referral', total: 4, won: 1, rate: 25 },
        { source: 'unknown', total: 0, won: 0, rate: 0 },
    ], {
        labelForSource: (source) => source === 'referral' ? 'Referral' : 'Unknown',
        rateLabel: 'Conversion rate',
    });

    assert.deepEqual(chart, {
        categories: ['Referral', 'Unknown'],
        series: [{ name: 'Conversion rate', data: [25, 0] }],
        details: [
            { key: 'referral', category: 'Referral', total: 4, won: 1, rate: 25 },
            { key: 'unknown', category: 'Unknown', total: 0, won: 0, rate: 0 },
        ],
    });

    const invalidRows = [
        [{ source: 'referral', total: 4, won: 1, rate: 24.9 }],
        [{ source: 'referral', total: 4, won: 5, rate: 100 }],
        [{ source: 'referral', total: 4, won: 1, rate: '25' }],
        [{ source: 123, total: 4, won: 1, rate: 25 }],
        [{ source: 'referral', total: 4, won: 1 }],
        [
            { source: 'referral', total: 4, won: 1, rate: 25 },
            { source: 'referral', total: 2, won: 1, rate: 50 },
        ],
    ];

    for (const rows of invalidRows) {
        assert.deepEqual(buildRequestSourceChartData(rows), emptyChartData);
    }
});

test('prospect dashboard lazy-loads shared accessible bars without deceptive minimum widths', () => {
    const component = read('resources/js/Pages/Request/UI/ProspectDashboardAnalytics.vue');

    assert.match(component, /const activeTab = ref\('overview'\);/u);
    assert.match(component, /v-if="activeTab === 'overview'"[\s\S]*?v-if="activeTab === 'overview' \|\| activeTab === 'pipeline'"/u);
    assert.match(component, /v-if="activeTab === 'assignees'"/u);
    assert.match(component, /defineAsyncComponent\([\s\S]*?Components\/UI\/Barchart\.vue/u);
    assert.equal((component.match(/<Barchart/gu) || []).length, 3);
    assert.match(component, /buildProspectStatusChartData\(byStatus\.value/u);
    assert.match(component, /buildProspectSourceChartData\(bySource\.value/u);
    assert.match(component, /buildProspectAssigneeChartData\(byAssignee\.value/u);
    assert.doesNotMatch(component, /unassigned' \?[^\n]*: key/u);
    assert.match(component, /<Suspense>[\s\S]*?<template #fallback>/u);
    assert.match(component, /role="status"/u);
    assert.match(component, /horizontal/u);
    assert.match(component, /:framed="false"/u);
    assert.match(component, /status_table_caption/u);
    assert.match(component, /source_table_caption/u);
    assert.match(component, /assignee_table_caption/u);
    assert.match(component, /sourceChartData\.details/u);
    assert.match(component, /assigneeChartData\.details/u);
    assert.match(component, /xaxis: \{[\s\S]*?min: 0/u);
    assert.doesNotMatch(component, /maxStatusTotal|maxSourceTotal/u);
    assert.doesNotMatch(component, /maxAssigneeTotal/u);
    assert.doesNotMatch(component, /:style="\{ width:/u);
    assert.match(component, /:aria-pressed="String\(activeTab === tab\.key\)"/u);
    assert.doesNotMatch(component, /item\.total \? 8 : 0/u);
});

test('request analytics lazy-loads the shared percent chart and retains exact source details', () => {
    const component = read('resources/js/Pages/Request/UI/RequestAnalytics.vue');

    assert.match(component, /defineAsyncComponent\([\s\S]*?Components\/UI\/Barchart\.vue/u);
    assert.match(component, /buildRequestSourceChartData\(bySource\.value/u);
    assert.match(component, /<Suspense>[\s\S]*?<Barchart/u);
    assert.match(component, /:table-caption="\$t\('requests\.analytics\.charts\.source_table_caption'\)"/u);
    assert.match(component, /sourceChartData\.details/u);
    assert.match(component, /xaxis: \{[\s\S]*?min: 0,[\s\S]*?max: 100/u);
    assert.equal(component.includes(':style="{ width: `${item.rate}%` }"'), false);
});

test('request chart copy exists in French, English, and Spanish', () => {
    const dashboardKeys = [
        'status_title',
        'status_subtitle',
        'source_title',
        'source_subtitle',
        'total_series',
        'converted_series',
        'overdue_series',
        'status_category',
        'source_category',
        'assignee_category',
        'count_value',
        'status_table_caption',
        'source_table_caption',
        'assignee_table_caption',
        'assignee_details_label',
        'source_details_label',
        'source_detail',
    ];
    const analyticsKeys = [
        'source_conversion_title',
        'source_conversion_subtitle',
        'source_rate_series',
        'source_category',
        'percent_value',
        'source_table_caption',
        'source_details_label',
        'source_detail',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/requests.json`));
        const dashboardCharts = messages.requests?.analytics?.dashboard?.charts;
        const analyticsCharts = messages.requests?.analytics?.charts;

        for (const key of dashboardKeys) {
            assert.equal(typeof dashboardCharts?.[key], 'string', `${locale}.requests.analytics.dashboard.charts.${key}`);
            assert.notEqual(dashboardCharts[key].trim(), '', `${locale}.requests.analytics.dashboard.charts.${key} is not empty`);
        }

        for (const key of analyticsKeys) {
            assert.equal(typeof analyticsCharts?.[key], 'string', `${locale}.requests.analytics.charts.${key}`);
            assert.notEqual(analyticsCharts[key].trim(), '', `${locale}.requests.analytics.charts.${key} is not empty`);
        }

        for (const placeholder of ['{converted}', '{won}', '{lost}', '{rate}']) {
            assert.equal(
                dashboardCharts.source_detail.includes(placeholder),
                true,
                `${locale}.requests.analytics.dashboard.charts.source_detail keeps ${placeholder}`,
            );
        }

        for (const placeholder of ['{won}', '{total}', '{rate}']) {
            assert.equal(
                analyticsCharts.source_detail.includes(placeholder),
                true,
                `${locale}.requests.analytics.charts.source_detail keeps ${placeholder}`,
            );
        }

        assert.equal(
            analyticsCharts.source_conversion_subtitle.includes('{days}'),
            true,
            `${locale}.requests.analytics.charts.source_conversion_subtitle keeps {days}`,
        );
    }
});
