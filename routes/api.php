<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SupplierController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });


Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-all', [AuthController::class, 'logoutAll']);
        Route::get('get-user-profile', [AuthController::class, 'getUserProfile']);
        Route::post('update-user-profile', [AuthController::class, 'updateProfile']);
    });


    // TODO: Come back to this later
    // Tenant Protected Routes (Require Active Company)
    Route::middleware(['tenant.access'])->group(function () {

        // Company Management Routes
        Route::prefix('company')->group(function () {

            // All authenticated users can access these
            Route::get('profile', [CompanyController::class, 'getCompanyProfile']);
            Route::get('settings', [CompanyController::class, 'getCompanySettings']);

            // Manager or Owner can access these
            Route::middleware(['manager.or.owner'])->group(function () {
                Route::get('users', [CompanyController::class, 'getCompanyUsers']);
            });

            // Owner Only Routes
            Route::middleware(['company.owner'])->group(function () {
                Route::post('update-profile', [CompanyController::class, 'updateCompanyProfile']);
                Route::post('users', [CompanyController::class, 'addUser']);
                Route::post('users/{userId}/update', [CompanyController::class, 'updateUser']);
                Route::delete('users/{userId}', [CompanyController::class, 'deleteUser']);
            });
        });

        // Unit Management Routes
        Route::prefix('units')->group(function () {
            Route::get('/', [UnitController::class, 'getUnits']);
            Route::get('/active', [UnitController::class, 'getActiveUnits']);
            Route::get('/types', [UnitController::class, 'getUnitTypes']);
            Route::get('/{id}', [UnitController::class, 'show']);

            // Manager or Owner can manage units
            Route::middleware(['manager.or.owner'])->group(function () {
                Route::post('/', [UnitController::class, 'addUnit']);
                Route::post('/{id}', [UnitController::class, 'updateUnit']);
                Route::post('/{id}', [UnitController::class, 'deleteUnit']);
                Route::post('/seed-defaults', [UnitController::class, 'seedDefaultUnits']);
            });
        });

        // Category Management Routes
        Route::prefix('categories')->group(function () {
            Route::get('/', [CategoryController::class, 'getCategories']);
            Route::get('/active', [CategoryController::class, 'getAllActive']);
            Route::get('get-category/{id}', [CategoryController::class, 'getCategory']);

            Route::middleware(['manager.or.owner'])->group(function () {
                Route::post('create', [CategoryController::class, 'createCategory']);
                Route::post('update/{id}', [CategoryController::class, 'updateCategory']);
                Route::post('delete/{id}', [CategoryController::class, 'deleteCategory']);
            });
        });

        // Product & Inventory Management Routes
        Route::prefix('products')->group(function () {
            Route::get('/', [ProductController::class, 'getProducts']);
            Route::get('stats', [ProductController::class, 'getProductStats']);
            Route::get('/low-stock', [ProductController::class, 'getLowStockProducts']);
            Route::get('get-product/{id}', [ProductController::class, 'getProduct']);
            Route::get('/{id}/stock-movements', [ProductController::class, 'getStockMovements']);

            Route::middleware(['manager.or.owner'])->group(function () {
                Route::post('create', [ProductController::class, 'createProduct']);
                Route::post('update/{id}', [ProductController::class, 'updateProduct']);
                Route::post('/{id}/adjust-stock', [ProductController::class, 'adjustStock']);
                Route::post('delete/{id}', [ProductController::class, 'deleteProduct']);
            });
        });



        // Customer Management Routes
        Route::prefix('customers')->group(function () {
            Route::get('/', [CustomerController::class, 'getCustomers']);
            Route::get('/active', [CustomerController::class, 'getActiveCustomers']);
            Route::get('/stats', [CustomerController::class, 'getCustomerStats']);
            Route::get('/{id}', [CustomerController::class, 'getCustomer']);
            Route::post('/create', [CustomerController::class, 'addCustomer']);

            Route::middleware(['manager.or.owner'])->group(function () {
                Route::post('update/{id}', [CustomerController::class, 'updateCustomer']);
                Route::post('delete/{id}', [CustomerController::class, 'deleteCustomer']);
            });
        });

        // Sales Management Routes
        Route::prefix('sales')->group(function () {
            Route::get('/', [SaleController::class, 'getSales']);
            Route::get('/stats', [SaleController::class, 'getSalesStats']);
            Route::get('/{id}', [SaleController::class, 'getSale']);

            Route::middleware(['manager.or.owner'])->group(function () {
                Route::post('/create', [SaleController::class, 'addSale']);
                Route::put('update/{id}', [SaleController::class, 'updateSale']);
                Route::post('/{id}/payments', [SaleController::class, 'addPayment']);
                Route::post('/{id}/refund', [SaleController::class, 'refund']);
            });
        });


        Route::prefix('suppliers')->group(function () {
            Route::get('/', [SupplierController::class, 'getSuppliers']);
            Route::post('/create', [SupplierController::class, 'addSupplier']);
            Route::get('/{id}', [SupplierController::class, 'show']);
            Route::post('update/{id}', [SupplierController::class, 'updateSupplier']);
            Route::post('delete/{id}', [SupplierController::class, 'deleteSupplier']);
        });

        Route::prefix('purchases')->group(function () {
            Route::get('/', [PurchaseController::class, 'getPurchases']);
            Route::post('/create', [PurchaseController::class, 'addPurchase']);
            Route::get('/summary', [PurchaseController::class, 'getPurchaseSummary']);
            Route::get('/{id}', [PurchaseController::class, 'show']);
            Route::post('delete/{id}', [PurchaseController::class, 'deletePurchase']);
        });

        // Future modules:
        // Route::prefix('sales')->group(function () { ... });
        // Route::prefix('purchases')->group(function () { ... });
        // Route::prefix('reports')->group(function () { ... });

    });
});
