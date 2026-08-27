# Especificação — Transações

## Tabela `transacoes`

| Campo | Tipo | Obs |
|-------|------|-----|
| user_id | FK | |
| fatura_id | FK | |
| cartao_numero_id | FK nullable → `cartao_numeros` | final do cartão da compra; obrigatório no create manual (auto se só houver 1) |
| estabelecimento_id | FK nullable | só quando informado; no create **manual** fica `null` até conciliar (não criar a partir da descrição) |
| data | date nullable | data da compra (igual em todas as parcelas do grupo) |
| valor | decimal | valor da parcela (o que cai na fatura do mês) |
| parcelas_total | int nullable | 1..36 |
| parcela_atual | int nullable | 1..N |
| valor_parcela | decimal nullable | em geral = `valor` |
| compra_grupo_id | uuid nullable | liga as N parcelas da mesma compra; null se à vista |
| tipo | enum | purchase, payment, refund, advance, fee, **carryover** (`fee` = encargos; `carryover` = saldo restante da fatura anterior — operação, não compra) |
| origem_compra | enum nullable | COMPRAS_ONLINE, COMPRAS_PRESENCIAL, PAGAMENTO_SERVICOS, PAGAMENTO_FATURA — origem/canal da compra; **obrigatório no create** |
| eh_assinatura | boolean | default false; a compra é assinatura (lista oficial). Independente de `origem_compra` |
| categoria_id | FK nullable | categoria **da compra** |
| subcategoria_id | FK nullable | exige categoria + vínculo N:N |
| responsavel_id | FK | obrigatório; default = responsável `Eu` |
| observacoes | text nullable | **o que foi comprado** no cadastro manual (rótulo “Descrição da compra”); aparece na fatura e na transação |
| descricao | string nullable | espelho da descrição amigável (“Mouse Logitech”); o create copia `observacoes` ↔ `descricao`; não é sobrescrito pelo PDF |
| descricao_fatura | string nullable | nome original do lançamento (“PAG*LOJA XYZ”) após conciliação |
| status_conciliacao | string nullable | `nao_conciliada` \| `pendente` \| `conciliada` \| `rejeitada` (compras manuais) |
| lancamento_id | FK nullable → `transacoes` | lançamento da fatura vinculado |
| ignorar_no_total | boolean | true na compra conciliada (o lançamento do PDF é quem conta na fatura) |
| importada_pdf | boolean | true se veio do PDF/CSV **desta** fatura |
| compra_manual | boolean | true **somente** se o usuário cadastrou (Nova compra / Posso comprar). false no import do PDF e nas parcelas materializadas automaticamente em faturas sem anexo |

## Rotas (`/api/v1/transacoes`)

CRUD padrão + `transacoes-list` + export + estabelecimentos do filtro:

```http
GET /api/v1/transacoes/exportar
GET /api/v1/transacoes/estabelecimentos-do-filtro
GET /api/v1/transacoes/visualizar/{identificador}
GET /api/v1/transacoes/candidatos-conciliacao/{identificador}
POST /api/v1/transacoes/conciliar
POST /api/v1/transacoes/desvincular
POST /api/v1/transacoes/rejeitar-conciliacao
GET /api/v1/transacoes/anexos
POST /api/v1/transacoes/anexos
GET /api/v1/transacoes/anexos/{id}
DELETE /api/v1/transacoes/anexos/{id}
GET /api/v1/transacoes/historico/{identificador}
DELETE /api/v1/transacoes/excluir/{id}?excluir_grupo=1
```

CSV UTF-8 (BOM) com separador `;`, mesmos filtros da listagem.

### Estabelecimentos do filtro

```http
GET /api/v1/transacoes/estabelecimentos-do-filtro?palavra_chave=atacad&apenas_sem_loja=1
```

Mesmos filtros de `/listar`. Retorna **uma linha por estabelecimento** distinto (não por transação), com:

| Campo | Obs |
|-------|-----|
| `id` | estabelecimento_id |
| `nome` | nome da maquininha |
| `loja_id` / `loja_nome` | vínculo atual (se houver) |
| `transacoes_count` | qtd de transações desse estabelecimento **no filtro** |

Query extra: `apenas_sem_loja=true` — só estabelecimentos sem loja.

