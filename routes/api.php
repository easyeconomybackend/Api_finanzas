<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MovementController;
use App\Http\Controllers\TagController;

Route::get('/', function () {
    return response()->json(['message' => 'Welcome to the Finance API']);
});

Route::get('/error', function () {
    return response()->json(['error' => 'error into the server'], 500);
})->name('login');

// Rutas de autenticación
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

// Rutas // Movimientos
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/movements', [MovementController::class, 'index']);
    Route::post('/movements', [MovementController::class, 'create']);
    Route::post('/movements/sugerir-voz', [MovementController::class, 'sugerirMovimientoConIA'])
         ->middleware('throttle:5,1'); // opcional: limitar llamadas a la IA
    Route::post('/tags/create', [TagController::class, 'store']);
    Route::post('/tags/suggestion', [TagController::class, 'sugerirDesdeIA']);
    Route::get('/tags', [TagController::class, 'index']);
});

