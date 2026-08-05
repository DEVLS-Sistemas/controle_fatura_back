<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faturas', function (Blueprint $table) {
            $table->string('arquivo_csv')->nullable()->after('arquivo_pdf');
        });

        // Migra anexos não-PDF que estavam no campo legado arquivo_pdf.
        $faturas = DB::table('faturas')
            ->whereNotNull('arquivo_pdf')
            ->whereNull('deleted_at')
            ->get(['id', 'arquivo_pdf']);

        foreach ($faturas as $fatura) {
            $extension = strtolower(pathinfo((string) $fatura->arquivo_pdf, PATHINFO_EXTENSION));
            if (!in_array($extension, ['csv', 'txt', 'xml'], true)) {
                continue;
            }

            DB::table('faturas')
                ->where('id', $fatura->id)
                ->update([
                    'arquivo_csv' => $fatura->arquivo_pdf,
                    'arquivo_pdf' => null,
                ]);
        }
    }

    public function down(): void
    {
        // Devolve CSV para arquivo_pdf quando não houver PDF (melhor esforço).
        $faturas = DB::table('faturas')
            ->whereNotNull('arquivo_csv')
            ->whereNull('arquivo_pdf')
            ->get(['id', 'arquivo_csv']);

        foreach ($faturas as $fatura) {
            DB::table('faturas')
                ->where('id', $fatura->id)
                ->update(['arquivo_pdf' => $fatura->arquivo_csv]);
        }

        Schema::table('faturas', function (Blueprint $table) {
            $table->dropColumn('arquivo_csv');
        });
    }
};