Usado no botão **Vincular com loja** da listagem de compras. Ver [`frontend-prompt-loja-estabelecimento.md`](../frontend-prompt-loja-estabelecimento.md).

### Visualização da compra

```http
GET /api/v1/transacoes/visualizar/{identificador}?mes=8&ano=2026
```

`identificador` = `compra_grupo_id` (UUID, ranking) **ou** `id` da transação. Se a transação pertence a um grupo, devolve o **grupo inteiro**.

- `mes` / `ano`: competência de referência (default: atual) — mesmo critério do ranking (pago = fatura ≤ referência)
- Concentra metadados da compra: data, cartão/bandeira/final, categoria/sub, estabelecimento/loja, responsável, origem
- `parcelas[]` com `status_parcela` (`paga` | `atual` | `aberta`), fatura e repasse
- `conciliacao` (status, mensagem, lançamento vinculado) e `anexos[]`
- À vista: `avista: true`, `compra_grupo_id: null`, 1 item em `parcelas`

Prompt: [`frontend-prompt-visualizacao-compra.md`](../frontend-prompt-visualizacao-compra.md)

Lookups: `tipos`, `origens_compra`, `status_conciliacao`, `categorias`, `subcategorias`, `responsaveis`, `default_responsavel_id`, `cartoes` (cada item traz `pessoa_id`, `pessoa_nome`, `pessoa_eh_principal`), `faturas`.

Estabelecimentos **não** vêm no lookups — usar busca async:

```http
GET /api/v1/estabelecimentos/estabelecimentos-list?palavra_chave=atacad
```

## Create — compra à vista e parcelada

### Payload (parcelado)

```json
{
  "cartao_id": 1,
  "cartao_numero_id": 10,
  "data": "2026-03-15",
  "estabelecimento_id": 104,
  "valor_compra": "1000,00",
  "parcelas_total": 10,
  "parcelas": [
    { "parcela": 1, "valor": "100,00" },
    { "parcela": 2, "valor": "100,00" }
  ],
  "tipo": "purchase",
  "origem_compra": "COMPRAS_PRESENCIAL",
  "categoria_id": 1,
  "subcategoria_id": 1,
  "responsavel_id": 1,
  "observacoes": "..."
}
```

### Regras

- `valor_compra` = total da venda. Aceita BR (`125,50`) ou decimal. Alternativa: `valor` (ver legado abaixo).
- `parcelas_total` ∈ 1..36 (default 1).
- `parcelas[]` opcional: se omitido, backend divide `valor_compra` igualmente (centavos na última).
- Se `parcelas[]` vier: tamanho = `parcelas_total`, números 1..N sem buracos; soma deve bater com `valor_compra` (tol. R$ 0,01) → senão 422.
- Sempre materializa **1..N** transações (ignora `parcela_atual` no create).
- `cartao_numero_id`: final do cartão da compra. No create manual é obrigatório se houver 2+ números elegíveis; com exatamente 1, o backend auto-seleciona. A bandeira da fatura é derivada do número (ou de `cartao_bandeira_id` / fatura).
- Ciclo do cartão (`dia_limite_fatura`) define a fatura da parcela 1; demais = +1 mês na mesma bandeira (`findOrCreateByCartaoPeriodo`).
  - `data.day <= dia_limite` → fatura do mês da compra; após o limite → fatura do mês seguinte.
- `data` da compra é gravada igual em todas as linhas; cada uma tem seu `fatura_id` e o mesmo `cartao_numero_id`.
- `parcelas_total > 1` → todas compartilham o mesmo `compra_grupo_id` (UUID).
- À vista (`parcelas_total = 1`): uma linha, `compra_grupo_id = null`.
- `fatura_id` explícito ainda é aceito (tela da fatura); o cartão/bandeira vêm da fatura. Sem `data`, usa mês/ano da fatura como base.
- Estabelecimento: `estabelecimento_id` **ou** `estabelecimento` (texto; find-or-create **somente se enviado**). No cadastro manual **omitir**: não criar estabelecimento a partir da descrição; fica `null` até a conciliação.
- `observacoes`: **o que foi comprado** (campo “Descrição da compra”). Obrigatório no create se não houver estabelecimento. O back espelha em `descricao`.
- `descricao`: se omitida, copia `observacoes` (e vice-versa).
- `fatura_id` no create: força a competência da 1ª parcela (senão usa o ciclo do cartão).
- Categoria/subcategoria: opcionais; create usa padrões do estabelecimento se omitidas.
- Subcategoria sem categoria → 422.
- Responsável omitido → `Eu`.
- `origem_compra` **obrigatório** no create. Valores:
  - `COMPRAS_ONLINE` — compra em e-commerce / internet
  - `COMPRAS_PRESENCIAL` — compra no estabelecimento físico
  - `PAGAMENTO_SERVICOS` — assinatura / cartão cadastrado com desconto automático
  - `PAGAMENTO_FATURA` — pagamento de fatura
