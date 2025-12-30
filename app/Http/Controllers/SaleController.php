<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sale;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\SaleItem;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function getSales(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $perPage = min($request->get('per_page', 15), 100);
        $search = $request->get('search');
        $customerId = $request->get('customer_id');
        $status = $request->get('status');
        $paymentStatus = $request->get('payment_status');
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');
        $sortBy = $request->get('sort_by', 'sale_date');
        $sortOrder = $request->get('sort_order', 'desc');

        $query = Sale::where('company_id', $companyId)
            ->with([
                'customer:id,name,customer_code',
                'user:id,first_name,last_name',
                'saleItems.product:id,name,sku',
                'saleItems.unit:id,name,symbol',
            ]);

        // Apply filters
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('sale_number', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%")
                        ->orWhere('customer_code', 'like', "%{$search}%");
                  });
            });
        }

        if ($customerId) {
            $query->where('customer_id', $customerId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($paymentStatus) {
            $query->where('payment_status', $paymentStatus);
        }

        if ($dateFrom) {
            $query->whereDate('sale_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('sale_date', '<=', $dateTo);
        }

        // Apply sorting
        $allowedSorts = ['sale_date', 'sale_number', 'total_amount', 'status', 'payment_status'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        }

        $sales = $query->paginate($perPage);

        return response()->json([
            $sales
        ]);
    }

    public function addSale(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $validator = Validator::make($request->all(), [
            'customer_id' => ['nullable', 'string', 'exists:customers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', 'string', 'in:cash,card,bank_transfer,cheque,other'],
            'amount_paid' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'sale_date' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Validate customer belongs to company if provided
        if ($request->customer_id) {
            $customerExists = Customer::where('id', $request->customer_id)
                ->where('company_id', $companyId)
                ->exists();

            if (!$customerExists) {
                return response()->json([
                    'message' => 'Selected customer does not belong to your company'
                ], 422);
            }
        }

        // Validate all products belong to company and have sufficient stock
        $productIds = collect($request->items)->pluck('product_id')->unique();
        $products = Product::where('company_id', $companyId)
            ->whereIn('id', $productIds)
            ->with('unit')
            ->get()
            ->keyBy('id');

        foreach ($request->items as $itemData) {
            $product = $products->get($itemData['product_id']);

            if (!$product) {
                return response()->json([
                    'message' => "Product {$itemData['product_id']} does not belong to your company"
                ], 422);
            }

            if ($product->track_stock && $product->stock_quantity < $itemData['quantity']) {
                return response()->json([
                    'message' => "Insufficient stock for {$product->name}. Available: {$product->stock_quantity}, Required: {$itemData['quantity']}"
                ], 422);
            }
        }

        try {
            DB::beginTransaction();

            // Create the sale
            $sale = Sale::create([
                'company_id' => $companyId,
                'customer_id' => $request->customer_id,
                'user_id' => $user->id,
                'discount_amount' => $request->discount_amount ?? 0,
                'discount_percentage' => $request->discount_percentage ?? 0,
                'tax_amount' => $request->tax_amount ?? 0,
                'payment_method' => $request->payment_method,
                'amount_paid' => $request->amount_paid,
                'notes' => $request->notes,
                'sale_date' => $request->sale_date ?? now(),
                'status' => 'completed',
            ]);

            // Create sale items and update stock
            foreach ($request->items as $itemData) {
                $product = $products->get($itemData['product_id']);

                $saleItem = SaleItem::create([
                    'company_id' => $companyId,
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'unit_id' => $product->unit_id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'discount_amount' => $itemData['discount_amount'] ?? 0,
                    'discount_percentage' => $itemData['discount_percentage'] ?? 0,
                    'cost_price' => $product->cost_price,
                    'cost_total' => $itemData['quantity'] * $product->cost_price,
                    'line_total' => 0,
                    'profit_amount' => 0,
                ]);

                // Calculate line total
                // $saleItem->calculateLineTotal();
                $saleItem->calculateAndStoreValues($product);

                // Update product stock
                if ($product->track_stock) {
                    $product->updateStock(
                        -$itemData['quantity'],
                        'sale',
                        $user->id,
                        ['type' => 'sale', 'id' => $sale->id],
                        "Sold via {$sale->sale_number}"
                    );
                }
            }

            // Calculate sale totals
            $sale->calculateTotals();

            // Update payment status
            $paymentStatus = $sale->amount_paid >= $sale->total_amount ? 'paid' :
                           ($sale->amount_paid > 0 ? 'partial' : 'pending');

            $sale->update([
                'payment_status' => $paymentStatus,
                'amount_due' => max(0, $sale->total_amount - $sale->amount_paid)
            ]);

            // Update customer totals if customer exists
            if ($sale->customer_id) {
                $sale->customer->updateTotals();
            }

            DB::commit();

            return response()->json([
                'message' => 'Sale created successfully',
                'data' => $sale->load([
                    'customer:id,name,customer_code',
                    'saleItems.product:id,name',
                    'saleItems.unit:id,name,symbol'
                ])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Sale creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Sale creation failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $sale = Sale::where('id', $id)
            ->where('company_id', $companyId)
            ->with([
                'customer:id,name,customer_code,email,phone',
                'user:id,first_name,last_name',
                'saleItems.product:id,name,sku',
                'saleItems.unit:id,name,symbol',
                'paymentRecords.user:id,first_name,last_name'
            ])
            ->first();

        if (!$sale) {
            Log::warning('Sale not found', [
                'sale_id' => $id,
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Sale not found'
            ], 404);
        }

        Log::info('Sale viewed', [
            'sale_id' => $id,
            'user_id' => $user->id
        ]);

        return response()->json([
            'sale' => $sale
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        Log::info('Sale update attempt', [
            'sale_id' => $id,
            'user_id' => $user->id
        ]);

        $sale = Sale::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$sale) {
            return response()->json([
                'message' => 'Sale not found'
            ], 404);
        }

        if ($sale->status === 'refunded') {
            return response()->json([
                'message' => 'Cannot update refunded sale'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'customer_id' => ['sometimes', 'nullable', 'string', 'exists:customers,id'],
            'discount_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'discount_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'tax_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'payment_method' => ['sometimes', 'string', 'in:cash,card,bank_transfer,cheque,other'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'string', 'in:completed,pending,cancelled'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $sale->update($validator->validated());

            // Recalculate totals if discount or tax changed
            if ($request->has(['discount_amount', 'discount_percentage', 'tax_amount'])) {
                $sale->calculateTotals();
            }

            // Update customer totals if customer changed
            if ($request->has('customer_id')) {
                if ($sale->getOriginal('customer_id')) {
                    Customer::find($sale->getOriginal('customer_id'))->updateTotals();
                }
                if ($sale->customer_id) {
                    $sale->customer->updateTotals();
                }
            }

            DB::commit();

            Log::info('Sale updated successfully', [
                'sale_id' => $id,
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Sale updated successfully',
                'sale' => $sale->fresh()->load(['customer:id,name,customer_code', 'saleItems.product:id,name', 'saleItems.unit:id,name,symbol'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Sale update failed', [
                'sale_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Sale update failed. Please try again.'
            ], 500);
        }
    }

    public function addPayment(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        Log::info('Add payment attempt', [
            'sale_id' => $id,
            'user_id' => $user->id
        ]);

        $sale = Sale::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$sale) {
            return response()->json([
                'message' => 'Sale not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:0.01', "max:{$sale->amount_due}"],
            'method' => ['required', 'string', 'in:cash,card,bank_transfer,cheque,other'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.required' => 'Payment amount is required',
            'amount.min' => 'Payment amount must be greater than 0',
            'amount.max' => "Payment amount cannot exceed amount due ({$sale->amount_due})",
            'method.required' => 'Payment method is required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $payment = $sale->addPayment(
                $request->amount,
                $request->method,
                $request->reference,
                $request->notes,
                $user->id
            );

            Log::info('Payment added successfully', [
                'sale_id' => $id,
                'payment_id' => $payment->id,
                'amount' => $request->amount,
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Payment added successfully',
                'payment' => $payment,
                'sale' => $sale->fresh(['paymentRecords'])
            ], 201);

        } catch (\Exception $e) {
            Log::error('Add payment failed', [
                'sale_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Payment addition failed. Please try again.'
            ], 500);
        }
    }

    public function refund(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        Log::info('Sale refund attempt', [
            'sale_id' => $id,
            'user_id' => $user->id
        ]);

        $sale = Sale::where('id', $id)
            ->where('company_id', $companyId)
            ->with('saleItems.product')
            ->first();

        if (!$sale) {
            return response()->json([
                'message' => 'Sale not found'
            ], 404);
        }

        if ($sale->status === 'refunded') {
            return response()->json([
                'message' => 'Sale is already refunded'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'refund_amount' => ['required', 'numeric', 'min:0.01', "max:{$sale->total_amount}"],
            'reason' => ['required', 'string', 'max:500'],
            'restock_items' => ['boolean'],
        ], [
            'refund_amount.required' => 'Refund amount is required',
            'refund_amount.max' => "Refund amount cannot exceed sale total ({$sale->total_amount})",
            'reason.required' => 'Refund reason is required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Update sale status
            $sale->update([
                'status' => 'refunded',
                'payment_status' => 'refunded',
                'notes' => ($sale->notes ? $sale->notes . "\n\n" : '') .
                          "REFUNDED: {$request->reason} (Amount: {$request->refund_amount})"
            ]);

            // Restock items if requested
            if ($request->restock_items) {
                foreach ($sale->saleItems as $item) {
                    if ($item->product && $item->product->track_stock) {
                        $item->product->updateStock(
                            $item->quantity,
                            'refund',
                            $user->id,
                            ['type' => 'refund', 'id' => $sale->id],
                            "Refund - restocked from {$sale->sale_number}"
                        );
                    }
                }
            }

            // Update customer totals
            if ($sale->customer_id) {
                $sale->customer->updateTotals();
            }

            DB::commit();

            Log::info('Sale refunded successfully', [
                'sale_id' => $id,
                'refund_amount' => $request->refund_amount,
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Sale refunded successfully',
                'sale' => $sale->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Sale refund failed', [
                'sale_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Sale refund failed. Please try again.'
            ], 500);
        }
    }

    public function getSalesStats(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        Log::info('Sales stats accessed', [
            'user_id' => $user->id,
            'company_id' => $companyId
        ]);

        $period = $request->get('period', 'today'); // today, week, month, year

        $query = Sale::where('company_id', $companyId)->completed();

        switch ($period) {
            case 'today':
                $query->today();
                break;
            case 'week':
                $query->thisWeek();
                break;
            case 'month':
                $query->thisMonth();
                break;
            case 'year':
                $query->whereYear('sale_date', now()->year);
                break;
        }

        $stats = [
            'period' => $period,
            'total_sales' => $query->count(),
            'total_revenue' => $query->sum('total_amount'),
            'total_items_sold' => $query->with('saleItems')->get()->sum(function ($sale) {
                return $sale->saleItems->sum('quantity');
            }),
            'average_sale_value' => $query->count() > 0 ? $query->avg('total_amount') : 0,
            'cash_sales' => $query->where('payment_method', 'cash')->sum('total_amount'),
            'card_sales' => $query->where('payment_method', 'card')->sum('total_amount'),
            'pending_payments' => Sale::where('company_id', $companyId)
                ->where('payment_status', 'pending')
                ->sum('amount_due'),
        ];

        // Top selling products for the period
        $topProducts = SaleItem::where('company_id', $companyId)
            ->whereHas('sale', function ($q) use ($query) {
                $q->where('company_id', $query->getQuery()->wheres[0]['value'] ?? null)
                  ->completed();

                // Apply same date filters as main query
                if ($period === 'today') {
                    $q->whereDate('sale_date', today());
                } elseif ($period === 'week') {
                    $q->whereBetween('sale_date', [now()->startOfWeek(), now()->endOfWeek()]);
                } elseif ($period === 'month') {
                    $q->whereMonth('sale_date', now()->month)->whereYear('sale_date', now()->year);
                } elseif ($period === 'year') {
                    $q->whereYear('sale_date', now()->year);
                }
            })
            ->with('product:id,name')
            ->selectRaw('product_id, SUM(quantity) as total_quantity, SUM(line_total) as total_revenue')
            ->groupBy('product_id')
            ->orderBy('total_quantity', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'stats' => $stats,
            'top_products' => $topProducts
        ]);
    }
}
