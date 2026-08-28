<?php

use App\Http\Controllers\PlataformaController;
use Illuminate\Support\Facades\Route;

Route::get('/lookups', [PlataformaController::class, 'listarLookupsPlataforma']);
Route::get('/listar', [PlataformaController::class, 'listarPlataforma']);
Route::get('/listar/{id}', [PlataformaController::class, 'listarPlataformaId']);
Route::post('/cadastrar', [PlataformaController::class, 'createPlataforma']);
Route::post('/cadastrar-rapido', [PlataformaController::class, 'cadastrarRapidoPlataforma']);
Route::put('/editar', [PlataformaController::class, 'editPlataforma']);
Route::delete('/excluir/{id}', [PlataformaController::class, 'deletePlataforma']);
Route::get('/plataformas-list', [PlataformaController::class, 'listarPlataformaAsync']);
