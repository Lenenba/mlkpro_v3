<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuoteTax extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_id',
        'tax_id',
        'rate',
        'amount',
    ];

    /**
     * Relation : Cette taxe appartient à un devis.
     */
    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * Relation : Cette taxe appartient a une taxe definie.
     */
    public function tax()
    {
        return $this->belongsTo(Tax::class);
    }

    /**
     * Calculer le montant de la taxe.
     *
     * @param float $subtotal
     */
    public function calculateAmount($subtotal)
    {
        $this->amount = $subtotal * ($this->rate / 100);
        $this->save();
    }
}
