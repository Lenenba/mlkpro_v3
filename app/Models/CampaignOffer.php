<?php

namespace App\Models;

use App\Enums\OfferType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'offer_type',
        'offer_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'offer_id');
    }

    public function scopeProducts($query)
    {
        return $query->where('offer_type', OfferType::PRODUCT->value);
    }

    public function scopeServices($query)
    {
        return $query->where('offer_type', OfferType::SERVICE->value);
    }
}

