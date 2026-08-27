<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/resumo', [DashboardController::class, 'resumo']);
Route::get('/projecao-faturas', [DashboardController::class, 'projecaoFaturas']);
Route::get('/ranking-parceladas', [DashboardController::class, 'rankingParceladas']);
Route::get('/gastos-criticos', [DashboardController::class, 'gastosCriticos']);
Route::get('/gastos-por-categoria', [DashboardController::class, 'gastosPorCategoria']);
Route::get('/raio-x', [DashboardController::class, 'raioX']);
