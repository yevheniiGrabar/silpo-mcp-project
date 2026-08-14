<?php

use App\Http\Controllers\MealPlanController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Меню тижня (демо: без auth, резолвимо demo-юзера; у проді — auth:sanctum).
Route::post('/meal-plans', [MealPlanController::class, 'store']);
Route::get('/meal-plans/{id}', [MealPlanController::class, 'show']);
Route::post('/meal-plans/{id}/checkout', [MealPlanController::class, 'checkout']);
