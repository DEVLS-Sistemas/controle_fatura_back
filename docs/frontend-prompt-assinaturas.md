# Prompt — Frontend: Assinaturas

Use este prompt no repositório do frontend para criar a tela de **assinaturas** alinhada à API do `controle_fatura_back`.

---

## Objetivo

- Lista **oficial**: só o que o usuário (ou a confirmação) marcou como assinatura
- Bloco **separado**: o que o sistema *achou* que é assinatura, com botão **Confirmar**
- Marcar **na própria compra** (`eh_assinatura`), sem depender só do detector
- Mostrar **quanto gasta por ano** com as assinaturas oficiais

---

## Conceito

Uma assinatura = grupo de compras **à vista** do mesmo estabelecimento (ou loja, se os valores forem parecidos).

| Lista | Campo na API | Como entra |
|-------|--------------|------------|
| **Oficial** | `data.assinaturas` | `eh_assinatura = true` na compra **ou** botão Confirmar nesta tela |
| **Para confirmar** | `data.candidatas` | Detector (valor parecido + intervalo regular). **Não mistura** com a oficial |
| Ignoradas | `data.ignoradas` | Usuário disse que não é (só se pedir `status=ignorada`) |

**Não** usar `data.itens` como lista única misturada.  
`itens` no default (`status=todas`) = só a lista oficial (atalho). Sempre preferir `assinaturas` + `candidatas`.

Confirmar (botão da candidata):

- `POST /assinaturas/cadastrar` `{ "identificador": "estabelecimento-45" }`
- Grava `eh_assinatura = true` e `origem_compra = PAGAMENTO_SERVICOS` nas cobranças à vista do grupo
- Some de `candidatas` e entra em `assinaturas`

Sinalizar **uma compra** (formulário / linha):

- `PUT /transacoes/editar` `{ "id": 123, "eh_assinatura": true }`
- Ou `POST /assinaturas/cadastrar` `{ "transacao_id": 123 }`
- A compra entra na lista oficial mesmo que o detector não tivesse pego (ex.: 1ª cobrança do Spotify)

Parceladas não entram nesta tela.

---

## Menu / rota

**Assinaturas** · `/assinaturas`  
Detalhe: `/assinaturas/{identificador}` (`loja-12` / `estabelecimento-45` — string, sem parseInt)

---

## APIs (Bearer Sanctum)

```http
GET  /api/v1/assinaturas/lookups
GET  /api/v1/assinaturas/listar
GET  /api/v1/assinaturas/listar/{identificador}
POST /api/v1/assinaturas/cadastrar
PUT  /api/v1/assinaturas/editar
DELETE /api/v1/assinaturas/excluir/{identificador}
```

### Listagem

```http
GET /api/v1/assinaturas/listar?ordenar=anual_desc
```

Dois arrays **sempre** no default:

```json
{
  "data": {
    "totais": {
      "assinaturas": 2,
      "confirmadas": 2,
      "candidatas": 3,
      "pendentes_confirmacao": 3,
      "estimativa_mensal": 77.8,
      "estimativa_anual": 933.6,
      "estimativa_anual_candidatas": 670.8
    },
    "assinaturas": [ { "identificador": "estabelecimento-9", "titulo": "Spotify", "status": "confirmada", "pode_confirmar": false } ],
    "candidatas": [ { "identificador": "estabelecimento-45", "titulo": "Netflix", "status": "candidata", "pode_confirmar": true, "acoes_disponiveis": ["confirmar", "ignorar"] } ],
    "itens": []
  }
}
```

`totais.estimativa_anual` = **só a lista oficial**.  
`pendentes_confirmacao` / `estimativa_anual_candidatas` = bloco “para confirmar”.

Cada item tem `pode_confirmar` e `acoes_disponiveis`.

### Confirmar candidata (obrigatório)

```http
POST /api/v1/assinaturas/cadastrar
{ "identificador": "estabelecimento-45" }
```

Equivalente: `PUT /editar` `{ "identificador": "…", "acao": "confirmar" }`.

