<?php

namespace App\Support\CRM;

use App\Models\Quote;
use App\Models\Request as LeadRequest;

final class OpportunityNeedValidation
{
    public const MODE_REQUEST_QUOTE_FIRST = 'request_quote_first';

    /**
     * @return array<string, mixed>
     */
    public static function present(?LeadRequest $request, ?Quote $quote): array
    {
        return [
            'validated' => true,
            'mode' => self::MODE_REQUEST_QUOTE_FIRST,
            'requires_opportunity' => false,
            'decision' => 'defer_opportunity_model',
            'confidence' => 'high',
            'current_anchor' => self::currentAnchor($request, $quote),
            'forecast_anchor' => self::forecastAnchor($quote),
            'next_action_anchor' => self::nextActionAnchor($request, $quote),
            'reason_codes' => [
                'request_quote_chain_covers_pipeline',
                'request_quote_next_actions_cover_follow_up',
                'quote_totals_cover_v1_forecast_basis',
            ],
            'reasons' => [
                'The commercial flow already runs through the request to quote chain.',
                'Next actions and activity tracking still fit inside Request, Quote, and ActivityLog.',
                'Simple forecast inputs can be anchored on quote totals without a separate opportunity model.',
            ],
            'promotion_triggers' => [
                [
                    'code' => 'parallel_active_opportunities_outside_request',
                    'label' => 'A customer carries multiple active commercial tracks outside the current request and quote chain.',
                ],
                [
                    'code' => 'pre_quote_pipeline_needs_independent_ownership',
                    'label' => 'Commercial stages before or beyond the quote need separate ownership, stage management, or history.',
                ],
                [
                    'code' => 'forecast_and_next_actions_no_longer_fit_request_quote',
                    'label' => 'Forecast, next actions, and activity linkage no longer fit cleanly inside Request, Quote, and ActivityLog.',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function currentAnchor(?LeadRequest $request, ?Quote $quote): ?array
    {
        if ($quote) {
            return [
                'type' => 'quote',
                'id' => $quote->id,
                'status' => $quote->status,
            ];
        }

        if ($request) {
            return [
                'type' => 'request',
                'id' => $request->id,
                'status' => $request->status,
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function forecastAnchor(?Quote $quote): ?array
    {
        if (! $quote || $quote->total === null) {
            return null;
        }

        return [
            'type' => 'quote',
            'id' => $quote->id,
            'status' => $quote->status,
            'amount' => (float) $quote->total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function nextActionAnchor(?LeadRequest $request, ?Quote $quote): ?array
    {
        if ($quote?->next_follow_up_at) {
            return [
                'type' => 'quote',
                'id' => $quote->id,
                'at' => $quote->next_follow_up_at->toIso8601String(),
            ];
        }

        if ($request?->next_follow_up_at) {
            return [
                'type' => 'request',
                'id' => $request->id,
                'at' => $request->next_follow_up_at->toIso8601String(),
            ];
        }

        return null;
    }
}
