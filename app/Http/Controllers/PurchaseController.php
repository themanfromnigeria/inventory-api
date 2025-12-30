<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseController extends Controller
{
    public function getPurchases(Request $request)
    {
        try {
            $query = Purchase::with(['supplier', 'items.product'])
                ->where('company_id', $request->user()->company_id);

            // Date filtering
            if ($request->has('from_date')) {
                $query->whereDate('purchase_date', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->whereDate('purchase_date', '<=', $request->to_date);
            }

            // Supplier filtering
            if ($request->has('supplier_id')) {
                $query->where('supplier_id', $request->supplier_id);
            }

            // Status filtering
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Search functionality
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('purchase_number', 'like', "%{$search}%")
                      ->orWhereHas('supplier', function($sq) use ($search) {
                          $sq->where('name', 'like', "%{$search}%");
                      });
                });
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'purchase_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $purchases = $query->paginate($request->get('per_page', 15));

            Log::info('Purchases fetched successfully', [
                'count' => $purchases->count(),
                'total' => $purchases->total()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Purchases retrieved successfully',
                'data' => $purchases
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching purchases', [
                'error' => $e->getMessage(),
                'company_id' => $request->user()->company_id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching purchases'
            ], 500);
        }
    }

    public function addPurchase(Request $request)
    {
        try {
            $validated = $request->validate([
                'supplier_id' => 'required|exists:suppliers,id',
                'purchase_date' => 'required|date',
                'tax_amount' => 'numeric|min:0',
                'discount_amount' => 'numeric|min:0',
                'notes' => 'nullable|string',
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.quantity' => 'required|numeric|min:0.001',
                'items.*.unit_cost' => 'required|numeric|min:0',
            ]);

            Log::info('Creating new purchase', [
                'company_id' => $request->user()->company_id,
                'supplier_id' => $validated['supplier_id'],
                'items_count' => count($validated['items'])
            ]);

            DB::beginTransaction();

            // Calculate totals
            $subtotal = 0;
            foreach ($validated['items'] as $item) {
                $subtotal += $item['quantity'] * $item['unit_cost'];
            }

            $taxAmount = $validated['tax_amount'] ?? 0;
            $discountAmount = $validated['discount_amount'] ?? 0;
            $totalAmount = $subtotal + $taxAmount - $discountAmount;

            // Create purchase
            $purchase = Purchase::create([
                'company_id' => $request->user()->company_id,
                'supplier_id' => $validated['supplier_id'],
                'purchase_date' => $validated['purchase_date'],
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'notes' => $validated['notes'] ?? null,
            ]);

            Log::info('Purchase created', [
                'purchase_id' => $purchase->id,
                'purchase_number' => $purchase->purchase_number
            ]);

            // Create purchase items and update inventory
            foreach ($validated['items'] as $itemData) {
                $totalCost = $itemData['quantity'] * $itemData['unit_cost'];

                // Create purchase item
                $purchaseItem = PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $itemData['product_id'],
                    'quantity' => $itemData['quantity'],
                    'unit_cost' => $itemData['unit_cost'],
                    'total_cost' => $totalCost,
                ]);

                Log::info('Purchase item created', [
                    'purchase_item_id' => $purchaseItem->id,
                    'product_id' => $itemData['product_id'],
                    'quantity' => $itemData['quantity']
                ]);

                // Update product stock
                $product = Product::find($itemData['product_id']);
                $oldQuantity = $product->quantity;
                $newQuantity = $oldQuantity + $itemData['quantity'];

                $product->update(['quantity' => $newQuantity]);

                Log::info('Product stock updated', [
                    'product_id' => $product->id,
                    'old_quantity' => $oldQuantity,
                    'new_quantity' => $newQuantity,
                    'added_quantity' => $itemData['quantity']
                ]);

                // Update product cost if using last_purchase method
                $product->updateCostFromPurchase($itemData['unit_cost'], $validated['purchase_date']);

                // Create stock movement record
                StockMovement::create([
                    'company_id' => $request->user()->company_id,
                    'product_id' => $itemData['product_id'],
                    'type' => 'purchase',
                    'quantity' => $itemData['quantity'],
                    'reference_id' => $purchase->id,
                    'reference_type' => 'purchase',
                    'notes' => "Purchase from " . $purchase->supplier->name,
                    'created_by' => $request->user()->id,
                ]);

                Log::info('Stock movement recorded', [
                    'product_id' => $itemData['product_id'],
                    'type' => 'purchase',
                    'quantity' => $itemData['quantity']
                ]);
            }

            // Load relationships for response
            $purchase->load(['supplier', 'items.product']);

            DB::commit();

            Log::info('Purchase completed successfully', [
                'purchase_id' => $purchase->id,
                'purchase_number' => $purchase->purchase_number,
                'total_amount' => $totalAmount
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Purchase created successfully',
                'data' => $purchase
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            Log::warning('Validation failed for purchase creation', [
                'errors' => $e->errors()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating purchase', [
                'error' => $e->getMessage(),
                'company_id' => $request->user()->company_id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error creating purchase'
            ], 500);
        }
    }

    public function show(Request $request, string $id)
    {
        try {
            $purchase = Purchase::with(['supplier', 'items.product.unit'])
                ->where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            Log::info('Purchase retrieved', [
                'purchase_id' => $purchase->id,
                'purchase_number' => $purchase->purchase_number
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Purchase retrieved successfully',
                'data' => $purchase
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Purchase not found', [
                'purchase_id' => $id,
                'company_id' => $request->user()->company_id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Purchase not found'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Error retrieving purchase', [
                'error' => $e->getMessage(),
                'purchase_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving purchase'
            ], 500);
        }
    }

    public function deletePurchase(Request $request, string $id)
    {
        try {
            $purchase = Purchase::with('items.product')
                ->where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            Log::info('Attempting to cancel purchase', [
                'purchase_id' => $purchase->id,
                'purchase_number' => $purchase->purchase_number
            ]);

            if ($purchase->status === 'cancelled') {
                return response()->json([
                    'success' => false,
                    'message' => 'Purchase is already cancelled'
                ], 400);
            }

            DB::beginTransaction();

            // Reverse stock movements
            foreach ($purchase->items as $item) {
                $product = $item->product;
                $oldQuantity = $product->quantity;
                $newQuantity = $oldQuantity - $item->quantity;

                // Check if we have enough stock to reverse
                if ($newQuantity < 0) {
                    DB::rollBack();
                    Log::warning('Insufficient stock to cancel purchase', [
                        'product_id' => $product->id,
                        'current_quantity' => $oldQuantity,
                        'required_quantity' => $item->quantity
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => "Cannot cancel purchase. Insufficient stock for product: {$product->name}"
                    ], 400);
                }

                $product->update(['quantity' => $newQuantity]);

                Log::info('Product stock reversed', [
                    'product_id' => $product->id,
                    'old_quantity' => $oldQuantity,
                    'new_quantity' => $newQuantity,
                    'reversed_quantity' => $item->quantity
                ]);

                // Create reverse stock movement
                StockMovement::create([
                    'company_id' => $request->user()->company_id,
                    'product_id' => $product->id,
                    'type' => 'adjustment',
                    'quantity' => -$item->quantity,
                    'reference_id' => $purchase->id,
                    'reference_type' => 'purchase_cancellation',
                    'notes' => "Purchase cancellation - {$purchase->purchase_number}",
                    'created_by' => $request->user()->id,
                ]);
            }

            // Update purchase status
            $purchase->update(['status' => 'cancelled']);

            DB::commit();

            Log::info('Purchase cancelled successfully', [
                'purchase_id' => $purchase->id,
                'purchase_number' => $purchase->purchase_number
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Purchase cancelled successfully'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase not found'
            ], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error cancelling purchase', [
                'error' => $e->getMessage(),
                'purchase_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error cancelling purchase'
            ], 500);
        }
    }

    public function getPurchaseSummary(Request $request)
    {
        try {
            $companyId = $request->user()->company_id;

            Log::info('Generating purchase summary', [
                'company_id' => $companyId
            ]);

            // Date filters
            $fromDate = $request->get('from_date', now()->startOfMonth()->toDateString());
            $toDate = $request->get('to_date', now()->endOfMonth()->toDateString());

            // Total purchases
            $totalPurchases = Purchase::where('company_id', $companyId)
                ->where('status', 'completed')
                ->whereDate('purchase_date', '>=', $fromDate)
                ->whereDate('purchase_date', '<=', $toDate)
                ->sum('total_amount');

            // Purchase count
            $purchaseCount = Purchase::where('company_id', $companyId)
                ->where('status', 'completed')
                ->whereDate('purchase_date', '>=', $fromDate)
                ->whereDate('purchase_date', '<=', $toDate)
                ->count();

            // Top suppliers
            $topSuppliers = Purchase::selectRaw('supplier_id, suppliers.name as supplier_name, SUM(total_amount) as total_spent, COUNT(*) as purchase_count')
                ->join('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
                ->where('purchases.company_id', $companyId)
                ->where('purchases.status', 'completed')
                ->whereDate('purchase_date', '>=', $fromDate)
                ->whereDate('purchase_date', '<=', $toDate)
                ->groupBy('supplier_id', 'suppliers.name')
                ->orderByDesc('total_spent')
                ->limit(5)
                ->get();

            // Recent purchases
            $recentPurchases = Purchase::with('supplier')
                ->where('company_id', $companyId)
                ->whereDate('purchase_date', '>=', $fromDate)
                ->whereDate('purchase_date', '<=', $toDate)
                ->orderBy('purchase_date', 'desc')
                ->limit(5)
                ->get();

            $summary = [
                'period' => [
                    'from_date' => $fromDate,
                    'to_date' => $toDate
                ],
                'totals' => [
                    'total_amount' => $totalPurchases,
                    'purchase_count' => $purchaseCount,
                    'average_purchase' => $purchaseCount > 0 ? $totalPurchases / $purchaseCount : 0
                ],
                'top_suppliers' => $topSuppliers,
                'recent_purchases' => $recentPurchases
            ];

            Log::info('Purchase summary generated successfully', [
                'total_amount' => $totalPurchases,
                'purchase_count' => $purchaseCount
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Purchase summary retrieved successfully',
                'data' => $summary
            ]);

        } catch (\Exception $e) {
            Log::error('Error generating purchase summary', [
                'error' => $e->getMessage(),
                'company_id' => $request->user()->company_id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error generating purchase summary'
            ], 500);
        }
    }
}
