# Especificação — Cores tema (categoria, subcategoria e cartão)

Prompt correspondente do front (mesmas etapas): [`../frontend-prompt-cores-tema.md`](../frontend-prompt-cores-tema.md).

Implementar **uma etapa por vez**, na ordem. O front usa o mesmo número de etapa.

Cores de categoria, subcategoria e cartão aparecem em cadastros, chips, listagens e **todos** os gráficos. Mudar tudo de uma vez quebra telas já estáveis. Cada etapa entrega valor sozinha e deixa o contrato anterior válido até a próxima.

| Etapa | Tema | Back hoje | Trabalho desta spec |
|-------|------|-----------|---------------------|
| **1** | Cor tema da categoria + default preto + gráficos de categoria | **Feito** — `CategoriaCoresTema`, lookups `temas`/`cor_padrao`, create/edit coalescem, gráficos devolvem HEX | Paleta tema, default `#000000`, lookups estruturados, gráficos **nunca** inventam paleta |
| **2** | Variações claras nas subcategorias | **Feito** — `categoria_subcategoria.cor`, `CategoriaCorVariacao`, regen no edit da categoria, `cor` nas pizzas | `cor` no pivot N:N; gerador de tons mais claros que o tema; APIs devolvem `cor` da sub |
| **3** | Alinhar o restante do sistema | **Feito** — backfill `categorias:backfill-cores`, coalesce em transações, compras, gastos críticos, assinaturas, estabelecimentos, responsável | Backfill, coalesce, chips e rankings usam a mesma regra |
| **4** | Cor personalizada no cartão *(tarefa separada)* | Presets oficiais de banco (`pares_cores`) | **Não remover** os presets. Chip “Cor personalizada” abre o seletor HEX |

A etapa 4 **não** começa antes da 1 (os gráficos de categoria precisam estar certos). Pode rodar em paralelo à 2/3 se o time quiser.

---

## Regras de produto (todas as etapas)

1. Cor de **categoria** nos gráficos = cor **salva no cadastro**. Sem paleta gerada no front.
2. Categoria **sem cor escolhida** → **preto** `#000000` (grava e devolve; não deixar `null` no contrato novo).
3. Bucket sintético **“Sem categoria”** (`categoria_id: null`) **não é cadastro** → cinza `#9ca3af` (não misturar com o preto tema).
4. Fatia **“Outros”** das pizzas → cinza `#d1d5db`. Não clicável. Continua regra das pizzas.
5. Cor de **subcategoria** (etapa 2+) = variação **mais clara** que a cor tema da **categoria daquele vínculo**. Relação é N:N: a mesma sub pode ter um azul claro em Alimentação e um verde claro em Mercado.
6. Cartão continua com o esquema de bancos. Cor livre só na etapa 4, atrás de “Cor personalizada”.

HEX sempre minúsculo, 6 dígitos (`#3b82f6`). Aceitar `#abc` na entrada e expandir.

---

## Etapa 1 — Cor tema da categoria

**Back: implementado.** `App\Services\Categoria\CategoriaCoresTema` · lookups em `GET /categorias/lookups` · create/edit/cadastro rápido · coalesce em gastos por categoria e `dashboard/resumo`.

### Objetivo

1. Cadastro de categoria escolhe uma **cor tema** em quadrados pequenos (não lista de HEX soltos).
2. Hover no quadrado mostra o hexadecimal.
3. Sem seleção → preto.
4. Pizza / barras / bolinhas de **categoria** usam `cor` da API. Fallback de categoria cadastrada: `#000000`.

### Lookups

```http
GET /api/v1/categorias/lookups
```

Hoje: `{ "cores": ["#ef4444", ...] }`.

Passa a:

```json
{
  "cor_padrao": "#000000",
  "cores": [
    "#000000", "#ef4444", "#f59e0b", "#22c55e",
    "#3b82f6", "#8b5cf6", "#ec4899", "#6b7280", "#14b8a6"
  ],
  "temas": [
    {
      "chave": "preto",
      "label": "Preto",
      "hex": "#000000",
      "padrao": true,
      "variacoes": []
    },
    {
      "chave": "azul",
      "label": "Azul",
      "hex": "#3b82f6",
      "padrao": false,
      "variacoes": []
    }
  ]
}
```

