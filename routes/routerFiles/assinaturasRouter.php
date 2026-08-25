<?php

use App\Http\Controllers\AssinaturaController;
use Illuminate\Support\Facades\Route;

Route::get('/lookups', [AssinaturaController::class, 'listarLookupsAssinatura']);
Route::get('/listar', [AssinaturaController::class, 'listarAssinatura']);
Route::get('/listar/{id}', [AssinaturaController::class, 'listarAssinaturaId']);
Route::post('/cadastrar', [AssinaturaController::class, 'createAssinatura']);
Route::put('/editar', [AssinaturaController::class, 'editAssinatura']);
Route::delete('/excluir/{id}', [AssinaturaController::class, 'deleteAssinatura']);
Route::get('/assinaturas-list', [AssinaturaController::class, 'listarAssinaturaAsync']);
