<?php

namespace Tests\Unit;

use App\Models\Anexo;
use App\Support\AnexoNomeBlob;
use Tests\TestCase;

class AnexoNomeBlobTest extends TestCase
{
    public function test_mantem_nome_simples_com_extensao(): void
    {
        $this->assertSame('fatura.pdf', AnexoNomeBlob::sanitizar('fatura.pdf', 'pdf', 1));
    }

    public function test_sanitiza_acentos_espacos_e_caracteres_invalidos(): void
    {
        $this->assertSame(
            'fatura-nubank-setembro.pdf',
            AnexoNomeBlob::sanitizar('Fatura Nubank Setembro.pdf', 'pdf', 1)
        );
        $this->assertSame(
            'nota-fiscal-copia.pdf',
            AnexoNomeBlob::sanitizar('Nota Fiscal (cópia).pdf', 'pdf', 2)
        );
    }

    public function test_ignora_caminho_e_usa_so_o_basename(): void
    {
        $this->assertSame(
            'fatura.pdf',
            AnexoNomeBlob::sanitizar('../../etc/Fatura.pdf', 'pdf', 9)
        );
        $this->assertSame(
            'nota.pdf',
            AnexoNomeBlob::sanitizar('C:\\uploads\\nota.pdf', 'pdf', 9)
        );
    }

    public function test_nome_vazio_cai_no_id(): void
    {
        $this->assertSame('3.pdf', AnexoNomeBlob::sanitizar('', 'pdf', 3));
        $this->assertSame('4.pdf', AnexoNomeBlob::sanitizar('???', 'pdf', 4));
    }

    public function test_usa_extensao_do_catalogo_quando_o_nome_nao_tem(): void
    {
        $this->assertSame('comprovante.pdf', AnexoNomeBlob::sanitizar('comprovante', 'pdf', 1));
    }

    public function test_de_anexo_usa_nome_original_e_extensao(): void
    {
        $anexo = new Anexo;
        $anexo->forceFill([
            'nome_original' => 'Fatura Nubank.pdf',
            'extensao' => 'pdf',
        ]);
        $anexo->id = 11;

        $this->assertSame('fatura-nubank.pdf', AnexoNomeBlob::deAnexo($anexo));
    }
}
