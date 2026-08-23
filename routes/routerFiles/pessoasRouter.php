<?php

use App\Http\Controllers\PessoaController;
use Illuminate\Support\Facades\Route;

Route::get('/lookups', [PessoaController::class, 'listarLookupsPessoa']);
Route::get('/listar', [PessoaController::class, 'listarPessoa']);
Route::get('/listar/{id}', [PessoaController::class, 'listarPessoaId']);
Route::post('/cadastrar', [PessoaController::class, 'createPessoa']);
Route::put('/editar', [PessoaController::class, 'editPessoa']);
Route::delete('/excluir/{id}', [PessoaController::class, 'deletePessoa']);
Route::get('/pessoas-list', [PessoaController::class, 'listarPessoaAsync']);