- `cores[]` permanece (compatível com o front atual).
- `temas[]` é a fonte dos quadrados. **Preto primeiro.**
- `variacoes` na etapa 1 pode ir `[]`; a etapa 2 preenche (tons de preview).
- Classe: `App\Services\Categoria\CategoriaCoresTema` (mesmo espírito de `CartaoCoresPreset`).

Paleta tema (chave → HEX):

| chave | label | hex |
|-------|-------|-----|
| preto | Preto | `#000000` |
| vermelho | Vermelho | `#ef4444` |
| laranja | Laranja | `#f59e0b` |
| verde | Verde | `#22c55e` |
| azul | Azul | `#3b82f6` |
| roxo | Roxo | `#8b5cf6` |
| rosa | Rosa | `#ec4899` |
| cinza | Cinza | `#6b7280` |
| teal | Teal | `#14b8a6` |

Não voltar à paleta genérica inventada no gráfico. O usuário escolhe um desses temas (etapa 1 não exige color picker livre na categoria).

### Create / edit / cadastro rápido

- `cor` opcional. Vazio / omitido / inválido → gravar `#000000`.
- Validar HEX (`#RGB` ou `#RRGGBB`). 422 se vier lixo (`"azul"`).
- Cadastro rápido: se a categoria já existe **sem** cor, preencher com a enviada; se existir com cor, **não** sobrescrever.

Helper único: `CategoriaCoresTema::normalizar(?string $hex): string` → sempre um HEX válido, default preto.

### Gráficos e listagens (somente categoria)

Onde já existe `cat.cor` / `categoria_cor`, aplicar coalesce na **resposta**:

```
categoria cadastrada: CategoriaCoresTema::normalizar($cor)
Sem categoria:        #9ca3af
```

Pontos desta etapa (não precisa mexer em sub ainda):

| Superfície | Campo | Ação |
|------------|-------|------|
| `GET /dashboard/gastos-por-categoria` | `categorias[].cor`, `dashboards.categorias[].cor`, `categoria_cor` da pai | coalesce |
| `GET /dashboard/resumo` | `por_categoria[].cor` | coalesce |
| `GET /categorias/listar`, `listar/{id}`, `categorias-list` | `cor` | coalesce |

Front da pizza mestre: `background = item.cor`. **Proibido** Chart.js `backgroundColor` default, paleta rainbow ou cinza para categoria **cadastrada**.

### Fora desta etapa

- Gerar / gravar cor de subcategoria
- Color picker livre na categoria
- Cor personalizada no cartão
- Recolorir cartões

### Testes

- Create sem `cor` → `#000000`
- Create `cor: "#3B82F6"` → `#3b82f6`
- Create `cor: "foo"` → 422
- Lookups: preto primeiro, `cor_padrao`, `temas[].hex` casa com `cores[]`
- Gastos por categoria: categoria sem cor no banco devolve `#000000`; bucket sem FK devolve `#9ca3af`

---

## Etapa 2 — Variações de subcategoria

**Back: implementado.** `App\Services\Categoria\CategoriaCorVariacao` · migration `categoria_subcategoria.cor` · regen no edit da categoria · `cor` / `cor_vinculo` nas APIs de sub e na pizza escrava.

### Objetivo

Ao escolher (ou mudar) a cor tema da categoria, **todas** as subcategorias **vinculadas a ela** recebem tons derivados desse tema. Todos os tons são **mais claros** que a cor tema. Gráficos de subcategoria usam essa `cor`, não a cor da pai copiada.

### Modelo

A relação categoria ↔ subcategoria é **N:N**. A cor da sub **não** vive em `subcategorias` (uma sub em duas categorias teria uma cor só). Vive no pivot.

