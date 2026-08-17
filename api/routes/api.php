<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\FoodLogController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MealPlanController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Асистент Зоряна (чат).
Route::post('/assistant', [AssistantController::class, 'chat'])->middleware('throttle:30,1');

// Профіль / філії / головна.
Route::get('/me', [ProfileController::class, 'show']);
Route::get('/branches', [BranchController::class, 'index']);
Route::get('/home', [HomeController::class, 'index']);

// Меню тижня (демо: без auth, резолвимо demo-юзера; у проді — auth:sanctum).
// Генерація — дорогий ендпоінт → rate-limit.
Route::post('/meal-plans', [MealPlanController::class, 'store'])->middleware('throttle:10,1');
Route::get('/meal-plans/{id}', [MealPlanController::class, 'show']);
Route::post('/meal-plans/{id}/items/{item}/swap', [MealPlanController::class, 'swap']);
Route::post('/meal-plans/{id}/shopping-days', [MealPlanController::class, 'shoppingDays'])->middleware('throttle:30,1');
Route::post('/meal-plans/{id}/checkout', [MealPlanController::class, 'checkout'])->middleware('throttle:20,1');

// Аналітика + збір подій історії (покупки / лог їжі).
Route::get('/analytics', [AnalyticsController::class, 'index']);
Route::get('/purchases', [PurchaseController::class, 'index']);
Route::post('/purchases', [PurchaseController::class, 'store'])->middleware('throttle:60,1');
Route::post('/food-logs', [FoodLogController::class, 'store'])->middleware('throttle:120,1');
