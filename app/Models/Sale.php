<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Models\SaleItem;
use App\Models\PaymentRecord;

class Sale extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'company_id',
        'customer_id',
        'user_id',
        'sale_number',
        'subtotal',
        'discount_amount',
        'discount_percentage',
        'tax_amount',
        'total_amount',
        'total_cost',
        'profit_amount',
        'profit_margin',
        'payment_method',
        'payment_status',
        'amount_paid',
        'amount_due',
        'notes',
        'status',
        'sale_date',
    ];

    protected $casts = [
        'id' => 'string',
        'company_id' => 'string',
        'customer_id' => 'string',
        'user_id' => 'string',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'profit_amount' => 'decimal:2',
        'profit_margin' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'amount_due' => 'decimal:2',
        'sale_date' => 'datetime',
    ];

    protected $appends = [
        'display_status',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($sale) {
            if (empty($sale->sale_number)) {
                $sale->sale_number = static::generateSaleNumber($sale->company_id);
            }
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function paymentRecords()
    {
        return $this->hasMany(PaymentRecord::class);
    }

    public function getProfitMarginAttribute()
    {
        // Load sale items with their products to calculate cost
        $saleItems = $this->saleItems()->with('product')->get();

        $totalCost = 0;
        foreach ($saleItems as $item) {
            if ($item->product) {
                $totalCost += ($item->quantity * $item->product->cost_price);
            }
        }

        if ($totalCost == 0) {
            return 0;
        }

        $profit = $this->total_amount - $totalCost;
        return round(($profit / $totalCost) * 100, 2);
    }

    public function calculateAndStoreProfitMetrics()
    {
        // Get all sale items with their stored cost values
        $saleItems = $this->saleItems;

        $totalCost = $saleItems->sum('cost_total');
        $profitAmount = $this->total_amount - $totalCost;
        $profitMargin = $totalCost > 0 ? round(($profitAmount / $totalCost) * 100, 2) : 0;

        $this->update([
            'total_cost' => $totalCost,
            'profit_amount' => $profitAmount,
            'profit_margin' => $profitMargin,
        ]);

        return $this;
    }

    public function getDisplayStatusAttribute()
    {
        return ucfirst(str_replace('_', ' ', $this->status));
    }

    public function calculateTotals()
    {
        $itemsTotal = $this->saleItems()->sum('line_total');
        $subtotal = $itemsTotal;

        // Apply sale-level discount
        $discountAmount = $this->discount_amount ?? 0;
        if ($this->discount_percentage > 0) {
            $discountAmount += ($subtotal * $this->discount_percentage / 100);
        }

        $afterDiscount = $subtotal - $discountAmount;
        $taxAmount = $this->tax_amount ?? 0;
        $total = $afterDiscount + $taxAmount;

        $this->update([
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'total_amount' => $total,
            'amount_due' => $total - $this->amount_paid,
        ]);

        return $this;
    }

    public function addPayment($amount, $method, $reference = null, $notes = null, $userId = null)
    {
        $payment = $this->paymentRecords()->create([
            'company_id' => $this->company_id,
            'user_id' => $userId,
            'amount' => $amount,
            'method' => $method,
            'reference' => $reference,
            'notes' => $notes,
        ]);

        // Update sale payment status
        $totalPaid = $this->paymentRecords()->sum('amount');
        $amountDue = $this->total_amount - $totalPaid;

        $paymentStatus = 'pending';
        if ($totalPaid >= $this->total_amount) {
            $paymentStatus = 'paid';
        } elseif ($totalPaid > 0) {
            $paymentStatus = 'partial';
        }

        $this->update([
            'amount_paid' => $totalPaid,
            'amount_due' => max(0, $amountDue),
            'payment_status' => $paymentStatus,
        ]);

        return $payment;
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByPaymentStatus($query, $paymentStatus)
    {
        return $query->where('payment_status', $paymentStatus);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('sale_date', today());
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('sale_date', [now()->startOfWeek(), now()->endOfWeek()]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('sale_date', now()->month)
                    ->whereYear('sale_date', now()->year);
    }

    public static function generateSaleNumber($companyId)
    {
        $today = now()->format('Ymd');
        $prefix = 'SALE-' . $today . '-';

        $lastSale = static::where('company_id', $companyId)
            ->where('sale_number', 'like', $prefix . '%')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$lastSale) {
            return $prefix . '0001';
        }

        $lastNumber = (int) substr($lastSale->sale_number, -4);
        $newNumber = $lastNumber + 1;

        return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}
