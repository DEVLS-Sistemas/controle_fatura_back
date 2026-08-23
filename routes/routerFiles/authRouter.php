<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/recuperar-senha', [AuthController::class, 'recuperarSenha']);
Route::post('/verificar-codigo', [AuthController::class, 'verificarCodigo']);
Route::post('/redefinir-senha', [AuthController::class, 'redefinirSenha']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});
