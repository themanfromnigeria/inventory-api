<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Company;
use App\Models\Category;
use App\Models\Unit;
use App\Models\StockMovement;

class Product extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'company_id',
        'category_id',
        'name',
        'description',
        'sku',
        'barcode',
        'image_url',
        'cost_price',
        'selling_price',
        'stock_quantity',
        'minimum_stock',
        'track_stock',
        'active',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'minimum_stock' => 'integer',
        'maximum_stock' => 'integer',
        'track_stock' => 'boolean',
        'active' => 'boolean',
    ];

    protected $appends = [
        'is_low_stock',
        'profit_margin',
        'stock_status',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class)->orderBy('created_at', 'desc');
    }

    public function isActive()
    {
        return $this->active === true;
    }

    public function getIsLowStockAttribute()
    {
        if (!$this->track_stock) {
            return false;
        }

        return $this->stock_quantity <= $this->minimum_stock;
    }

    public function getProfitMarginAttribute()
    {
        if ($this->cost_price == 0) {
            return 0;
        }

        return round((($this->selling_price - $this->cost_price) / $this->cost_price) * 100, 2);
    }

    public function getStockStatusAttribute()
    {
        if (!$this->track_stock) {
            return 'untracked';
        }

        if ($this->stock_quantity == 0) {
            return 'out_of_stock';
        }

        if ($this->is_low_stock) {
            return 'low_stock';
        }

        if ($this->maximum_stock && $this->stock_quantity >= $this->maximum_stock) {
            return 'overstock';
        }

        return 'in_stock';
    }

    public function getDisplayStockAttribute()
    {
        if (!$this->unit) {
            return $this->formatQuantity($this->stock_quantity);
        }

        $quantity = $this->unit->formatQuantity($this->stock_quantity);
        $symbol = $this->unit->symbol;

        return "{$quantity} {$symbol}";
    }

    public function formatQuantity($quantity)
    {
        if (!$this->unit) {
            return rtrim(rtrim(number_format($quantity, 6), '0'), '.');
        }

        return $this->unit->formatQuantity($quantity);
    }

    public function updateStock($quantity, $type = 'adjustment', $userId = null, $reference = null, $notes = null)
    {
        if (!$this->track_stock) {
            return false;
        }

        $stockBefore = $this->stock_quantity;
        $stockAfter = $stockBefore + $quantity;

        // Prevent negative stock
        if ($stockAfter < 0) {
            $stockAfter = 0;
            $quantity = -$stockBefore;
        }

        $this->update(['stock_quantity' => $stockAfter]);

        // Log stock movement with unit
        if ($quantity != 0) {
            StockMovement::create([
                'company_id' => $this->company_id,
                'product_id' => $this->id,
                'user_id' => $userId,
                'type' => $quantity > 0 ? 'in' : 'out',
                'quantity' => $quantity,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'unit_id' => $this->unit_id, // Track unit used
                'reference_type' => $reference['type'] ?? $type,
                'reference_id' => $reference['id'] ?? null,
                'notes' => $notes,
            ]);
        }

        return true;
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeLowStock($query)
    {
        return $query->where('track_stock', true)
                    ->whereColumn('stock_quantity', '<=', 'minimum_stock');
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('track_stock', true)
                    ->where('stock_quantity', 0);
    }

    public function scopeInStock($query)
    {
        return $query->where('track_stock', true)
                    ->where('stock_quantity', '>', 0);
    }

    public function scopeByUnit($query, $unitId)
    {
        return $query->where('unit_id', $unitId);
    }
}