- `eh_assinatura` (boolean, opcional). No create, se omitido e a origem for `PAGAMENTO_SERVICOS`, assume `true`. Lista/edição expõem o campo. Filtro `eh_assinatura=true`.
- Em compras parceladas, a mesma `origem_compra` é gravada em todas as parcelas.

### Resposta do create

```json
{
  "transacao": {
    "data": {
      "compra_grupo_id": "uuid-ou-null",
      "valor_compra": 1000,
      "parcelas_total": 10,
      "transacoes": [ { "id": 1, "parcela_atual": 1, "...": "..." } ]
    },
    "status": true,
    "message": "Compra parcelada cadastrada com sucesso!"
  }
}
```

### Legado (breaking)

Payload antigo com `valor` + `parcelas_total > 1` **sem** `valor_compra`/`parcelas`:
- `valor` = valor **de cada** parcela
- Backend cria N linhas com esse valor (ex.: `valor=100`, `parcelas_total=10` → 10× R$ 100, total R$ 1000)

Com `valor_compra`, o total é dividido em N.

### À vista (exemplo)

```json
{
  "cartao_id": 1,
  "cartao_numero_id": 10,
  "data": "2026-07-31",
  "estabelecimento_id": 104,
  "valor_compra": "125,50",
  "parcelas_total": 1,
  "tipo": "purchase",
  "origem_compra": "COMPRAS_ONLINE"
}
```

Também aceita `valor` no lugar de `valor_compra` quando `parcelas_total` é 1.

## Edit

- Por linha (ajuste fino de valor/parcela/fatura/`cartao_numero_id`).
- `observacoes` e `responsavel_id`: ao editar, sincronizam automaticamente em todas as parcelas do mesmo `compra_grupo_id` (sem precisar de flag). Toda a compra parcelada fica com o mesmo responsável.
- Flag `propagar_grupo: true`: propaga estabelecimento, categoria, subcategoria, `origem_compra`, `eh_assinatura` e `cartao_numero_id` para as irmãs do mesmo `compra_grupo_id` (não propaga valor/fatura/parcela_*).
- Edit de `eh_assinatura` (como observações/responsável) já sincroniza sozinho em todas as parcelas do `compra_grupo_id`.
- Ao definir `categoria_id` numa transação cujo estabelecimento ainda **não** tem `categoria_padrao_id`:
  1. grava categoria/subcategoria como padrão do estabelecimento;
  2. aplica nas demais transações do mesmo estabelecimento com `categoria_id` nulo;
  3. próximas imports/compras sem categoria herdam o padrão.
  Transações já categorizadas (editadas de propósito) não são alteradas. Se o estabelecimento já tem padrão, só a linha editada muda.

## Delete

- Default: exclui só a linha.
- `?excluir_grupo=1` (ou body `excluir_grupo`): soft-delete de todas as parcelas do `compra_grupo_id`.

## Import PDF/CSV/XML

