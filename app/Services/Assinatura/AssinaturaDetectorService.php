<?php

namespace App\Services\Assinatura;

use App\Models\AssinaturaIgnorada;
use App\Models\Transacao;
use App\Services\Categoria\CategoriaCoresTema;
use Carbon\Carbon;
use Exception;

class AssinaturaDetectorService
{
    public const TIPO_CHAVE_LOJA = AssinaturaIgnorada::TIPO_CHAVE_LOJA;
    public const TIPO_CHAVE_ESTABELECIMENTO = AssinaturaIgnorada::TIPO_CHAVE_ESTABELECIMENTO;

    public const STATUS_CONFIRMADA = 'confirmada';
    public const STATUS_CANDIDATA = 'candidata';
    public const STATUS_IGNORADA = 'ignorada';

    public const STATUS = [
        self::STATUS_CONFIRMADA,
        self::STATUS_CANDIDATA,
        self::STATUS_IGNORADA,
    ];

    public const STATUS_LABELS = [
        self::STATUS_CONFIRMADA => 'Confirmada',
        self::STATUS_CANDIDATA => 'Candidata',
        self::STATUS_IGNORADA => 'Ignorada',
    ];

    public const PERIODICIDADE_SEMANAL = 'semanal';
    public const PERIODICIDADE_QUINZENAL = 'quinzenal';
    public const PERIODICIDADE_MENSAL = 'mensal';
    public const PERIODICIDADE_TRIMESTRAL = 'trimestral';
    public const PERIODICIDADE_SEMESTRAL = 'semestral';
    public const PERIODICIDADE_ANUAL = 'anual';
    public const PERIODICIDADE_IRREGULAR = 'irregular';

    public const PERIODICIDADES = [
        self::PERIODICIDADE_SEMANAL,
        self::PERIODICIDADE_QUINZENAL,
        self::PERIODICIDADE_MENSAL,
        self::PERIODICIDADE_TRIMESTRAL,
        self::PERIODICIDADE_SEMESTRAL,
        self::PERIODICIDADE_ANUAL,
        self::PERIODICIDADE_IRREGULAR,
    ];

    public const PERIODICIDADE_LABELS = [
        self::PERIODICIDADE_SEMANAL => 'Semanal',
        self::PERIODICIDADE_QUINZENAL => 'Quinzenal',
        self::PERIODICIDADE_MENSAL => 'Mensal',
        self::PERIODICIDADE_TRIMESTRAL => 'Trimestral',
        self::PERIODICIDADE_SEMESTRAL => 'Semestral',
        self::PERIODICIDADE_ANUAL => 'Anual',
        self::PERIODICIDADE_IRREGULAR => 'Irregular',
    ];

    public const CONFIANCA_ALTA = 'alta';
    public const CONFIANCA_MEDIA = 'media';
    public const CONFIANCA_BAIXA = 'baixa';

    public const CONFIANCAS = [
        self::CONFIANCA_ALTA,
        self::CONFIANCA_MEDIA,
        self::CONFIANCA_BAIXA,
    ];

    public const CONFIANCA_LABELS = [
        self::CONFIANCA_ALTA => 'Alta',
        self::CONFIANCA_MEDIA => 'Média',
        self::CONFIANCA_BAIXA => 'Baixa',
    ];

    public const ACAO_CONFIRMAR = 'confirmar';
    public const ACAO_IGNORAR = 'ignorar';
    public const ACAO_RESTAURAR = 'restaurar';
    public const ACAO_DESFAZER_CONFIRMACAO = 'desfazer_confirmacao';

    public const ACOES = [
        self::ACAO_CONFIRMAR,
        self::ACAO_IGNORAR,
        self::ACAO_RESTAURAR,
        self::ACAO_DESFAZER_CONFIRMACAO,
    ];

