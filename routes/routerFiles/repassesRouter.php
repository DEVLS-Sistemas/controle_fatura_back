<?php

use App\Http\Controllers\RepasseController;
use Illuminate\Support\Facades\Route;

Route::get('/lookups', [RepasseController::class, 'listarLookupsRepasse']);
Route::get('/matriz', [RepasseController::class, 'matriz']);
Route::post('/quitar-competencia', [RepasseController::class, 'quitarCompetencia']);
Route::get('/listar', [RepasseController::class, 'listarRepasse']);
Route::get('/listar/{id}', [RepasseController::class, 'listarRepasseId']);
Route::post('/cadastrar', [RepasseController::class, 'createRepasse']);
Route::put('/editar', [RepasseController::class, 'editRepasse']);
Route::delete('/excluir/{id}', [RepasseController::class, 'deleteRepasse']);
Route::get('/repasses-list', [RepasseController::class, 'listarRepasseAsync']);