- Resolve estabelecimento pelo nome (cria se necessário).
- Aplica padrões do estabelecimento.
- Sempre define responsável `Eu`.
- `origem_compra` fica `null` (não é possível inferir do PDF).
- Compras parceladas (`parcelas_total > 1`): após gravar a parcela do mês, materializa as parcelas restantes via `TransacaoService::materializarParcelasFuturas`:
  - gera/reusa `compra_grupo_id` na linha-fonte e nas irmãs;
  - materializa parcelas anteriores (`1..parcela_atual-1`) e futuras (`parcela_atual+1..N`);
  - cria/reusa faturas do cartão nas competências correspondentes com `findOrCreateByCartaoPeriodo` (`status=pendente`, **sem** `arquivo_pdf`);
  - se a fatura da competência já existir e a parcela ainda não estiver nela, a transação é incluída;
  - cria uma transação por competência faltante (`importada_pdf=false`, **`compra_manual=false`**, `status_conciliacao=null`), com o mesmo estabelecimento/valor/categoria/responsável;
  - essas parcelas automáticas **não** pedem conciliação (`precisa_conciliar=false`). Só uma compra cadastrada pelo usuário (`compra_manual=true`) fica em evidência;
  - idempotente (não duplica parcela já existente no grupo ou na fatura-alvo).
- Quando a fatura do mês seguinte for processada, a parcela materializada é mesclada (passa a `importada_pdf=true`).
- Match exato (mesmo estabelecimento + valor + parcela) numa **compra manual** que já tenha estabelecimento: preenche `descricao_fatura` e marca `conciliada` **sem** alterar `observacoes`.
- Compra manual **sem estabelecimento**: após o PDF, `sugerirParaFatura` emparelha 1:1 (valor + competência + data próxima + parcela). Marca `pendente`, esconde o lançamento do **total** (`ignorar_no_total`) mas **mantém visível** na fatura para o usuário confirmar.

## Conciliação, anexos e histórico

Prompt: [`frontend-prompt-cadastro-manual-compra.md`](../frontend-prompt-cadastro-manual-compra.md).

- Create manual grava `status_conciliacao = nao_conciliada`, `estabelecimento_id = null`, **`compra_manual = true`** e o texto da descrição em `observacoes` (espelhado em `descricao`). O mesmo vale para o **Registrar esta compra** do Posso comprar.
- `fatura_id` no create define a competência da 1ª parcela (override do ciclo).
- Estabelecimento **não** é criado a partir da descrição. Sem `estabelecimento_id`/`estabelecimento`, permanece `null` até a conciliação.
- Conciliar: compra `ignorar_no_total=true` (some da tela da fatura); o lançamento do PDF permanece, conta no total, recebe `observacoes`/categoria/origem da compra se estiverem vazios, e a listagem devolve `conciliada_com_manual` + `compra_manual_vinculada`.
- Listagem devolve `texto_compra`, `compra_manual`, `precisa_conciliar`, `precisa_conciliar_label`, `tem_sugestao_conciliacao`, `sugestao_conciliacao_label`, `conciliada_com_manual`, `conciliada_com_manual_label`, `compra_manual_vinculada`, `conta_no_total`.
- `POST /conciliar` aceita compra e lançamento em qualquer ordem. `POST /desvincular` aceita o id da compra ou do lançamento.
- Anexos em `compra_anexos` (PDF/imagem, máx. 10MB), ligados à compra — não à fatura.
- Histórico em `compra_historicos` (criação, edição, conciliação, anexos, exclusão).

## Filtros listar

- `data_inicio`, `data_fim`
- `categoria_id`, `subcategoria_id`, `estabelecimento_id`, `responsavel_id`, `cartao_id`, `fatura_id`
- `cartao_numero_id`, `ultimos_digitos`
- `tipo`, `origem_compra`, `eh_assinatura`, `status_conciliacao`, `mes`, `ano`, `palavra_chave`
- `page`, `perPage`

Respostas expõem `estabelecimento` (nome; `null` → UI mostra —), `observacoes`, `texto_compra`, `compra_manual`, `precisa_conciliar`, `precisa_conciliar_label`, `tem_sugestao_conciliacao`, `sugestao_conciliacao_label`, `conciliada_com_manual`, `compra_manual_vinculada`, `conta_no_total`, `categoria_*`, `subcategoria_*`, `responsavel_*`, `origem_compra`, `eh_assinatura`, `compra_grupo_id`, `cartao_numero_id`, `ultimos_digitos`, `cartao_numero_tipo`, `cartao_numero_apelido`, `cartao_numero_nome_no_cartao`, `cartao_bandeira_id`, `cartao_bandeira`.

Com `fatura_id`, a ordenação é: final do cartão asc → data asc (para agrupar na view da fatura).
