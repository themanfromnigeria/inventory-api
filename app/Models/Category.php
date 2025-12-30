<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Company;
use App\Models\Product;

class Category extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'active',
    ];

    protected $casts = [
        'id' => 'string',
        'company_id' => 'string',
        'active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function activeProducts()
    {
        return $this->hasMany(Product::class)->where('active', true);
    }

    public function isActive()
    {
        return $this->active === true;
    }

    public function getProductCountAttribute()
    {
        return $this->products()->count();
    }

    public function getActiveProductCountAttribute()
    {
        return $this->activeProducts()->count();
    }
}
