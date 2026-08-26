<?php

use App\Http\Controllers\CartaoController;
use Illuminate\Support\Facades\Route;

Route::get('/lookups', [CartaoController::class, 'listarLookupsCartao']);
Route::get('/listar', [CartaoController::class, 'listarCartao']);
Route::get('/listar/{id}', [CartaoController::class, 'listarCartaoId']);
Route::post('/cadastrar', [CartaoController::class, 'createCartao']);
Route::post('/cadastrar-rapido', [CartaoController::class, 'cadastrarRapidoCartao']);
Route::put('/editar', [CartaoController::class, 'editCartao']);
Route::delete('/excluir/{id}', [CartaoController::class, 'deleteCartao']);
Route::get('/cartoes-list', [CartaoController::class, 'listarCartaoAsync']);
Route::get('/bandeiras-list', [CartaoController::class, 'listarBandeirasAsync']);
Route::get('/numeros-list', [CartaoController::class, 'listarNumerosAsync']);
