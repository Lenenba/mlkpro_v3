<?php

namespace App\Support\CRM;

use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Work;

final class OpportunitySchema
{
    public const MODE_REQUEST_QUOTE_PROJECTION = 'request_quote_projection';

    /**
     * @return array<string, mixed>
     */
    public static function present(?LeadRequest $request, ?Quote $quote, ?Work $work = null, ?Invoice $invoice = null): array
    {
        $stage = self::stage($request, $quote, $work, $invoice);
        $amount = $quote?->total !== null ? (float) $quote->total : null;
        $nextActionAt = $quote?->next_follow_up_at ?? $request?->next_follow_up_at;
        $nextActionSource = $quote?->next_follow_up_at
            ? $quote
            : ($request?->next_follow_up_at ? $request : null);
        $crmLinks = CrmOpportunityLinking::present($request, $quote, $work, $invoice);
        $weightedAmount = $amount === null
            ? null
            : round($amount * ($stage['probability_percent'] / 100), 2);

        return [
            'mode' => self::MODE_REQUEST_QUOTE_PROJECTION,
            'key' => self::projectionKey($request, $quote),
            'is_projection' => true,
            'is_persisted' => false,
            'title' => self::title($request, $quote),
            'customer_id' => $quote?->customer_id ?? $request?->customer_id,
            'stage' => [
                'key' => $stage['key'],
                'label' => $stage['label'],
                'state' => $stage['state'],
                'rank' => $stage['rank'],
            ],
            'amount' => [
                'subtotal' => $quote?->subtotal !== null ? (float) $quote->subtotal : null,
                'total' => $amount,
                'currency_code' => $quote?->currency_code,
            ],
            'forecast' => [
                'category' => $stage['forecast_category'],
                'probability_percent' => $stage['probability_percent'],
                'amount' => $amount,
                'weighted_amount' => $weightedAmount,
            ],
            'next_action' => [
                'at' => $nextActionAt?->toIso8601String(),
                'is_overdue' => $nextActionAt ? $nextActionAt->lte(now()) : false,
                'source_type' => $nextActionSource instanceof Quote
                    ? 'quote'
                    : ($nextActionSource instanceof LeadRequest ? 'request' : null),
                'source_id' => $nextActionSource?->id,
            ],
            'anchors' => [
                'request_id' => $request?->id,
                'quote_id' => $quote?->id,
                'job_id' => $work?->id,
                'invoice_id' => $invoice?->id,
            ],
            'statuses' => [
                'request' => $request?->status,
                'quote' => $quote?->status,
                'job' => $work?->status,
                'invoice' => $invoice?->status,
            ],
            'timestamps' => [
                'opened_at' => ($request?->created_at ?? $quote?->created_at)?->toIso8601String(),
                'quoted_at' => $quote?->created_at?->toIso8601String(),
                'won_at' => ($quote?->accepted_at ?? $request?->converted_at)?->toIso8601String(),
            ],
            'validation' => [
                'mode' => OpportunityNeedValidation::MODE_REQUEST_QUOTE_FIRST,
                'requires_opportunity' => false,
            ],
            'crm_links' => $crmLinks,
        ];
    }

    private static function projectionKey(?LeadRequest $request, ?Quote $quote): ?string
    {
        if ($request?->id) {
            return sprintf('request:%s', $request->id);
        }

        if ($quote?->id) {
            return sprintf('quote:%s', $quote->id);
        }

        return null;
    }

    private static function title(?LeadRequest $request, ?Quote $quote): ?string
    {
        return $quote?->job_title
            ?: $request?->title
            ?: $request?->service_type
            ?: ($quote?->number ? sprintf('Quote %s', $quote->number) : null);
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     state: string,
     *     rank: int,
     *     forecast_category: string,
     *     probability_percent: int
     * }
     */
    private static function stage(?LeadRequest $request, ?Quote $quote, ?Work $work, ?Invoice $invoice): array
    {
        if ($quote?->status === 'declined' || $request?->status === LeadRequest::STATUS_LOST) {
            return self::stageDefinition('lost', 'Lost', 'lost', 90, 'closed_lost', 0);
        }

        if (
            $quote?->status === 'accepted'
            || in_array($request?->status, [LeadRequest::STATUS_WON, LeadRequest::STATUS_CONVERTED], true)
            || $work
            || $invoice
        ) {
            return self::stageDefinition('won', 'Won', 'won', 80, 'closed_won', 100);
        }

        if ($quote || $request?->status === LeadRequest::STATUS_QUOTE_SENT) {
            return self::stageDefinition('quoted', 'Quoted', 'open', 60, 'best_case', 75);
        }

        return match ($request?->status) {
            LeadRequest::STATUS_QUALIFIED => self::stageDefinition('qualified', 'Qualified', 'open', 40, 'pipeline', 50),
            LeadRequest::STATUS_CONTACTED, LeadRequest::STATUS_CALL_REQUESTED => self::stageDefinition('contacted', 'Contacted', 'open', 20, 'pipeline', 25),
            default => self::stageDefinition('intake', 'Intake', 'open', 10, 'pipeline', 10),
        };
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     state: string,
     *     rank: int,
     *     forecast_category: string,
     *     probability_percent: int
     * }
     */
    private static function stageDefinition(
        string $key,
        string $label,
        string $state,
        int $rank,
        string $forecastCategory,
        int $probabilityPercent
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'rank' => $rank,
            'forecast_category' => $forecastCategory,
            'probability_percent' => $probabilityPercent,
        ];
    }
}
