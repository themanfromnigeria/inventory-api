<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Models\Unit;

class UnitController extends Controller
{
    public function getUnits(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $type = $request->get('type'); // Filter by type: weight, volume, count, etc.
        $search = $request->get('search');

        $query = Unit::where('company_id', $companyId);

        if ($type) {
            $query->where('type', $type);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('symbol', 'like', "%{$search}%");
            });
        }

        $units = $query->orderBy('type')
                      ->orderBy('name')
                      ->get();

        return response()->json([
            'data' => $units
        ]);
    }

    public function addUnit(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('units')->where('company_id', $companyId)
            ],
            'symbol' => [
                'required',
                'string',
                'max:10',
                Rule::unique('units')->where('company_id', $companyId)
            ],
            'type' => ['required', 'string', 'in:weight,volume,length,area,count,custom'],
            'allow_decimals' => ['boolean'],
            'decimal_places' => ['integer', 'min:0', 'max:6'],
            'active' => ['boolean'],
        ], [
            'name.required' => 'Unit name is required',
            'name.unique' => 'Unit name already exists',
            'symbol.required' => 'Unit symbol is required',
            'symbol.unique' => 'Unit symbol already exists',
            'type.required' => 'Unit type is required',
            'type.in' => 'Invalid unit type',
        ]);

        if ($validator->fails()) {
            Log::warning('Unit creation validation failed', [
                'user_id' => $user->id,
                'errors' => $validator->errors()
            ]);

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $unit = Unit::create([
                'company_id' => $companyId,
                'name' => $request->name,
                'symbol' => $request->symbol,
                'type' => $request->type,
                'allow_decimals' => $request->allow_decimals ?? true,
                'decimal_places' => $request->decimal_places ?? 2,
                'active' => $request->active ?? true,
            ]);

            Log::info('Unit created successfully', [
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'company_id' => $companyId
            ]);

            return response()->json([
                'message' => 'Unit created successfully',
                'unit' => $unit
            ], 201);

        } catch (\Exception $e) {
            Log::error('Unit creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Unit creation failed. Please try again.'
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $unit = Unit::where('id', $id)
            ->where('company_id', $companyId)
            ->withCount('products')
            ->first();

        if (!$unit) {
            Log::warning('Unit not found', [
                'unit_id' => $id,
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Unit not found'
            ], 404);
        }

        return response()->json([
            'data' => $unit
        ]);
    }

    public function updateUnit(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $unit = Unit::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$unit) {
            return response()->json([
                'message' => 'Unit not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('units')->where('company_id', $companyId)->ignore($unit->id)
            ],
            'symbol' => [
                'sometimes',
                'string',
                'max:10',
                Rule::unique('units')->where('company_id', $companyId)->ignore($unit->id)
            ],
            'type' => ['sometimes', 'string', 'in:weight,volume,length,area,count,custom'],
            'allow_decimals' => ['sometimes', 'boolean'],
            'decimal_places' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'name.unique' => 'Unit name already exists',
            'symbol.unique' => 'Unit symbol already exists',
            'type.in' => 'Invalid unit type',
        ]);

        if ($validator->fails()) {
            Log::warning('Unit update validation failed', [
                'unit_id' => $id,
                'user_id' => $user->id,
                'errors' => $validator->errors()
            ]);

            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $unit->update($validator->validated());

            return response()->json([
                'message' => 'Unit updated successfully',
                'data' => $unit->fresh()
            ]);

        } catch (\Exception $e) {
            Log::error('Unit update failed', [
                'unit_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Unit update failed. Please try again.'
            ], 500);
        }
    }

    public function deleteUnit(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $unit = Unit::where('id', $id)
            ->where('company_id', $companyId)
            ->withCount('products')
            ->first();

        if (!$unit) {
            return response()->json([
                'message' => 'Unit not found'
            ], 404);
        }

        // Check if unit is being used by products
        if ($unit->products_count > 0) {
            Log::warning('Attempted to delete unit with products', [
                'unit_id' => $id,
                'products_count' => $unit->products_count,
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Cannot delete unit that is being used by products.',
                'products_count' => $unit->products_count
            ], 400);
        }

        try {
            $unit->delete();

            return response()->json([
                'message' => 'Unit deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Unit deletion failed', [
                'unit_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Unit deletion failed. Please try again.'
            ], 500);
        }
    }

    public function getActiveUnits(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $units = Unit::where('company_id', $companyId)
            ->where('active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $units
        ]);
    }

    public function getUnitTypes(Request $request)
    {
        $unitTypes = [
            'weight' => 'Weight (kg, g, lb, oz)',
            'volume' => 'Volume (L, ml, gal, qt)',
            'length' => 'Length (m, cm, ft, in)',
            'area' => 'Area (m², ft²)',
            'count' => 'Count (pcs, doz, pair)',
            'custom' => 'Custom (box, roll, bag)',
        ];

        return response()->json([
            'data' => $unitTypes
        ]);
    }

    public function seedDefaultUnits(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        try {
            // Check if units already exist
            $existingUnits = Unit::where('company_id', $companyId)->count();

            if ($existingUnits > 0) {
                return response()->json([
                    'message' => 'Default units already exist for this company',
                    'existing_count' => $existingUnits
                ], 400);
            }

            Unit::seedDefaultUnits($companyId);

            $unitsCount = Unit::where('company_id', $companyId)->count();

            Log::info('Default units seeded successfully', [
                'user_id' => $user->id,
                'company_id' => $companyId,
                'units_created' => $unitsCount
            ]);

            return response()->json([
                'message' => 'Default units seeded successfully',
                'units_created' => $unitsCount
            ], 201);

        } catch (\Exception $e) {
            Log::error('Default units seeding failed', [
                'user_id' => $user->id,
                'company_id' => $companyId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to seed default units. Please try again.'
            ], 500);
        }
    }
}
