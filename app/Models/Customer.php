<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Company;
use App\Models\Sale;

class Customer extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'phone',
        'address',
        'customer_code',
        'type',
        'tax_number',
        'total_spent',
        'total_orders',
        'last_order_at',
        'active',
    ];

    protected $casts = [
        'id' => 'string',
        'company_id' => 'string',
        'total_spent' => 'decimal:2',
        'total_orders' => 'integer',
        'last_order_at' => 'datetime',
        'active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($customer) {
            if (empty($customer->customer_code)) {
                $customer->customer_code = static::generateCustomerCode($customer->company_id);
            }
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function completedSales()
    {
        return $this->hasMany(Sale::class)->where('status', 'completed');
    }

    public function updateTotals()
    {
        $stats = $this->completedSales()
            ->selectRaw('COUNT(*) as total_orders, SUM(total_amount) as total_spent, MAX(sale_date) as last_order')
            ->first();

        $this->update([
            'total_orders' => $stats->total_orders ?? 0,
            'total_spent' => $stats->total_spent ?? 0,
            'last_order_at' => $stats->last_order,
        ]);
    }

    public function isActive()
    {
        return $this->active === true;
    }

    public function getDisplayNameAttribute()
    {
        return $this->name . ($this->customer_code ? " ({$this->customer_code})" : '');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public static function generateCustomerCode($companyId)
    {
        $lastCustomer = static::where('company_id', $companyId)
            ->whereNotNull('customer_code')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$lastCustomer) {
            return 'CUST-0001';
        }

        $lastNumber = (int) substr($lastCustomer->customer_code, 5);
        $newNumber = $lastNumber + 1;

        return 'CUST-' . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}
