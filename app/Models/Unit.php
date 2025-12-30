<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Unit extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'company_id',
        'name',
        'symbol',
        'type',
        'allow_decimals',
        'decimal_places',
        'active',
    ];

    protected $casts = [
        'id' => 'string',
        'company_id' => 'string',
        'allow_decimals' => 'boolean',
        'decimal_places' => 'integer',
        'active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'unit_id');
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class, 'unit_id');
    }

    // Format quantity according to unit's decimal rules
    public function formatQuantity($quantity)
    {
        if (!$this->allow_decimals) {
            return (int) $quantity;
        }

        return round($quantity, $this->decimal_places);
    }

    public function getDisplayNameAttribute()
    {
        return "{$this->name} ({$this->symbol})";
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    // Seed default units for a company
    public static function seedDefaultUnits($companyId)
    {
        $defaultUnits = [
            // Count units (no decimals)
            ['name' => 'Pieces', 'symbol' => 'pcs', 'type' => 'count', 'allow_decimals' => false, 'decimal_places' => 0],
            ['name' => 'Dozen', 'symbol' => 'doz', 'type' => 'count', 'allow_decimals' => false, 'decimal_places' => 0],
            ['name' => 'Box', 'symbol' => 'box', 'type' => 'count', 'allow_decimals' => false, 'decimal_places' => 0],
            ['name' => 'Pack', 'symbol' => 'pack', 'type' => 'count', 'allow_decimals' => false, 'decimal_places' => 0],

            // Weight units (with decimals)
            ['name' => 'Kilogram', 'symbol' => 'kg', 'type' => 'weight', 'allow_decimals' => true, 'decimal_places' => 3],
            ['name' => 'Gram', 'symbol' => 'g', 'type' => 'weight', 'allow_decimals' => true, 'decimal_places' => 2],
            ['name' => 'Pound', 'symbol' => 'lb', 'type' => 'weight', 'allow_decimals' => true, 'decimal_places' => 3],
            ['name' => 'Ounce', 'symbol' => 'oz', 'type' => 'weight', 'allow_decimals' => true, 'decimal_places' => 2],

            // Volume units (with decimals)
            ['name' => 'Liter', 'symbol' => 'L', 'type' => 'volume', 'allow_decimals' => true, 'decimal_places' => 3],
            ['name' => 'Milliliter', 'symbol' => 'ml', 'type' => 'volume', 'allow_decimals' => true, 'decimal_places' => 2],
            ['name' => 'Gallon', 'symbol' => 'gal', 'type' => 'volume', 'allow_decimals' => true, 'decimal_places' => 3],
            ['name' => 'Quart', 'symbol' => 'qt', 'type' => 'volume', 'allow_decimals' => true, 'decimal_places' => 3],

            // Length units (with decimals)
            ['name' => 'Meter', 'symbol' => 'm', 'type' => 'length', 'allow_decimals' => true, 'decimal_places' => 3],
            ['name' => 'Centimeter', 'symbol' => 'cm', 'type' => 'length', 'allow_decimals' => true, 'decimal_places' => 2],
            ['name' => 'Foot', 'symbol' => 'ft', 'type' => 'length', 'allow_decimals' => true, 'decimal_places' => 2],
            ['name' => 'Inch', 'symbol' => 'in', 'type' => 'length', 'allow_decimals' => true, 'decimal_places' => 2],
            ['name' => 'Yard', 'symbol' => 'yd', 'type' => 'length', 'allow_decimals' => true, 'decimal_places' => 3],

            // Area units
            ['name' => 'Square Meter', 'symbol' => 'm²', 'type' => 'area', 'allow_decimals' => true, 'decimal_places' => 3],
            ['name' => 'Square Foot', 'symbol' => 'ft²', 'type' => 'area', 'allow_decimals' => true, 'decimal_places' => 2],

            // Custom units
            ['name' => 'Roll', 'symbol' => 'roll', 'type' => 'custom', 'allow_decimals' => false, 'decimal_places' => 0],
            ['name' => 'Sheet', 'symbol' => 'sheet', 'type' => 'custom', 'allow_decimals' => false, 'decimal_places' => 0],
            ['name' => 'Bottle', 'symbol' => 'btl', 'type' => 'custom', 'allow_decimals' => false, 'decimal_places' => 0],
            ['name' => 'Can', 'symbol' => 'can', 'type' => 'custom', 'allow_decimals' => false, 'decimal_places' => 0],
            ['name' => 'Tube', 'symbol' => 'tube', 'type' => 'custom', 'allow_decimals' => false, 'decimal_places' => 0],
            ['name' => 'Bag', 'symbol' => 'bag', 'type' => 'custom', 'allow_decimals' => false, 'decimal_places' => 0],
        ];

        foreach ($defaultUnits as $unit) {
            Unit::create(array_merge($unit, ['company_id' => $companyId]));
        }
    }
}
