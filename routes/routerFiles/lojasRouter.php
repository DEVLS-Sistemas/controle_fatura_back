<?php

use App\Http\Controllers\LojaController;
use Illuminate\Support\Facades\Route;

Route::get('/lookups', [LojaController::class, 'listarLookupsLoja']);
Route::get('/listar', [LojaController::class, 'listarLoja']);
Route::get('/listar/{id}', [LojaController::class, 'listarLojaId']);
Route::post('/cadastrar', [LojaController::class, 'createLoja']);
Route::post('/cadastrar-rapido', [LojaController::class, 'cadastrarRapidoLoja']);
Route::post('/vincular-estabelecimentos', [LojaController::class, 'vincularEstabelecimentosLoja']);
Route::put('/editar', [LojaController::class, 'editLoja']);
Route::delete('/excluir/{id}', [LojaController::class, 'deleteLoja']);
Route::get('/lojas-list', [LojaController::class, 'listarLojaAsync']);
