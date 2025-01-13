<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'type', 'address', 'city', 'state', 'zip',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
