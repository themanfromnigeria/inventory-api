<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Category;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function getCategories(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $search = $request->get('search');
        $status = $request->get('status');

        $query = Category::where('company_id', $companyId)
            ->withCount(['products', 'activeProducts']);

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($status) {
            $query->where('active', $status === 'active');
        }

        $categories = $query->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories
        ]);
    }

    public function createCategory(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories')->where('company_id', $companyId)
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $category = Category::create([
                'company_id' => $companyId,
                'name' => $request->name,
                'description' => $request->description,
                'active' => $request->active ?? true,
            ]);

            return response()->json([
                'message' => 'Category created successfully',
                'category' => $category
            ], 201);

        } catch (\Exception $e) {
            Log::error('Category creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Category creation failed. Please try again.'
            ], 500);
        }
    }

    public function getCategory(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $category = Category::where('id', $id)
            ->where('company_id', $companyId)
            ->withCount(['products', 'activeProducts'])
            ->with(['products'])
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Category not found'
            ], 404);
        }

        return response()->json([
            'data' => $category
        ]);
    }

    public function updateCategory(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $category = Category::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Category not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('categories')->where('company_id', $companyId)->ignore($category->id)
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $category->update($validator->validated());

            return response()->json([
                'message' => 'Category updated successfully',
                'data' => $category->fresh()
            ]);

        } catch (\Exception $e) {
            Log::error('Category update failed', [
                'category_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Category update failed. Please try again.'
            ], 500);
        }
    }

    public function deleteCategory(Request $request, $id)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $category = Category::where('id', $id)
            ->where('company_id', $companyId)
            ->withCount('products')
            ->first();

        if (!$category) {
            return response()->json([
                'message' => 'Category not found'
            ], 404);
        }

        // Check if category has products
        if ($category->products_count > 0) {
            return response()->json([
                'message' => 'Cannot delete category that has products. Move or delete products first.',
                'products_count' => $category->products_count
            ], 400);
        }

        try {
            $category->delete();

            return response()->json([
                'message' => 'Category deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Category deletion failed', [
                'category_id' => $id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Category deletion failed. Please try again.'
            ], 500);
        }
    }

    public function getAllActive(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $categories = Category::where('company_id', $companyId)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories
        ]);
    }
}
