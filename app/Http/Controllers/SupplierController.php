<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Supplier;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function getSuppliers(Request $request)
    {
        try {
            Log::info('Fetching suppliers for company', [
                'company_id' => $request->user()->company_id,
                'user_id' => $request->user()->id
            ]);

            $query = Supplier::where('company_id', $request->user()->company_id);

            // Search functionality
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('contact_person', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('is_active', $request->status === 'active');
            } else {
                $query->active(); // Default to active suppliers
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'name');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            $suppliers = $query->paginate($request->get('per_page', 15));

            Log::info('Suppliers fetched successfully', [
                'count' => $suppliers->count(),
                'total' => $suppliers->total()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Suppliers retrieved successfully',
                'data' => $suppliers
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching suppliers', [
                'error' => $e->getMessage(),
                'company_id' => $request->user()->company_id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching suppliers'
            ], 500);
        }
    }

    public function addSuppliers(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'contact_person' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:20',
                'email' => [
                    'nullable',
                    'string',
                    'email',
                    'max:255',
                    Rule::unique('suppliers')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->user()->company_id);
                    })
                ],
                'address' => 'nullable|string',
                'notes' => 'nullable|string',
                'is_active' => 'boolean'
            ]);

            Log::info('Creating new supplier', [
                'company_id' => $request->user()->company_id,
                'supplier_name' => $validated['name']
            ]);

            $supplier = Supplier::create([
                'company_id' => $request->user()->company_id,
                ...$validated
            ]);

            Log::info('Supplier created successfully', [
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Supplier created successfully',
                'data' => $supplier
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validation failed for supplier creation', [
                'errors' => $e->errors(),
                'input' => $request->except(['password'])
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Error creating supplier', [
                'error' => $e->getMessage(),
                'company_id' => $request->user()->company_id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error creating supplier'
            ], 500);
        }
    }

    public function show(Request $request, string $id)
    {
        try {
            $supplier = Supplier::where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            Log::info('Supplier retrieved', [
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Supplier retrieved successfully',
                'data' => $supplier
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Supplier not found', [
                'supplier_id' => $id,
                'company_id' => $request->user()->company_id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Supplier not found'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Error retrieving supplier', [
                'error' => $e->getMessage(),
                'supplier_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving supplier'
            ], 500);
        }
    }

    public function updateSupplier(Request $request, string $id)
    {
        try {
            $supplier = Supplier::where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'contact_person' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:20',
                'email' => [
                    'nullable',
                    'string',
                    'email',
                    'max:255',
                    Rule::unique('suppliers')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->user()->company_id);
                    })->ignore($supplier->id)
                ],
                'address' => 'nullable|string',
                'notes' => 'nullable|string',
                'is_active' => 'boolean'
            ]);

            Log::info('Updating supplier', [
                'supplier_id' => $supplier->id,
                'changes' => $validated
            ]);

            $supplier->update($validated);

            Log::info('Supplier updated successfully', [
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Supplier updated successfully',
                'data' => $supplier->fresh()
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier not found'
            ], 404);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Error updating supplier', [
                'error' => $e->getMessage(),
                'supplier_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error updating supplier'
            ], 500);
        }
    }

    public function deleteSupplier(Request $request, string $id)
    {
        try {
            $supplier = Supplier::where('company_id', $request->user()->company_id)
                ->findOrFail($id);

            // Check if supplier has purchases
            if ($supplier->purchases()->count() > 0) {
                Log::warning('Attempted to delete supplier with purchases', [
                    'supplier_id' => $supplier->id,
                    'purchases_count' => $supplier->purchases()->count()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete supplier with existing purchases. Deactivate instead.'
                ], 400);
            }

            Log::info('Deleting supplier', [
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name
            ]);

            $supplierName = $supplier->name;
            $supplier->delete();

            Log::info('Supplier deleted successfully', [
                'supplier_name' => $supplierName
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Supplier deleted successfully'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier not found'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Error deleting supplier', [
                'error' => $e->getMessage(),
                'supplier_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error deleting supplier'
            ], 500);
        }
    }
}
