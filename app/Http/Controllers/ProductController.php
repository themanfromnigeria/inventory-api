<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function getProducts(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $perPage = min($request->get('per_page', 15), 100);
        $search = $request->get('search');
        $categoryId = $request->get('category_id');
        $unitId = $request->get('unit_id'); // Changed from 'unit' to 'unit_id'
        $status = $request->get('status');
        $sortBy = $request->get('sort_by', 'name');
        $sortOrder = $request->get('sort_order', 'asc');

        $query = Product::where('company_id', $companyId)
            ->with(['category:id,name', 'unit:id,name,symbol']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        if ($unitId) {
            $query->where('unit_id', $unitId);
        }

        if ($status) {
            switch ($status) {
                case 'active':
                    $query->where('active', true);
                    break;
                case 'inactive':
                    $query->where('active', false);
                    break;
                case 'low_stock':
                    $query->lowStock();
                    break;
                case 'out_of_stock':
                    $query->outOfStock();
                    break;
            }
        }

        // Apply sorting
        $allowedSorts = ['name', 'sku', 'selling_price', 'cost_price', 'stock_quantity', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder === 'desc' ? 'desc' : 'asc');
        }

        $products = $query->paginate($perPage);

        return response()->json([
            $products
        ]);
    }

    public function createProduct(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['nullable', 'string', 'exists:categories,id'],
            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products')->where('company_id', $companyId)
            ],
            'barcode' => ['nullable', 'string', 'max:100'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'cost_price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'stock_quantity' => ['required', 'numeric', 'min:0'],
            'minimum_stock' => ['required', 'numeric', 'min:0'],
            'unit_id' => ['required', 'string', 'exists:units,id'],
            'track_stock' => ['boolean'],
            'active' => ['boolean'],
        ]);

        // Validate category belongs to company if provided
        if ($request->category_id) {
            $categoryExists = Category::where('id', $request->category_id)
                ->where('company_id', $companyId)
                ->where('active', true)
                ->exists();

            if (!$categoryExists) {
                $validator->after(function ($validator) {
                    $validator->errors()->add('category_id', 'Selected category does not belong to your company or is inactive');
                });
            }
        }

        // Validate unit belongs to company
        if ($request->unit_id) {
            $unitExists = Unit::where('id', $request->unit_id)
                ->where('company_id', $companyId)
                ->where('active', true)
                ->exists();

            if (!$unitExists) {
                $validator->after(function ($validator) {
                    $validator->errors()->add('unit_id', 'Selected unit does not belong to your company or is inactive');
                });
            }
        }

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $product = Product::create([
                'company_id' => $companyId,
                'category_id' => $request->category_id,
                'name' => $request->name,
                'description' => $request->description,
                'sku' => $request->sku,
                'barcode' => $request->barcode,
                'image_url' => $request->image_url,
                'cost_price' => $request->cost_price,
                'selling_price' => $request->selling_price,
                'stock_quantity' => $request->stock_quantity ?? 0,
                'minimum_stock' => $request->minimum_stock ?? 0,
                'maximum_stock' => $request->maximum_stock,
                'unit_id' => $request->unit_id,
                'track_stock' => $request->track_stock ?? true,
                'active' => $request->active ?? true,
            ]);

            // Log initial stock if tracking enabled and quantity > 0
            if ($product->track_stock && $product->stock_quantity > 0) {
                $product->stockMovements()->create([
                    'company_id' => $companyId,
                    'user_id' => $user->id,
                    'type' => 'in',
                    'quantity' => $product->stock_quantity,
                    'stock_before' => 0,
                    'stock_after' => $product->stock_quantity,
                    'unit_id' => $product->unit_id,
                    'reference_type' => 'initial_stock',
                    'notes' => 'Initial stock when product was created',
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Product created successfully',
                'product' => $product->load(['category:id,name', 'unit:id,name,symbol'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Product creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Product creation failed. Please try again.'
            ], 500);
        }
    }

    public function getProduct(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $product = Product::where('id', $id)
            ->where('company_id', $companyId)
            ->with(['category:id,name', 'unit:id,name,symbol'])
            ->first();

        if (!$product) {
            Log::warning('Product not found', [
                'product_id' => $id,
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Product not found'
            ], 404);
        }

        Log::info('Product viewed', [
            'product_id' => $id,
            'user_id' => $user->id
        ]);

        return response()->json([
            'product' => $product
        ]);
    }

    public function updateProduct(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        Log::info('Product update attempt', [
            'product_id' => $id,
            'user_id' => $user->id
        ]);

        $product = Product::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category_id' => ['sometimes', 'nullable', 'string', 'exists:categories,id'],
            'sku' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::unique('products')->where('company_id', $companyId)->ignore($product->id)
            ],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'cost_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999.99'],
            'selling_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999.99'],
            'minimum_stock' => ['sometimes', 'numeric', 'min:0'],
            'maximum_stock' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'unit_id' => ['sometimes', 'string', 'exists:units,id'],
            'track_stock' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // Validate category belongs to company if provided
        if ($request->category_id) {
            $categoryExists = Category::where('id', $request->category_id)
                ->where('company_id', $companyId)
                ->exists();

            if (!$categoryExists) {
                $validator->errors()->add('category_id', 'Selected category does not belong to your company');
            }
        }

        // Validate unit belongs to company if provided
        if ($request->unit_id) {
            $unitExists = Unit::where('id', $request->unit_id)
                ->where('company_id', $companyId)
                ->where('active', true)
                ->exists();

            if (!$unitExists) {
                $validator->errors()->add('unit_id', 'Selected unit does not belong to your company or is inactive');
            }
        }

        if ($validator->fails()) {
            Log::warning('Product update validation failed', [
                'product_id' => $id,
                'user_id' => $user->id,
                'errors' => $validator->errors()
            ]);

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $product->update($validator->validated());

            Log::info('Product updated successfully', [
                'product_id' => $id,
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Product updated successfully',
                'product' => $product->fresh()->load(['category:id,name', 'unit:id,name,symbol'])
            ]);

        } catch (\Exception $e) {
            Log::error('Product update failed', [
                'product_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Product update failed. Please try again.'
            ], 500);
        }
    }

    public function deleteProduct(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        Log::info('Product deletion attempt', [
            'product_id' => $id,
            'user_id' => $user->id
        ]);

        $product = Product::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found'
            ], 404);
        }

        try {
            $product->delete();

            Log::info('Product deleted successfully', [
                'product_id' => $id,
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Product deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Product deletion failed', [
                'product_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Product deletion failed. Please try again.'
            ], 500);
        }
    }

    public function adjustStock(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        Log::info('Stock adjustment attempt', [
            'product_id' => $id,
            'user_id' => $user->id
        ]);

        $product = Product::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found'
            ], 404);
        }

        if (!$product->track_stock) {
            return response()->json([
                'message' => 'Stock tracking is disabled for this product'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'adjustment' => ['required', 'numeric', 'not_in:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'adjustment.required' => 'Stock adjustment amount is required',
            'adjustment.not_in' => 'Adjustment amount cannot be zero',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $adjustment = $request->adjustment;
            $notes = $request->notes ?? 'Manual stock adjustment';

            $success = $product->updateStock(
                $adjustment,
                'adjustment',
                $user->id,
                ['type' => 'manual_adjustment'],
                $notes
            );

            if (!$success) {
                return response()->json([
                    'message' => 'Stock adjustment failed'
                ], 400);
            }

            Log::info('Stock adjusted successfully', [
                'product_id' => $id,
                'user_id' => $user->id,
                'adjustment' => $adjustment,
                'new_stock' => $product->fresh()->stock_quantity
            ]);

            return response()->json([
                'message' => 'Stock adjusted successfully',
                'product' => $product->fresh()->load(['unit:id,name,symbol'])
            ]);

        } catch (\Exception $e) {
            Log::error('Stock adjustment failed', [
                'product_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Stock adjustment failed. Please try again.'
            ], 500);
        }
    }

    public function getAvailableUnits(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $units = Unit::where('company_id', $companyId)
            ->where('active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'name', 'symbol', 'type']);

        return response()->json([
            'units' => $units
        ]);
    }

    public function getProductStats(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        Log::info('Product stats accessed', [
            'user_id' => $user->id,
            'company_id' => $companyId
        ]);

        $stats = [
            'total_products' => Product::where('company_id', $companyId)->count(),
            'active_products' => Product::where('company_id', $companyId)->where('active', true)->count(),
            'low_stock_products' => Product::where('company_id', $companyId)->lowStock()->count(),
            'out_of_stock_products' => Product::where('company_id', $companyId)->outOfStock()->count(),
            'total_stock_value' => Product::where('company_id', $companyId)
                ->selectRaw('SUM(stock_quantity * cost_price) as total_value')
                ->value('total_value') ?? 0,
            'total_categories' => Category::where('company_id', $companyId)->where('active', true)->count(),
            'total_units' => Unit::where('company_id', $companyId)->where('active', true)->count(),
        ];

        // Get products by unit breakdown
        $unitBreakdown = Product::where('company_id', $companyId)
            ->where('active', true)
            ->whereNotNull('unit_id')
            ->with('unit:id,name,symbol')
            ->get()
            ->groupBy('unit.name')
            ->map(function ($products, $unitName) {
                return [
                    'unit_name' => $unitName,
                    'unit_symbol' => $products->first()->unit->symbol ?? '',
                    'count' => $products->count()
                ];
            })
            ->values();

        return response()->json([
            'stats' => $stats,
            'unit_breakdown' => $unitBreakdown
        ]);
    }

    public function getStockMovements(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $product = Product::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found'
            ], 404);
        }

        $perPage = $request->get('per_page', 20);
        $movements = $product->stockMovements()
            ->with(['user:id,first_name,last_name', 'unit:id,name,symbol'])
            ->paginate($perPage);

        return response()->json([
            'movements' => $movements
        ]);
    }

    public function getLowStockProducts(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $lowStockProducts = Product::where('company_id', $companyId)
            ->lowStock()
            ->with(['category:id,name', 'unit:id,name,symbol'])
            ->orderBy('stock_quantity', 'asc')
            ->get();

        return response()->json([
            'data' => $lowStockProducts,
            'count' => $lowStockProducts->count()
        ]);
    }
}