    public const ACOES_LABELS = [
        self::ACAO_CONFIRMAR => 'Confirmar como pagamento de serviços',
        self::ACAO_IGNORAR => 'Ignorar (não é assinatura)',
        self::ACAO_RESTAURAR => 'Voltar a exibir',
        self::ACAO_DESFAZER_CONFIRMACAO => 'Desfazer confirmação',
    ];

    public const ORDENAR_ANUAL_DESC = 'anual_desc';
    public const ORDENAR_MENSAL_DESC = 'mensal_desc';
    public const ORDENAR_ULTIMA_DESC = 'ultima_desc';
    public const ORDENAR_TITULO = 'titulo';
    public const ORDENAR_COBRANCAS_DESC = 'cobrancas_desc';

    public const ORDENACOES = [
        self::ORDENAR_ANUAL_DESC,
        self::ORDENAR_MENSAL_DESC,
        self::ORDENAR_ULTIMA_DESC,
        self::ORDENAR_TITULO,
        self::ORDENAR_COBRANCAS_DESC,
    ];

    public const SIMILARIDADE_DESVIO_RELATIVO_MAX = 0.25;
    public const SIMILARIDADE_DESVIO_ABSOLUTO_MAX = 20.0;

    /** @var array<string, int> */
    private const MIN_COBRANCAS = [
        self::PERIODICIDADE_SEMANAL => 4,
        self::PERIODICIDADE_QUINZENAL => 3,
        self::PERIODICIDADE_MENSAL => 2,
        self::PERIODICIDADE_TRIMESTRAL => 2,
        self::PERIODICIDADE_SEMESTRAL => 2,
        self::PERIODICIDADE_ANUAL => 2,
        self::PERIODICIDADE_IRREGULAR => PHP_INT_MAX,
    ];

    /** @var array<string, float> */
    private const MULTIPLICADOR_ANUAL = [
        self::PERIODICIDADE_SEMANAL => 52.0,
        self::PERIODICIDADE_QUINZENAL => 26.0,
        self::PERIODICIDADE_MENSAL => 12.0,
        self::PERIODICIDADE_TRIMESTRAL => 4.0,
        self::PERIODICIDADE_SEMESTRAL => 2.0,
        self::PERIODICIDADE_ANUAL => 1.0,
    ];

    public function montarIdentificador(string $tipoChave, int $referenciaId): string
    {
        return $tipoChave . '-' . $referenciaId;
    }

    /**
     * @return array{tipo_chave: string, referencia_id: int}
     */
    public function parseIdentificador(?string $identificador): array
    {
        $raw = trim((string) $identificador);
        if ($raw === '') {
            throw new Exception('Identificador da assinatura é obrigatório', 422);
        }

        if (!preg_match('/^(loja|estabelecimento)-(\d+)$/', $raw, $m)) {
            throw new Exception('Identificador da assinatura inválido', 422);
        }

        return [
            'tipo_chave' => $m[1],
            'referencia_id' => (int) $m[2],
        ];
    }

