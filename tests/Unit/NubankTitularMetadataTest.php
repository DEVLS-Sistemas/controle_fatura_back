<?php

namespace Tests\Unit;

use App\Services\Pdf\InvoicePdfParserService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class NubankTitularMetadataTest extends TestCase
{
    public function test_extrai_ola_e_nome_completo_antes_de_fatura(): void
    {
        $text = <<<'TXT'
Olá, Maysa.
Esta é a sua fatura de
agosto, no valor de
R$ 1.950,33

Data de vencimento: 21 AGO 2026

                                                                               MAYSA ARAUJO DA CONCEIÇÃO
                                                                               FATURA 21 AGO 2026             EMISSÃO E ENVIO 14 AGO 2026

RESUMO 5162 •••• •••• 7495
TXT;

        $titulares = $this->extractTitulares($text);

        $this->assertContains('Maysa', $titulares);
        $this->assertContains('MAYSA ARAUJO DA CONCEIÇÃO', $titulares);
    }

    public function test_extrai_ola_leonardo(): void
    {
        $text = "Olá, Leonardo.\nEsta é a sua fatura Nubank de\nmaio\n";
        $titulares = $this->extractTitulares($text);
        $this->assertContains('Leonardo', $titulares);
    }

    /**
     * @return list<string>
     */
    private function extractTitulares(string $text): array
    {
        $method = new ReflectionMethod(InvoicePdfParserService::class, 'extractTitularesFromText');
        $method->setAccessible(true);

        return $method->invoke(new InvoicePdfParserService(), $text);
    }
}
