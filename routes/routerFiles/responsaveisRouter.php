<?php

use App\Http\Controllers\ResponsavelController;
use Illuminate\Support\Facades\Route;

Route::get('/lookups', [ResponsavelController::class, 'listarLookupsResponsavel']);
Route::get('/listar', [ResponsavelController::class, 'listarResponsavel']);
Route::get('/listar/{id}', [ResponsavelController::class, 'listarResponsavelId']);
Route::post('/cadastrar', [ResponsavelController::class, 'createResponsavel']);
Route::put('/editar', [ResponsavelController::class, 'editResponsavel']);
Route::delete('/excluir/{id}', [ResponsavelController::class, 'deleteResponsavel']);
Route::get('/responsaveis-list', [ResponsavelController::class, 'listarResponsavelAsync']);