    /**
     * Agrupa cobranças: tenta loja; se os valores não forem parecidos, parte por estabelecimento.
     *
     * @param array<int, array<string, mixed>> $eventos
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function agruparEventos(array $eventos): array
    {
        $buckets = [];
        foreach ($eventos as $evento) {
            $lojaId = isset($evento['loja_id']) && $evento['loja_id'] !== null
                ? (int) $evento['loja_id']
                : null;
            $estabId = (int) ($evento['estabelecimento_id'] ?? 0);
            if ($estabId <= 0) {
                continue;
            }

            $chave = $lojaId
                ? $this->montarIdentificador(self::TIPO_CHAVE_LOJA, $lojaId)
                : $this->montarIdentificador(self::TIPO_CHAVE_ESTABELECIMENTO, $estabId);

            $buckets[$chave][] = $evento;
        }

        $grupos = [];
        foreach ($buckets as $chave => $itens) {
            $parsed = $this->parseIdentificador($chave);
            $valores = array_map(fn ($e) => (float) $e['valor'], $itens);

            if (
                $parsed['tipo_chave'] === self::TIPO_CHAVE_LOJA
                && !$this->valoresSaoSimilares($valores)
            ) {
                foreach ($itens as $evento) {
                    $estabId = (int) $evento['estabelecimento_id'];
                    $sub = $this->montarIdentificador(self::TIPO_CHAVE_ESTABELECIMENTO, $estabId);
                    $grupos[$sub][] = $evento;
                }
                continue;
            }

            $grupos[$chave] = $itens;
        }

        return $grupos;
    }

    /**
     * @param array<int, array<string, mixed>> $eventos
     * @return array<string, mixed>|null
     */
    public function classificarGrupo(array $eventos, ?string $hoje = null, ?string $identificadorGrupo = null): ?array
    {
        if ($eventos === []) {
            return null;
        }

        $hojeCarbon = $hoje ? Carbon::parse($hoje)->startOfDay() : Carbon::today();
        $ordenados = $this->ordenarPorData($eventos);
        $valores = array_map(fn ($e) => (float) $e['valor'], $ordenados);
        $datas = array_map(fn ($e) => (string) $e['data'], $ordenados);

        $cobrancas = count($ordenados);
        $confirmadas = $this->contarConfirmadas($ordenados);
        $valoresSimilares = $this->valoresSaoSimilares($valores);
        $intervalos = $this->intervalosEmDias($datas);
        $intervaloMedio = $this->mediana($intervalos);
        $periodicidadeDetectada = $this->detectarPeriodicidade($intervalos);
        $oficial = $confirmadas > 0;

        $periodicidadeAssumida = false;
        $periodicidade = $periodicidadeDetectada;

        if ($oficial && ($cobrancas === 1 || $periodicidade === self::PERIODICIDADE_IRREGULAR)) {
            $periodicidade = self::PERIODICIDADE_MENSAL;
            $periodicidadeAssumida = true;
        }

        $ehCandidata = !$oficial
            && $valoresSimilares
            && $periodicidade !== self::PERIODICIDADE_IRREGULAR
            && $cobrancas >= (self::MIN_COBRANCAS[$periodicidade] ?? PHP_INT_MAX);

        if (!$oficial && !$ehCandidata) {
            return null;
        }

        $status = $oficial ? self::STATUS_CONFIRMADA : self::STATUS_CANDIDATA;
        $valorMedio = $this->money((float) $this->mediana($valores));
        $valorUltima = $this->money((float) end($valores));
        $primeira = $datas[0];
        $ultima = $datas[array_key_last($datas)];
        $gasto12 = $this->gasto12Meses($ordenados, $hojeCarbon);
        $estimativaAnual = $this->estimarAnual($periodicidade, $valorMedio, $gasto12);
        $estimativaMensal = $this->money($estimativaAnual / 12);
        $proxima = $this->proximaEstimada($ultima, $intervaloMedio, $periodicidadeAssumida);

        $confianca = $this->resolverConfianca(
            $status,
            $cobrancas,
            $periodicidade,
            $valoresSimilares,
            $periodicidadeAssumida
        );

        $meta = $this->metaDoGrupo($ordenados, $identificadorGrupo);
        $origens = $this->origemPredominante($ordenados);

        return [
            'identificador' => $meta['identificador'],
            'tipo_chave' => $meta['tipo_chave'],
            'referencia_id' => $meta['referencia_id'],
            'titulo' => $meta['titulo'],
            'loja_id' => $meta['loja_id'],
            'loja_nome' => $meta['loja_nome'],
            'estabelecimento_id' => $meta['estabelecimento_id'],
            'estabelecimento_nome' => $meta['estabelecimento_nome'],
            'estabelecimentos' => $meta['estabelecimentos'],
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status],
            'periodicidade' => $periodicidade,
            'periodicidade_label' => self::PERIODICIDADE_LABELS[$periodicidade],
            'periodicidade_assumida' => $periodicidadeAssumida,
            'confianca' => $confianca,
            'confianca_label' => self::CONFIANCA_LABELS[$confianca],
            'cobrancas' => $cobrancas,
            'cobrancas_confirmadas' => $confirmadas,
            'cobrancas_pendentes' => $cobrancas - $confirmadas,
            'valores_similares' => $valoresSimilares,
            'valor_medio' => $valorMedio,
            'valor_ultima' => $valorUltima,
            'intervalo_medio_dias' => $intervaloMedio !== null ? round($intervaloMedio, 1) : null,
            'primeira_cobranca' => $primeira,
            'ultima_cobranca' => $ultima,
            'proxima_estimada' => $proxima,
            'gasto_12_meses' => $gasto12,
            'estimativa_mensal' => $estimativaMensal,
            'estimativa_anual' => $estimativaAnual,
            'origem_compra_predominante' => $origens['value'],
            'origem_compra_predominante_label' => $origens['label'],
            'categoria_id' => $meta['categoria_id'],
            'categoria_nome' => $meta['categoria_nome'],
            'categoria_cor' => CategoriaCoresTema::corParaGrafico(
                $meta['categoria_cor'] ?? null,
                $meta['categoria_id'] ?? null
            ),
            'subcategoria_id' => $meta['subcategoria_id'],
            'subcategoria_nome' => $meta['subcategoria_nome'],
            'responsavel_id' => $meta['responsavel_id'],
            'responsavel_nome' => $meta['responsavel_nome'],
            'ignorada' => false,
            'pode_confirmar' => $status === self::STATUS_CANDIDATA,
            'acoes_disponiveis' => $this->acoesDisponiveis($status),
        ];
    }

    /**
     * @param array<int, float|int|string> $valores
     */
    public function valoresSaoSimilares(array $valores): bool
    {
        $nums = array_values(array_map('floatval', $valores));
        if (count($nums) <= 1) {
            return true;
        }

        $mediana = $this->mediana($nums);
        $amplitude = max($nums) - min($nums);

        if ($amplitude <= self::SIMILARIDADE_DESVIO_ABSOLUTO_MAX) {
            return true;
        }

        if ($mediana === null || abs($mediana) < 0.01) {
            return false;
        }

        return ($amplitude / abs($mediana)) <= self::SIMILARIDADE_DESVIO_RELATIVO_MAX;
    }

    /**
     * @param array<int, int|float> $intervalos
     */
    public function detectarPeriodicidade(array $intervalos): string
    {
        $mediana = $this->mediana($intervalos);
        if ($mediana === null) {
            return self::PERIODICIDADE_IRREGULAR;
        }

        $dias = (float) $mediana;

        if ($dias >= 5 && $dias <= 9) {
            return self::PERIODICIDADE_SEMANAL;
        }
        if ($dias >= 13 && $dias <= 17) {
            return self::PERIODICIDADE_QUINZENAL;
        }
        if ($dias >= 25 && $dias <= 40) {
            return self::PERIODICIDADE_MENSAL;
        }
        if ($dias >= 80 && $dias <= 105) {
            return self::PERIODICIDADE_TRIMESTRAL;
        }
        if ($dias >= 160 && $dias <= 200) {
            return self::PERIODICIDADE_SEMESTRAL;
        }
        if ($dias >= 330 && $dias <= 400) {
            return self::PERIODICIDADE_ANUAL;
        }

        return self::PERIODICIDADE_IRREGULAR;
    }

    public function estimarAnual(string $periodicidade, float $valorMedio, float $gasto12Meses): float
    {
        if (isset(self::MULTIPLICADOR_ANUAL[$periodicidade])) {
            return $this->money($valorMedio * self::MULTIPLICADOR_ANUAL[$periodicidade]);
        }

        return $this->money($gasto12Meses);
    }

    /**
     * @param array<int, float|int> $valores
     */
    public function mediana(array $valores): ?float
    {
        $nums = array_values(array_map('floatval', $valores));
        $n = count($nums);
        if ($n === 0) {
            return null;
        }

        sort($nums, SORT_NUMERIC);
        $meio = intdiv($n, 2);

        if ($n % 2 === 1) {
            return (float) $nums[$meio];
        }

        return ((float) $nums[$meio - 1] + (float) $nums[$meio]) / 2;
    }

    /**
     * @param array<int, string> $datas
     * @return array<int, int>
     */
    public function intervalosEmDias(array $datas): array
    {
        $ordenadas = $datas;
        sort($ordenadas);
        $out = [];
        for ($i = 1, $n = count($ordenadas); $i < $n; $i++) {
            $out[] = (int) Carbon::parse($ordenadas[$i - 1])->diffInDays(Carbon::parse($ordenadas[$i]));
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $itens
     * @return array<int, array<string, mixed>>
     */
    public function ordenarItens(array $itens, string $ordenar): array
    {
        $campo = match ($ordenar) {
            self::ORDENAR_MENSAL_DESC => 'estimativa_mensal',
            self::ORDENAR_COBRANCAS_DESC => 'cobrancas',
            default => 'estimativa_anual',
        };

        usort($itens, function (array $a, array $b) use ($ordenar, $campo) {
            if ($ordenar === self::ORDENAR_TITULO) {
                return strcasecmp((string) $a['titulo'], (string) $b['titulo']);
            }

            if ($ordenar === self::ORDENAR_ULTIMA_DESC) {
                return strcmp((string) $b['ultima_cobranca'], (string) $a['ultima_cobranca']);
            }

            $diff = ((float) $b[$campo]) <=> ((float) $a[$campo]);
            if ($diff !== 0) {
                return $diff;
            }

            return strcasecmp((string) $a['titulo'], (string) $b['titulo']);
        });

        return array_values($itens);
    }

    /**
     * @param array<int, array<string, mixed>> $itens
     * @return array<string, mixed>
     */
    public function montarTotais(array $itens): array
    {
        $visiveis = array_values(array_filter(
            $itens,
            fn (array $i) => ($i['status'] ?? '') !== self::STATUS_IGNORADA
        ));

        $confirmadas = array_values(array_filter(
            $visiveis,
            fn (array $i) => ($i['status'] ?? '') === self::STATUS_CONFIRMADA
        ));
        $candidatas = array_values(array_filter(
            $visiveis,
            fn (array $i) => ($i['status'] ?? '') === self::STATUS_CANDIDATA
        ));

        $somar = function (array $lista, string $campo): float {
            $total = 0.0;
            foreach ($lista as $item) {
                $total += (float) ($item[$campo] ?? 0);
            }

            return $this->money($total);
        };

        return [
            'assinaturas' => count($visiveis),
            'confirmadas' => count($confirmadas),
            'candidatas' => count($candidatas),
            'gasto_12_meses' => $somar($visiveis, 'gasto_12_meses'),
            'estimativa_mensal' => $somar($visiveis, 'estimativa_mensal'),
            'estimativa_anual' => $somar($visiveis, 'estimativa_anual'),
            'gasto_12_meses_confirmadas' => $somar($confirmadas, 'gasto_12_meses'),
            'estimativa_anual_confirmadas' => $somar($confirmadas, 'estimativa_anual'),
            'estimativa_anual_candidatas' => $somar($candidatas, 'estimativa_anual'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $eventos
     * @return array<int, array<string, mixed>>
     */
    public function cobrancasRecentes(array $eventos, int $limite = 24): array
    {
        $ordenados = array_reverse($this->ordenarPorData($eventos));

        return array_map(function (array $e) {
            $origem = $e['origem_compra'] ?? null;

            return [
                'id' => (int) $e['id'],
                'data' => (string) $e['data'],
                'valor' => $this->money((float) $e['valor']),
                'origem_compra' => $origem,
                'origem_compra_label' => $origem && isset(Transacao::ORIGENS_COMPRA_LABELS[$origem])
                    ? Transacao::ORIGENS_COMPRA_LABELS[$origem]
                    : null,
                'confirmada' => !empty($e['eh_assinatura']),
                'eh_assinatura' => !empty($e['eh_assinatura']),
                'estabelecimento_id' => (int) ($e['estabelecimento_id'] ?? 0),
                'estabelecimento_nome' => $e['estabelecimento_nome'] ?? null,
                'fatura_id' => isset($e['fatura_id']) ? (int) $e['fatura_id'] : null,
                'fatura_mes' => isset($e['fatura_mes']) ? (int) $e['fatura_mes'] : null,
                'fatura_ano' => isset($e['fatura_ano']) ? (int) $e['fatura_ano'] : null,
                'responsavel_id' => isset($e['responsavel_id']) ? (int) $e['responsavel_id'] : null,
                'responsavel_nome' => $e['responsavel_nome'] ?? null,
                'observacoes' => $e['observacoes'] ?? null,
            ];
        }, array_slice($ordenados, 0, $limite));
    }

    /**
     * @param array<int, array<string, mixed>> $eventos
     */
    private function contarConfirmadas(array $eventos): int
    {
        $n = 0;
        foreach ($eventos as $e) {
            if (!empty($e['eh_assinatura'])) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @return array<int, string>
     */
    public function acoesDisponiveis(string $status): array
    {
        return match ($status) {
            self::STATUS_CANDIDATA => [self::ACAO_CONFIRMAR, self::ACAO_IGNORAR],
            self::STATUS_CONFIRMADA => [self::ACAO_DESFAZER_CONFIRMACAO],
            self::STATUS_IGNORADA => [self::ACAO_RESTAURAR],
            default => [],
        };
    }

    /**
     * @param array<int, array<string, mixed>> $eventos
     */
    private function gasto12Meses(array $eventos, Carbon $hoje): float
    {
        $inicio = $hoje->copy()->subYear()->toDateString();
        $fim = $hoje->toDateString();
        $total = 0.0;
        foreach ($eventos as $e) {
            $data = (string) $e['data'];
            if ($data >= $inicio && $data <= $fim) {
                $total += (float) $e['valor'];
            }
        }

        return $this->money($total);
    }

    private function proximaEstimada(string $ultima, ?float $intervaloMedio, bool $assumidaMensal): ?string
    {
        if ($assumidaMensal) {
            return Carbon::parse($ultima)->addMonth()->toDateString();
        }

        if ($intervaloMedio === null || $intervaloMedio < 5) {
            return null;
        }

        return Carbon::parse($ultima)->addDays((int) round($intervaloMedio))->toDateString();
    }

    private function resolverConfianca(
        string $status,
        int $cobrancas,
        string $periodicidade,
        bool $valoresSimilares,
        bool $periodicidadeAssumida
    ): string {
        if ($periodicidadeAssumida) {
            return self::CONFIANCA_BAIXA;
        }

        if (
            $valoresSimilares
            && $periodicidade !== self::PERIODICIDADE_IRREGULAR
            && $cobrancas >= 3
        ) {
            return self::CONFIANCA_ALTA;
        }

        if ($status === self::STATUS_CONFIRMADA && $cobrancas >= 2 && $valoresSimilares) {
            return self::CONFIANCA_ALTA;
        }

        if ($cobrancas >= 2 && in_array($periodicidade, [
            self::PERIODICIDADE_MENSAL,
            self::PERIODICIDADE_TRIMESTRAL,
            self::PERIODICIDADE_SEMESTRAL,
            self::PERIODICIDADE_ANUAL,
        ], true)) {
            return self::CONFIANCA_MEDIA;
        }

        return self::CONFIANCA_BAIXA;
    }

    /**
     * @param array<int, array<string, mixed>> $eventos
     * @return array<string, mixed>
     */
    private function metaDoGrupo(array $eventos, ?string $identificadorGrupo): array
    {
        $lojaIds = [];
        $estabMap = [];
        foreach ($eventos as $e) {
            if (!empty($e['loja_id'])) {
                $lojaIds[(int) $e['loja_id']] = (string) ($e['loja_nome'] ?? '');
            }
            $estabId = (int) ($e['estabelecimento_id'] ?? 0);
            if ($estabId > 0) {
                $estabMap[$estabId] = (string) ($e['estabelecimento_nome'] ?? '');
            }
        }

        if ($identificadorGrupo) {
            $parsed = $this->parseIdentificador($identificadorGrupo);
            $tipo = $parsed['tipo_chave'];
            $ref = $parsed['referencia_id'];
        } elseif (count($lojaIds) === 1 && count($estabMap) > 1) {
            $tipo = self::TIPO_CHAVE_LOJA;
            $ref = (int) array_key_first($lojaIds);
        } else {
            $tipo = self::TIPO_CHAVE_ESTABELECIMENTO;
            $ref = (int) array_key_first($estabMap);
        }

        $usaLoja = $tipo === self::TIPO_CHAVE_LOJA;
        $tituloLoja = $usaLoja ? ($lojaIds[$ref] ?? reset($lojaIds) ?: '') : (reset($lojaIds) ?: '');
        $tituloEstab = $estabMap[$ref] ?? (reset($estabMap) ?: '');
        $titulo = $tituloLoja !== '' ? $tituloLoja : ($tituloEstab ?: 'Assinatura');

        $ultimo = $eventos[array_key_last($eventos)];
        $estabelecimentos = [];
        foreach ($estabMap as $id => $nome) {
            $estabelecimentos[] = ['id' => (int) $id, 'nome' => $nome];
        }

        return [
            'identificador' => $this->montarIdentificador($tipo, $ref),
            'tipo_chave' => $tipo,
            'referencia_id' => $ref,
            'titulo' => $titulo,
            'loja_id' => $usaLoja
                ? $ref
                : (!empty($ultimo['loja_id']) ? (int) $ultimo['loja_id'] : null),
            'loja_nome' => $tituloLoja !== '' ? $tituloLoja : ($ultimo['loja_nome'] ?? null),
            'estabelecimento_id' => count($estabMap) === 1 ? (int) array_key_first($estabMap) : null,
            'estabelecimento_nome' => count($estabMap) === 1 ? (string) reset($estabMap) : null,
            'estabelecimentos' => $estabelecimentos,
            'categoria_id' => isset($ultimo['categoria_id']) ? (int) $ultimo['categoria_id'] : null,
            'categoria_nome' => $ultimo['categoria_nome'] ?? null,
            'categoria_cor' => CategoriaCoresTema::corParaGrafico(
                $ultimo['categoria_cor'] ?? null,
                $ultimo['categoria_id'] ?? null
            ),
            'subcategoria_id' => isset($ultimo['subcategoria_id']) ? (int) $ultimo['subcategoria_id'] : null,
            'subcategoria_nome' => $ultimo['subcategoria_nome'] ?? null,
            'responsavel_id' => isset($ultimo['responsavel_id']) ? (int) $ultimo['responsavel_id'] : null,
            'responsavel_nome' => $ultimo['responsavel_nome'] ?? null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $eventos
     * @return array{value: ?string, label: ?string}
     */
    private function origemPredominante(array $eventos): array
    {
        $contagem = [];
        foreach ($eventos as $e) {
            $origem = $e['origem_compra'] ?? null;
            $key = $origem ?: '_null';
            $contagem[$key] = ($contagem[$key] ?? 0) + 1;
        }
        arsort($contagem);
        $top = array_key_first($contagem);
        if ($top === null || $top === '_null') {
            return ['value' => null, 'label' => null];
        }

        return [
            'value' => $top,
            'label' => Transacao::ORIGENS_COMPRA_LABELS[$top] ?? $top,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $eventos
     * @return array<int, array<string, mixed>>
     */
    private function ordenarPorData(array $eventos): array
    {
        usort($eventos, function (array $a, array $b) {
            $cmp = strcmp((string) $a['data'], (string) $b['data']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        return array_values($eventos);
    }

    private function money(float $valor): float
    {
        return round($valor, 2);
    }
}
