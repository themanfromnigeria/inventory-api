<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\PurchaseItem;

class Purchase extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'company_id',
        'supplier_id',
        'purchase_number',
        'purchase_date',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'status',
        'notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($purchase) {
            if (!$purchase->purchase_number) {
                $purchase->purchase_number = static::generatePurchaseNumber($purchase->company_id);
            }
        });
    }

    public static function generatePurchaseNumber($companyId)
    {
        $date = now()->format('Ymd');
        $lastPurchase = static::where('company_id', $companyId)
            ->where('purchase_number', 'like', "PUR-{$date}-%")
            ->orderBy('purchase_number', 'desc')
            ->first();

        if ($lastPurchase) {
            $lastNumber = (int) substr($lastPurchase->purchase_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return "PUR-{$date}-" . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}
