<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Rutas de autenticación
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');
