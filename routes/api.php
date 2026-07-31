<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('', function () {
        return response()->json([
            'api_name' => 'controle-fatura-back',
            'api_version' => '1.0.0',
        ]);
    });

    Route::prefix('auth')->group(function () {
        require __DIR__ . '/routerFiles/authRouter.php';
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::prefix('cartoes')->group(function () {
            require __DIR__ . '/routerFiles/cartoesRouter.php';
        });

        Route::prefix('categorias')->group(function () {
            require __DIR__ . '/routerFiles/categoriasRouter.php';
        });

        Route::prefix('subcategorias')->group(function () {
            require __DIR__ . '/routerFiles/subcategoriasRouter.php';
        });

        Route::prefix('estabelecimentos')->group(function () {
            require __DIR__ . '/routerFiles/estabelecimentosRouter.php';
        });

        Route::prefix('responsaveis')->group(function () {
            require __DIR__ . '/routerFiles/responsaveisRouter.php';
        });

        Route::prefix('faturas')->group(function () {
            require __DIR__ . '/routerFiles/faturasRouter.php';
        });

        Route::prefix('transacoes')->group(function () {
            require __DIR__ . '/routerFiles/transacoesRouter.php';
        });

        Route::prefix('dashboard')->group(function () {
            require __DIR__ . '/routerFiles/dashboardRouter.php';
        });
    });
});
