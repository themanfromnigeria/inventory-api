<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Company;
use App\Models\Sale;
use App\Models\Product;
use App\Models\Unit;

class SaleItem extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'company_id',
        'sale_id',
        'product_id',
        'unit_id',
        'product_name',
        'product_sku',
        'quantity',
        'unit_price',
        'cost_price',
        'cost_total',
        'discount_amount',
        'discount_percentage',
        'line_total',
        'profit_amount',
    ];

    protected $casts = [
        'id' => 'string',
        'company_id' => 'string',
        'sale_id' => 'string',
        'product_id' => 'string',
        'unit_id' => 'string',
        'quantity' => 'decimal:6',
        'unit_price' => 'decimal:4',
        'cost_price' => 'decimal:4',
        'cost_total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'line_total' => 'decimal:2',
        'profit_amount' => 'decimal:2',
    ];

    protected $appends = [
        'display_quantity',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function getCostTotalAttribute()
    {
        if (!$this->product) {
            return 0;
        }

        return $this->quantity * $this->product->cost_price;
    }

    public function getProfitAttribute()
    {
        return $this->line_total - $this->cost_total;
    }

    public function getDisplayQuantityAttribute()
    {
        $quantity = $this->unit ? $this->unit->formatQuantity($this->quantity) : $this->quantity;
        $symbol = $this->unit ? $this->unit->symbol : '';

        return $quantity . ($symbol ? " {$symbol}" : '');
    }

    public function calculateAndStoreValues($product)
    {
        // Calculate line total
        $subtotal = $this->quantity * $this->unit_price;
        $discountAmount = $this->discount_amount ?? 0;
        if ($this->discount_percentage > 0) {
            $discountAmount += ($subtotal * $this->discount_percentage / 100);
        }
        $lineTotal = $subtotal - $discountAmount;

        // Calculate cost values
        $costPrice = $product->cost_price;
        $costTotal = $this->quantity * $costPrice;
        $profitAmount = $lineTotal - $costTotal;

        $this->update([
            'cost_price' => $costPrice,
            'cost_total' => $costTotal,
            'line_total' => $lineTotal,
            'profit_amount' => $profitAmount,
        ]);

        return $this;
    }

    public function calculateLineTotal()
    {
        $subtotal = $this->quantity * $this->unit_price;

        $discountAmount = $this->discount_amount ?? 0;
        if ($this->discount_percentage > 0) {
            $discountAmount += ($subtotal * $this->discount_percentage / 100);
        }

        $lineTotal = $subtotal - $discountAmount;

        $this->update(['line_total' => $lineTotal]);

        return $lineTotal;
    }
}
