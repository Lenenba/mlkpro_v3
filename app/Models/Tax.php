<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tax extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'rate',
    ];

    /**
     * Relation : Une taxe par défaut peut être utilisée par plusieurs devis via quote_taxes.
     */
    public function quoteTaxes()
    {
        return $this->hasMany(QuoteTax::class);
    }
}
