<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class CustomerController extends Controller
{
    public function getCustomers(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $perPage = min($request->get('per_page', 15), 100);
        $search = $request->get('search');
        $type = $request->get('type'); // individual, business
        $sortBy = $request->get('sort_by', 'name');
        $sortOrder = $request->get('sort_order', 'asc');

        $query = Customer::where('company_id', $companyId);

        // Apply filters
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('customer_code', 'like', "%{$search}%");
            });
        }

        if ($type) {
            $query->where('type', $type);
        }

        // Apply sorting
        $allowedSorts = ['name', 'customer_code', 'total_spent', 'total_orders', 'last_order_at', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder === 'desc' ? 'desc' : 'asc');
        }

        $customers = $query->paginate($perPage);

        return response()->json([
            $customers,
        ]);
    }

    public function addCustomer(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('customers')->where('company_id', $companyId)
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'type' => ['required', 'string', 'in:individual,business'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'active' => ['boolean'],
        ], [
            'name.required' => 'Customer name is required',
            'email.unique' => 'Email address already exists for another customer',
            'type.required' => 'Customer type is required',
            'type.in' => 'Customer type must be individual or business',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $customer = Customer::create([
                'company_id' => $companyId,
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'address' => $request->address,
                'type' => $request->type,
                'tax_number' => $request->tax_number,
                'active' => $request->active ?? true,
            ]);

            return response()->json([
                'message' => 'Customer created successfully',
                'customer' => $customer
            ], 201);

        } catch (\Exception $e) {
            Log::error('Customer creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Customer creation failed. Please try again.'
            ], 500);
        }
    }

    public function getCustomer(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $customer = Customer::where('id', $id)
            ->where('company_id', $companyId)
            ->withCount(['sales', 'completedSales'])
            ->first();

        if (!$customer) {
            return response()->json([
                'message' => 'Customer not found'
            ], 404);
        }

        // Get recent sales
        $recentSales = $customer->sales()
            ->with('user:id,first_name,last_name')
            ->orderBy('sale_date', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $customer,
            'recent_sales' => $recentSales
        ]);
    }

    public function updateCustomer(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $customer = Customer::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$customer) {
            return response()->json([
                'message' => 'Customer not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                Rule::unique('customers')->where('company_id', $companyId)->ignore($customer->id)
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'type' => ['sometimes', 'string', 'in:individual,business'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $customer->update($validator->validated());

            return response()->json([
                'message' => 'Customer updated successfully',
                'data' => $customer->fresh()
            ]);

        } catch (\Exception $e) {
            Log::error('Customer update failed', [
                'customer_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Customer update failed. Please try again.'
            ], 500);
        }
    }

    public function deleteCustomer(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $customer = Customer::where('id', $id)
            ->where('company_id', $companyId)
            ->withCount('sales')
            ->first();

        if (!$customer) {
            return response()->json([
                'message' => 'Customer not found'
            ], 404);
        }

        // Check if customer has sales
        if ($customer->sales_count > 0) {
            return response()->json([
                'message' => 'Cannot delete customer that has sales records. You can deactivate instead.',
                'sales_count' => $customer->sales_count
            ], 400);
        }

        try {
            $customer->delete();

            return response()->json([
                'message' => 'Customer deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Customer deletion failed', [
                'customer_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Customer deletion failed. Please try again.'
            ], 500);
        }
    }

    public function getActiveCustomers(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $customers = Customer::where('company_id', $companyId)
            ->where('active', true)
            ->select(['id', 'name', 'customer_code', 'email', 'phone'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $customers
        ]);
    }

    public function getCustomerStats(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $stats = [
            'total_customers' => Customer::where('company_id', $companyId)->count(),
            'active_customers' => Customer::where('company_id', $companyId)->where('active', true)->count(),
            'business_customers' => Customer::where('company_id', $companyId)->where('type', 'business')->count(),
            'individual_customers' => Customer::where('company_id', $companyId)->where('type', 'individual')->count(),
            'customers_with_orders' => Customer::where('company_id', $companyId)->where('total_orders', '>', 0)->count(),
            'total_customer_value' => Customer::where('company_id', $companyId)->sum('total_spent'),
            'average_customer_value' => Customer::where('company_id', $companyId)->where('total_orders', '>', 0)->avg('total_spent') ?? 0,
        ];

        // Top customers by spending
        $topCustomers = Customer::where('company_id', $companyId)
            ->where('total_spent', '>', 0)
            ->orderBy('total_spent', 'desc')
            ->limit(10)
            ->get(['id', 'name', 'customer_code', 'total_spent', 'total_orders']);

        // Recent customers
        $recentCustomers = Customer::where('company_id', $companyId)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'name', 'customer_code', 'created_at']);

        return response()->json([
            'data' => $stats,
            'top_customers' => $topCustomers,
            'recent_customers' => $recentCustomers
        ]);
    }
}
