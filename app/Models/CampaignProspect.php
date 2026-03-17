<?php

namespace App\Models;

use App\Models\Request as LeadRequest;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignProspect extends Model
{
    use HasFactory;

    public const SOURCE_CSV = 'csv';

    public const SOURCE_CONNECTOR = 'connector';

    public const SOURCE_LANDING_PAGE = 'landing_page';

    public const SOURCE_DIRECTORY_API = 'directory_api';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_ADS = 'ads';

    public const SOURCE_IMPORT = 'import';

    public const STATUS_NEW = 'new';

    public const STATUS_ENRICHED = 'enriched';

    public const STATUS_SCORED = 'scored';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_FOLLOW_UP_DUE = 'follow_up_due';

    public const STATUS_REPLIED = 'replied';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_CONVERTED_TO_LEAD = 'converted_to_lead';

    public const STATUS_CONVERTED_TO_CUSTOMER = 'converted_to_customer';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_DISQUALIFIED = 'disqualified';

    public const STATUS_DO_NOT_CONTACT = 'do_not_contact';

    public const MATCH_NONE = 'none';

    public const MATCH_CUSTOMER = 'matched_customer';

    public const MATCH_LEAD = 'matched_lead';

    public const MATCH_PROSPECT = 'matched_prospect';

    public const MATCH_BLOCKED_DESTINATION = 'blocked_destination';

    public const MATCH_MANUAL_REVIEW = 'manual_review_required';

    protected $fillable = [
        'campaign_id',
        'campaign_prospect_batch_id',
        'user_id',
        'source_type',
        'source_reference',
        'external_ref',
        'company_name',
        'contact_name',
        'first_name',
        'last_name',
        'email',
        'email_normalized',
        'phone',
        'phone_normalized',
        'website',
        'website_domain',
        'city',
        'state',
        'country',
        'industry',
        'company_size',
        'tags',
        'raw_payload',
        'normalized_payload',
        'fit_score',
        'intent_score',
        'priority_score',
        'qualification_summary',
        'status',
        'match_status',
        'matched_customer_id',
        'matched_lead_id',
        'converted_to_lead_id',
        'converted_to_customer_id',
        'first_contacted_at',
        'last_contacted_at',
        'last_replied_at',
        'last_activity_at',
        'do_not_contact',
        'blocked_reason',
        'owner_notes',
        'metadata',
    ];

    protected $casts = [
        'tags' => 'array',
        'raw_payload' => 'array',
        'normalized_payload' => 'array',
        'metadata' => 'array',
        'do_not_contact' => 'boolean',
        'first_contacted_at' => 'datetime',
        'last_contacted_at' => 'datetime',
        'last_replied_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CampaignProspectBatch::class, 'campaign_prospect_batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matchedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'matched_customer_id');
    }

    public function matchedLead(): BelongsTo
    {
        return $this->belongsTo(LeadRequest::class, 'matched_lead_id');
    }

    public function convertedLead(): BelongsTo
    {
        return $this->belongsTo(LeadRequest::class, 'converted_to_lead_id');
    }

    public function convertedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_to_customer_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CampaignProspectActivity::class, 'campaign_prospect_id')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }

    public static function allowedSourceTypes(): array
    {
        return [
            self::SOURCE_CSV,
            self::SOURCE_CONNECTOR,
            self::SOURCE_LANDING_PAGE,
            self::SOURCE_DIRECTORY_API,
            self::SOURCE_MANUAL,
            self::SOURCE_ADS,
            self::SOURCE_IMPORT,
        ];
    }
}
