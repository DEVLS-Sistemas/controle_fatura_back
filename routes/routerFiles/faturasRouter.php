<?php

use App\Http\Controllers\FaturaController;
use Illuminate\Support\Facades\Route;

Route::get('/lookups', [FaturaController::class, 'listarLookupsFatura']);
Route::get('/listar', [FaturaController::class, 'listarFatura']);
Route::get('/listar/{id}', [FaturaController::class, 'listarFaturaId']);
Route::post('/cadastrar', [FaturaController::class, 'createFatura']);
Route::put('/editar', [FaturaController::class, 'editFatura']);
Route::delete('/excluir-todas', [FaturaController::class, 'deleteTodasFaturas']);
Route::delete('/excluir/{id}', [FaturaController::class, 'deleteFatura']);
Route::get('/faturas-list', [FaturaController::class, 'listarFaturaAsync']);
Route::post('/upload-pdf', [FaturaController::class, 'uploadPdf']);
Route::post('/processar/{id}', [FaturaController::class, 'processarPdf']);
Route::get('/pdf/{id}', [FaturaController::class, 'downloadPdf']);