```
categoria_subcategoria
  categoria_id
  subcategoria_id
  cor          string(20) nullable  ← HEX da variação neste vínculo
```

Migration: `add_cor_to_categoria_subcategoria_table`.

Model `Categoria::subcategorias()` / `Subcategoria::categorias()`: `->withPivot('cor')`.

### Gerador

Classe: `App\Services\Categoria\CategoriaCorVariacao`.

```php
CategoriaCorVariacao::variacoes(string $tema, int $quantidade): array // HEX[], length = $quantidade
CategoriaCorVariacao::proxima(string $tema, array $coresJaUsadas): string
```

Regras:

1. Converter tema → HSL.
2. A cor tema é a **mais escura** da família.
3. Cada variação aumenta só a luminosidade: `L_i` ∈ `(L_tema, L_max]`, `L_max = 0.88` (nunca branco puro `#ffffff` — some no gráfico).
4. Distribuir `$quantidade` degraus uniformes nesse intervalo.
5. Manter o matiz. Saturação pode cair no máximo ~15% nos tons mais claros para não “queimar”.
6. Se o tema **já é claro** (`L >= 0.72`, ex. âmbar): o intervalo até 0.88 é curto — alternar matiz ±6° e/ou baixar saturação para as fatias continuarem distinguíveis. **Ainda assim** cada variação tem luminância relativa **maior** que a do tema.
7. Tema preto → cinzas claros (`#333333`, `#555555`, …), nunca azul inventado.
8. Saída minúscula `#rrggbb`. Garantir unicidade na lista gerada.

Exemplo: tema azul `#3b82f6` → `#60a5fa`, `#93c5fd`, `#bfdbfe`, `#dbeafe`, `#eff6ff` (valores ilustrativos; o teste trava a função, não este exemplo).

Lookups da etapa 2: cada `temas[]` traz `variacoes` com **5** tons de preview (quantidade fixa de exemplo). O front **não** implementa HSL.

### Quando atribuir

| Evento | Ação |
|--------|------|
| Create categoria com tema | nada (ainda não há subs) |
| Edit `cor` da categoria | **regerar** `categoria_subcategoria.cor` de **todos** os vínculos dessa categoria, estável por `subcategoria_id` ASC |
| Create / cadastro rápido / sync de sub com `categoria_ids` | para cada vínculo novo, `proxima(tema, cores já usadas naquela categoria)` |
| Sub já vinculada, outra categoria adicionada | só o **novo** pivot recebe cor; o vínculo antigo não muda |
| Desvincular | apaga o pivot (e a cor) |
| Tema da categoria inalterado no edit | **não** reshuffle (cores das subs ficam) |

Estabilidade: a 1ª sub (menor id) sempre pega o 1º degrau, a 2ª o 2º, etc. Recalcular no change de tema usa essa ordem — **não** a ordem de gasto nem a ordem da pizza.

### Contrato das APIs

Todo item de subcategoria que já carrega pai passa a ter `cor` **própria**:

```json
{
  "subcategoria_id": 10,
  "nome": "Delivery",
  "cor": "#93c5fd",
  "categoria_id": 2,
  "categoria_nome": "Alimentação",
  "categoria_cor": "#3b82f6"
}
```

| Campo | Quem pinta |
|-------|------------|
| `cor` | fatia / bolinha da **subcategoria** |
| `categoria_cor` | contexto da pai (chip “de Alimentação”, destaque mestre) |

Se o pivot ainda estiver `null` (legado antes do backfill da etapa 3): o service calcula `proxima` na leitura **e** pode persistir (lazy). Preferível backfill na etapa 3; na 2, calcular na escrita e na leitura com fallback `CategoriaCorVariacao` para não devolver `null`.

Pontos:

