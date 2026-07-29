<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/resumo', [DashboardController::class, 'resumo']);
