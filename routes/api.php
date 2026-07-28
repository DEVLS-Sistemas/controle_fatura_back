<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('', function () {
        return response()->json([
            'api_name' => 'controle-fatura-back',
            'api_version' => '1.0.0',
        ]);
    });
});