- `GET /dashboard/gastos-por-categoria` — `subcategorias[]` (plano e aninhado), `dashboards.subcategorias[]`, `top_subcategorias[]`: incluir `cor`
- `GET /subcategorias/listar`, `listar/{id}`, `subcategorias-list`: cada categoria do vínculo traz `{ id, nome, cor: tema, cor_vinculo: variação }`
- `GET /subcategorias/lookups` — `categorias[]` já tem `cor` da pai
- Query de gastos: join `categoria_subcategoria` por `(categoria_id, subcategoria_id)` da transação para pegar `cs.cor`

`subcategorias-list?categoria_id=` deve devolver a `cor` **daquele** vínculo (o select da compra precisa da bolinha certa).

### Detalhe da categoria

`GET /categorias/listar/{id}` inclui as subs com a cor do vínculo:

```json
{
  "id": 2,
  "nome": "Alimentação",
  "cor": "#3b82f6",
  "subcategorias": [
    { "id": 10, "nome": "Delivery", "cor": "#93c5fd" }
  ]
}
```

O formulário de subcategoria **não** ganha seletor. Cor é consequência do tema da pai. UI só mostra o quadrado (read-only).

### Testes

- Tema azul + 3 subs → 3 HEX distintos, todos mais claros que `#3b82f6` (comparar luminância relativa)
- Mudar tema vermelho → as 3 cores mudam para família vermelha e continuam mais claras que o novo tema
- Mesma sub em cat A (azul) e cat B (verde) → dois HEX, um por pivot
- Gastos por categoria: `dashboards.subcategorias[0].cor !== categoria_cor` quando há ≥ 1 sub (salvo tema preto com 1 degrau extremo — ainda assim L maior)
- Sem `cor` no pivot: resposta ainda traz HEX (fallback), nunca `null` para sub nomeada

### Fora desta etapa

- Seletor de cor na subcategoria
- Cor personalizada no cartão
- Backfill em massa de categorias antigas (etapa 3)

---

## Etapa 3 — Alinhar o restante do sistema

**Back: implementado.** Command `php artisan categorias:backfill-cores` · `CategoriaCorVariacao::backfill` / `planoBackfillVinculos` · coalesce em `GastosCriticosService`, `TransacaoService`, `CompraVisualizacaoService`, `AssinaturaService` / `AssinaturaDetectorService`, `EstabelecimentoService`, `ResponsavelVisualizacaoService`.

### Objetivo

Nenhuma tela usa cinza/`null`/paleta Chart.js para categoria **cadastrada**. Subcategoria nomeada sempre tem `cor` no JSON.

### Backfill

Command `php artisan categorias:backfill-cores {--dry-run} {--user=}` (ou `up()` da migration da etapa 2, se ainda não rodou em prod):

1. `categorias.cor` null ou `''` → `#000000`
2. Para cada categoria, gerar variações e gravar em `categoria_subcategoria.cor` (ordem `subcategoria_id`)

Idempotente: não sobrescreve HEX já válido na categoria nem no pivot.

### Superfícies a auditar (back + contrato)

| Módulo | Campos | Esperado |
|--------|--------|----------|
| Gastos críticos | `categoria_cor` nas listas; incluir `cor` da sub se o item for sub | tema / variação |
| Compras / transações | `categoria_cor` na linha | tema (preto se vazio) |
| Visualização da compra | chip categoria | idem |
| Assinaturas | `categoria_cor` | idem |
| Estabelecimentos | `categoria_padrao_cor` | idem |
| Responsável visualizar | agregados por categoria | idem |
| Cadastro rápido categoria | omitir `cor` → preto | etapa 1 já faz; conferir o modal |
| `subcategorias-list` | `cor` do vínculo | etapa 2 |

Onde a query já faz `cat.cor as categoria_cor`, aplicar `CategoriaCoresTema::normalizar` no map da resposta (não só no SQL). Bucket sem categoria: `#9ca3af`.

### Front (esta etapa)

Varredura: qualquer bolinha/chip/fatia de categoria ou sub usa o HEX da API. Sem hardcoded `hsl(...)` por índice.

### Testes

- Feature/unit: backfill não zera cor já preenchida
- Um teste por service auditado: fixture com `cor = null` no banco → JSON com `#000000`

