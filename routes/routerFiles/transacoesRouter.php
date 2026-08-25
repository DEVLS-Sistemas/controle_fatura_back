<?php

use App\Http\Controllers\TransacaoController;
use Illuminate\Support\Facades\Route;

Route::get('/lookups', [TransacaoController::class, 'listarLookupsTransacao']);
Route::get('/listar', [TransacaoController::class, 'listarTransacao']);
Route::get('/visualizar/{identificador}', [TransacaoController::class, 'visualizarCompra']);
Route::get('/listar/{id}', [TransacaoController::class, 'listarTransacaoId']);
Route::post('/cadastrar', [TransacaoController::class, 'createTransacao']);
Route::put('/editar', [TransacaoController::class, 'editTransacao']);
Route::delete('/excluir/{id}', [TransacaoController::class, 'deleteTransacao']);
Route::get('/transacoes-list', [TransacaoController::class, 'listarTransacaoAsync']);
Route::get('/exportar', [TransacaoController::class, 'exportarTransacao']);
Route::get('/estabelecimentos-do-filtro', [TransacaoController::class, 'listarEstabelecimentosDoFiltro']);
Route::get('/candidatos-conciliacao/{identificador}', [TransacaoController::class, 'listarCandidatosConciliacao']);
Route::post('/conciliar', [TransacaoController::class, 'conciliarTransacao']);
Route::post('/desvincular', [TransacaoController::class, 'desvincularConciliacao']);
Route::post('/rejeitar-conciliacao', [TransacaoController::class, 'rejeitarConciliacao']);
Route::get('/anexos', [TransacaoController::class, 'listarAnexosCompra']);
Route::post('/anexos', [TransacaoController::class, 'cadastrarAnexoCompra']);
Route::get('/anexos/{id}', [TransacaoController::class, 'downloadAnexoCompra']);
Route::delete('/anexos/{id}', [TransacaoController::class, 'excluirAnexoCompra']);
Route::get('/historico/{identificador}', [TransacaoController::class, 'listarHistoricoCompra']);
