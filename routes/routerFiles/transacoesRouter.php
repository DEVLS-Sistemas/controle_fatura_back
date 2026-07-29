<?php

use App\Http\Controllers\TransacaoController;
use Illuminate\Support\Facades\Route;

Route::get('/lookups', [TransacaoController::class, 'listarLookupsTransacao']);
Route::get('/listar', [TransacaoController::class, 'listarTransacao']);
Route::get('/listar/{id}', [TransacaoController::class, 'listarTransacaoId']);
Route::post('/cadastrar', [TransacaoController::class, 'createTransacao']);
Route::put('/editar', [TransacaoController::class, 'editTransacao']);
Route::delete('/excluir/{id}', [TransacaoController::class, 'deleteTransacao']);
Route::get('/transacoes-list', [TransacaoController::class, 'listarTransacaoAsync']);
Route::get('/exportar', [TransacaoController::class, 'exportarTransacao']);