---

## Etapa 4 — Cor personalizada no cartão *(tarefa separada)*

### Objetivo

O cadastro de **cartão** já tem swatches oficiais (Nubank, Inter, C6, …). **Manter**. Acrescentar um chip **“Cor personalizada”** que, ao clicar, mostra o **mesmo tipo de seletor** do cadastro de categoria antigo: escolher qualquer cor, hover devolve o HEX.

Não é para a categoria. Não substitui `pares_cores`.

### Contrato (já existe)

`POST/PUT /cartoes` já aceita `cor_fundo` / `cor_texto` em HEX livre (`CartaoService::normalizeCor`). Não precisa de coluna nova.

Lookups — acrescentar flag de UI, sem quebrar o array:

```json
{
  "cor_personalizada": {
    "chave": "personalizada",
    "label": "Cor personalizada",
    "cor_fundo": null,
    "cor_texto": null
  }
}
```

Opcional. O front pode só colocar o chip extra sem esse campo.

### Comportamento

1. Grade atual (`pares_cores`) intacta, inclusive auto-apply por nome/banco.
2. Último item (ou separado): **Cor personalizada**.
3. Clique → abre `<input type="color">` (ou o componente já usado em categoria) + tooltip/hover com HEX.
4. `cor_fundo` = HEX escolhido. `cor_texto` = branco ou preto pelo contraste (luminância relativa ≥ 0.179 → texto `#111827`, senão `#ffffff`). Usuário não é obrigado a ter picker de texto; se `cores_texto[]` ainda existir, pode oferecer override.
5. Marca `coresManuais = true` (já documentado em [`frontend-prompt-cores-cartoes.md`](../frontend-prompt-cores-cartoes.md)) para o auto-apply do banco **parar**.
6. Preview do chip do cartão usa o par.

### Fora desta etapa

- Recolorir cartões já cadastrados
- Cor por bandeira/número (continua no grupo)
- Remover presets
- Color picker livre na **categoria** (categoria = só temas)

### Testes

- PUT com `cor_fundo: "#1a2b3c"` persiste (já deve passar)
- Lookup ainda devolve Nubank/Inter/Padrão
- Não regressar o auto-apply quando o usuário **não** abriu personalizada

Prompt detalhado do front desta etapa: seção Etapa 4 em [`../frontend-prompt-cores-tema.md`](../frontend-prompt-cores-tema.md). O prompt antigo de presets **permanece** válido: [`../frontend-prompt-cores-cartoes.md`](../frontend-prompt-cores-cartoes.md).

---

## Ordem de merge sugerida

```
Etapa 1  →  gráficos de categoria certos, cadastro com quadrados
Etapa 2  →  pizza escrava deixa de repetir a cor da pai
Etapa 3  →  o resto do app para de destoar
Etapa 4  →  cartão ganha HEX livre (PR separado)
```

Não misturar a etapa 4 no mesmo PR das categorias.

---

## Arquivos previstos (back)

| Etapa | Arquivos |
|-------|----------|
| 1 | `app/Services/Categoria/CategoriaCoresTema.php`, `CategoriaService` (lookups + normalize), charts que devolvem `cor`, testes |
| 2 | migration pivot, `CategoriaCorVariacao.php`, `CategoriaService` (regen no edit), `SubcategoriaService` (assign no vínculo), `GastosPorCategoriaService` (join + `cor`), models, testes |
| 3 | command/backfill, maps em GastosCriticos, Transacao, Assinatura, Estabelecimento, ResponsavelVisualizacao, DashboardService, testes |
| 4 | `CartaoService::handleLookups` (opcional `cor_personalizada`), testes de persistência HEX livre se ainda não cobrirem o picker |

Docs a atualizar junto de cada etapa: este arquivo (status), [`categorias.md`](categorias.md), [`subcategorias.md`](subcategorias.md), [`gastos-por-categoria.md`](gastos-por-categoria.md), [`cartoes.md`](cartoes.md) só na 4.