Depois: refetch. A linha sai de `candidatas` e entra em `assinaturas`.

### Ignorar / restaurar / desfazer

```http
PUT /editar
{ "identificador": "estabelecimento-45", "acao": "ignorar" }
```

| `acao` | Efeito |
|--------|--------|
| `confirmar` | vai para a lista oficial |
| `ignorar` | some das candidatas (`DELETE /excluir/{id}` igual) |
| `restaurar` | volta a aparecer |
| `desfazer_confirmacao` | tira `eh_assinatura` (sai da oficial; pode voltar a candidata) |

---

## UX da tela (obrigatório)

Layout em **duas seções**, nesta ordem:

### 1) Hero — gasto oficial

De `data.totais` (lista oficial):

1. **Por ano** — `estimativa_anual`
2. **Por mês** — `estimativa_mensal`
3. Quantidade — `assinaturas`

Se `pendentes_confirmacao > 0`: chip **“{n} para confirmar”** que faz scroll até a seção 2.

Não somar candidatas no número grande do ano.

### 2) Para confirmar (separada da oficial)

Título: **Possíveis assinaturas** / **Para confirmar**  
Subtítulo: “O sistema encontrou cobranças recorrentes. Confirme para entrar na lista de assinaturas.”

Só renderiza se `candidatas.length > 0`.

Card da candidata:

- Título, periodicidade, `R$ {estimativa_anual}/ano`, última cobrança
- Botão primário **Confirmar** (`pode_confirmar`)
- Secundário **Não é assinatura** (ignorar)
- Clique no card → detalhe (não confirma)

Empty desta seção: não mostrar o bloco (não repetir empty da oficial).

Visual: fundo/borda diferente da lista oficial (ex. faixa “sugestão”), para não parecer já confirmado.

### 3) Lista oficial

Título: **Minhas assinaturas**

Cards **sem** botão Confirmar (`pode_confirmar: false`). Mostrar valor anual, periodicidade, próxima estimada. Menu: desfazer.

Empty: “Nenhuma assinatura confirmada. Marque na compra ou confirme uma sugestão acima.”

### 4) Detalhe

`GET /listar/{identificador}` + `cobrancas_recentes[]`.  
Se `pode_confirmar`, o botão Confirmar também no detalhe.  
Linha da cobrança: badge se `eh_assinatura`. Clique → visualização da compra.

---

## Sinalizar na tela de Compra (obrigatório)

Campo boolean `eh_assinatura` (independente de `origem_compra`).

- Switch/checkbox **“É assinatura”** no create e no edit
- Na **listagem** de transações: ícone/badge “Assinatura” quando `true`
- Na linha/menu: atalho para ligar/desligar → `PUT /transacoes/editar` `{ id, eh_assinatura }`
- Se `origem_compra = PAGAMENTO_SERVICOS` e o usuário **não** enviou o flag, o backend assume `true` no **create**. No front: pré-marcar o switch ao escolher pagamento de serviços (usuário pode desmarcar)

Payload create:

```json
{
  "origem_compra": "COMPRAS_ONLINE",
  "eh_assinatura": true
}
```

Detalhe da compra (`GET /transacoes/visualizar/{id}`) traz `eh_assinatura`.

Filtro opcional da listagem: `eh_assinatura=true`.

---

## Checklist

- [ ] Duas seções: **Para confirmar** (candidatas + botão Confirmar) **acima** de **Minhas assinaturas** (oficial)
- [ ] Candidata **não** aparece na lista oficial até confirmar
- [ ] Hero anual só com oficiais
- [ ] Confirmar: `POST /cadastrar` com `identificador`
- [ ] Compra: switch `eh_assinatura` no form + badge na listagem
- [ ] Identificador string na URL
- [ ] Empty / loading / erro / responsivo

---

## Fora de escopo

- Cadastrar cobrança futura que ainda não caiu
- Cancelar Netflix/Spotify no provedor

---

Spec: [`docs/modules/assinaturas.md`](modules/assinaturas.md)  
Compras: [`frontend-prompt-compras.md`](frontend-prompt-compras.md)
