<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Company;
use App\Models\Sale;
use App\Models\User;

class PaymentRecord extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'company_id',
        'sale_id',
        'user_id',
        'amount',
        'method',
        'reference',
        'notes',
        'payment_date',
    ];

    protected $casts = [
        'id' => 'string',
        'company_id' => 'string',
        'sale_id' => 'string',
        'user_id' => 'string',
        'amount' => 'decimal:2',
        'payment_date' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getDisplayMethodAttribute()
    {
        return ucfirst(str_replace('_', ' ', $this->method));
    }
}
