<?php

namespace App\Models;

use App\Models\Unit;
use App\Models\User;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockMovement extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'company_id',
        'product_id',
        'user_id',
        'type',
        'quantity',
        'stock_before',
        'stock_after',
        'unit_id',
        'reference_type',
        'reference_id',
        'notes',
    ];

    protected $casts = [
        'id' => 'string',
        'company_id' => 'string',
        'product_id' => 'string',
        'user_id' => 'string',
        'unit_id' => 'string',
        'reference_id' => 'string',
        'quantity' => 'decimal:6', // Support fractional quantities
        'stock_before' => 'decimal:6',
        'stock_after' => 'decimal:6',
    ];

    protected $appends = [
        'formatted_quantity',
        'display_movement',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function getFormattedQuantityAttribute()
    {
        $sign = $this->quantity > 0 ? '+' : '';
        $quantity = rtrim(rtrim(number_format($this->quantity, 6), '0'), '.');
        return $sign . $quantity;
    }

    public function getDisplayMovementAttribute()
    {
        $quantity = $this->formatted_quantity;
        $symbol = $this->unit ? $this->unit->symbol : 'units';

        return "{$quantity} {$symbol}";
    }
}
