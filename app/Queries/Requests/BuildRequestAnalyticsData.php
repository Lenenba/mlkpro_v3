<?php

namespace App\Queries\Requests;

use App\Models\ActivityLog;
use App\Models\Request as LeadRequest;
use App\Models\TrackingEvent;
use App\Services\Requests\LeadTriageClassifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuildRequestAnalyticsData
{
    public function __construct(
        private readonly LeadTriageClassifier $classifier
    ) {
    }

    public function execute(int $accountId): array
    {
        $windowDays = 30;
        $windowStart = now()->subDays($windowDays);
        $leadForm = $this->leadFormAnalytics($accountId, $windowDays);
        $responseMetrics = $this->responseMetrics($accountId, $windowStart);
        $triageMetrics = $this->triageMetrics($accountId);

        return [
            'window_days' => $windowDays,
            'total' => $responseMetrics['total'],
            'won' => $responseMetrics['won'],
            'avg_first_response_hours' => $responseMetrics['avg_first_response_hours'],
            'avg_time_to_intake_hours' => $responseMetrics['avg_time_to_intake_hours'],
            'conversion_rate' => $responseMetrics['conversion_rate'],
            'conversion_by_source' => $responseMetrics['conversion_by_source'],
            'stale_count' => $triageMetrics['stale_count'],
            'breached_count' => $triageMetrics['breached_count'],
            'lead_form' => $leadForm,
            'risk_leads' => $triageMetrics['risk_leads'],
        ];
    }

    private function responseMetrics(int $accountId, Carbon $windowStart): array
    {
        $subjectType = (new LeadRequest())->getMorphClass();

        $firstResponseSub = ActivityLog::query()
            ->selectRaw('subject_id, MIN(created_at) as first_response_at')
            ->where('subject_type', $subjectType)
            ->where('action', '!=', 'created')
            ->groupBy('subject_id');

        $kpiLeads = LeadRequest::query()
            ->where('user_id', $accountId)
            ->where('created_at', '>=', $windowStart)
            ->leftJoinSub($firstResponseSub, 'first_responses', function ($join) {
                $join->on('requests.id', '=', 'first_responses.subject_id');
            })
            ->get([
                'requests.id',
                'requests.status',
                'requests.channel',
                'requests.created_at',
                'requests.first_response_at as stored_first_response_at',
                'first_responses.first_response_at as activity_first_response_at',
            ]);

        $legacyResponseSeconds = $kpiLeads
            ->map(fn ($lead): ?int => $this->diffInSeconds(
                $lead->created_at,
                $lead->activity_first_response_at
            ))
            ->filter(fn (?int $seconds): bool => $seconds !== null)
            ->values();

        $intakeResponseSeconds = $kpiLeads
            ->map(fn ($lead): ?int => $this->diffInSeconds(
                $lead->created_at,
                $lead->stored_first_response_at ?: $lead->activity_first_response_at
            ))
            ->filter(fn (?int $seconds): bool => $seconds !== null)
            ->values();

        $kpiTotal = $kpiLeads->count();
        $kpiWon = $kpiLeads->where('status', LeadRequest::STATUS_WON)->count();

        return [
            'total' => $kpiTotal,
            'won' => $kpiWon,
            'avg_first_response_hours' => $this->averageHours($legacyResponseSeconds),
            'avg_time_to_intake_hours' => $this->averageHours($intakeResponseSeconds),
            'conversion_rate' => $kpiTotal > 0 ? round(($kpiWon / $kpiTotal) * 100, 1) : 0,
            'conversion_by_source' => $kpiLeads
                ->groupBy(fn ($lead) => $this->normalizeChannel($lead->channel) ?: 'unknown')
                ->map(function (Collection $items, string $channel): array {
                    $total = $items->count();
                    $won = $items->where('status', LeadRequest::STATUS_WON)->count();

                    return [
                        'source' => $channel,
                        'total' => $total,
                        'won' => $won,
                        'rate' => $total > 0 ? round(($won / $total) * 100, 1) : 0,
                    ];
                })
                ->values()
                ->sortByDesc('total')
                ->values()
                ->all(),
        ];
    }

    private function triageMetrics(int $accountId): array
    {
        $openStatuses = [
            LeadRequest::STATUS_NEW,
            LeadRequest::STATUS_CALL_REQUESTED,
            LeadRequest::STATUS_CONTACTED,
            LeadRequest::STATUS_QUALIFIED,
            LeadRequest::STATUS_QUOTE_SENT,
        ];

        $now = now();

        $classifiedOpenLeads = LeadRequest::query()
            ->where('user_id', $accountId)
            ->whereIn('status', $openStatuses)
            ->with([
                'assignee.user:id,name',
                'customer:id,company_name,first_name,last_name',
            ])
            ->get()
            ->map(function (LeadRequest $lead) use ($now): array {
                $classified = $this->classifier->classify($lead, $now);

                $customerName = $lead->customer
                    ? ($lead->customer->company_name
                        ?: trim(($lead->customer->first_name ?? '').' '.($lead->customer->last_name ?? '')))
                    : null;

                return [
                    'id' => $lead->id,
                    'title' => $lead->title,
                    'service_type' => $lead->service_type,
                    'status' => $lead->status,
                    'channel' => $lead->channel,
                    'last_activity_at' => $classified['last_activity_at']?->toJSON(),
                    'next_follow_up_at' => $lead->next_follow_up_at?->toJSON(),
                    'assignee_name' => $lead->assignee?->user?->name ?? $lead->assignee?->name,
                    'customer_name' => $customerName,
                    'days_since_activity' => $classified['days_since_activity'],
                    'triage_queue' => $classified['queue'],
                    'triage_priority' => $classified['triage_priority'],
                    'risk_level' => $classified['risk_level'],
                    'is_stale' => $classified['is_stale'],
                    'is_breached' => $classified['is_breached'],
                ];
            })
            ->values();

        $staleCount = $classifiedOpenLeads->where('triage_queue', LeadTriageClassifier::QUEUE_STALE)->count();
        $breachedCount = $classifiedOpenLeads->where('triage_queue', LeadTriageClassifier::QUEUE_BREACHED)->count();

        $riskLeads = $classifiedOpenLeads
            ->filter(fn (array $lead): bool => $lead['triage_queue'] === LeadTriageClassifier::QUEUE_BREACHED
                || $lead['triage_queue'] === LeadTriageClassifier::QUEUE_STALE)
            ->sort(function (array $left, array $right): int {
                $queueComparison = $this->riskQueueRank($left['triage_queue'])
                    <=> $this->riskQueueRank($right['triage_queue']);
                if ($queueComparison !== 0) {
                    return $queueComparison;
                }

                $priorityComparison = ((int) $right['triage_priority']) <=> ((int) $left['triage_priority']);
                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                return ((int) ($right['days_since_activity'] ?? 0)) <=> ((int) ($left['days_since_activity'] ?? 0));
            })
            ->values()
            ->take(10)
            ->all();

        return [
            'stale_count' => $staleCount,
            'breached_count' => $breachedCount,
            'risk_leads' => $riskLeads,
        ];
    }

    private function leadFormAnalytics(int $accountId, int $windowDays): array
    {
        $formWindowStart = now()->subDays($windowDays)->startOfDay();
        $viewsQuery = TrackingEvent::query()
            ->where('event_type', 'lead_form_view')
            ->where('user_id', $accountId);
        $submitsQuery = TrackingEvent::query()
            ->where('event_type', 'lead_form_submit')
            ->where('user_id', $accountId);

        $formViews = (clone $viewsQuery)
            ->where('created_at', '>=', $formWindowStart)
            ->count();
        $formUniqueViews = (clone $viewsQuery)
            ->where('created_at', '>=', $formWindowStart)
            ->whereNotNull('visitor_hash')
            ->distinct('visitor_hash')
            ->count('visitor_hash');
        $formSubmits = (clone $submitsQuery)
            ->where('created_at', '>=', $formWindowStart)
            ->count();
        $formConversion = $formViews > 0 ? round(($formSubmits / $formViews) * 100, 1) : 0;
        $lastFormView = (clone $viewsQuery)->latest('created_at')->first(['created_at']);
        $lastFormSubmit = (clone $submitsQuery)->latest('created_at')->first(['created_at']);

        return [
            'window_days' => $windowDays,
            'views' => $formViews,
            'unique_views' => $formUniqueViews,
            'submits' => $formSubmits,
            'conversion_rate' => $formConversion,
            'last_view_at' => $lastFormView?->created_at?->toJSON(),
            'last_submit_at' => $lastFormSubmit?->created_at?->toJSON(),
            'top_referrers' => $this->topTrackingValues($accountId, 'lead_form_view', 'referrer_host', $formWindowStart),
            'top_utm_sources' => $this->topTrackingValues($accountId, 'lead_form_view', 'utm_source', $formWindowStart),
            'top_utm_mediums' => $this->topTrackingValues($accountId, 'lead_form_view', 'utm_medium', $formWindowStart),
            'top_utm_campaigns' => $this->topTrackingValues($accountId, 'lead_form_view', 'utm_campaign', $formWindowStart),
        ];
    }

    private function topTrackingValues(int $accountId, string $eventType, string $key, Carbon $since, int $limit = 5): array
    {
        $path = sprintf('$.%s', $key);
        $driver = DB::connection()->getDriverName();
        $selector = $driver === 'sqlite'
            ? "json_extract(meta, '{$path}')"
            : "JSON_UNQUOTE(JSON_EXTRACT(meta, '{$path}'))";

        return TrackingEvent::query()
            ->selectRaw("{$selector} as value, COUNT(*) as count")
            ->where('event_type', $eventType)
            ->where('user_id', $accountId)
            ->where('created_at', '>=', $since)
            ->whereRaw("{$selector} IS NOT NULL")
            ->groupBy('value')
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->filter(fn ($row) => $row->value !== null && $row->value !== '')
            ->map(fn ($row) => ['value' => $row->value, 'count' => (int) $row->count])
            ->values()
            ->all();
    }

    private function diffInSeconds(mixed $createdAt, mixed $responseAt): ?int
    {
        if (blank($createdAt) || blank($responseAt)) {
            return null;
        }

        $created = Carbon::parse($createdAt);
        $response = Carbon::parse($responseAt);

        return $response->greaterThan($created)
            ? $response->diffInSeconds($created)
            : 0;
    }

    private function averageHours(Collection $seconds): ?float
    {
        if ($seconds->isEmpty()) {
            return null;
        }

        return round(((float) $seconds->avg()) / 3600, 1);
    }

    private function normalizeChannel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(strtolower($value));

        return $normalized === '' ? null : $normalized;
    }

    private function riskQueueRank(string $queue): int
    {
        return match ($queue) {
            LeadTriageClassifier::QUEUE_BREACHED => 0,
            LeadTriageClassifier::QUEUE_STALE => 1,
            default => 2,
        };
    }
}
