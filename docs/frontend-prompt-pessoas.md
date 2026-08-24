# Prompt — Frontend: Pessoas (titulares) + confirmação no import de fatura

Use este prompt no repositório do **frontend** para alinhar a UI à API do `controle_fatura_back`.

Spec do back: [`modules/pessoas.md`](modules/pessoas.md).  
Complementa: [`frontend-prompt-auth.md`](frontend-prompt-auth.md), [`frontend-prompt-perfil.md`](frontend-prompt-perfil.md), [`frontend-prompt-cadastro-fatura-metadados.md`](frontend-prompt-cadastro-fatura-metadados.md), [`frontend-prompt-faturas.md`](frontend-prompt-faturas.md).

---

## Objetivo

1. Cadastrar **pessoas** (titulares) dentro da conta — ex.: Leonardo (eu) e Maysa (cônjuge/adicional).
2. Vincular **cartão** e **fatura** a uma pessoa.
3. Ao importar PDF cujo nome **não bate** com as pessoas da conta, **avisar e pedir confirmação** — nunca bloquear para sempre e nunca exigir que o nome do login seja igual ao da fatura.
4. Filtrar a listagem de faturas por pessoa (filtro de verdade, não “sessão” mágica por string).

---

## Conceitos (não misturar)

| Conceito | O que é | Onde |
|----------|---------|------|
| **Login / perfil** | Quem entra no app | Auth + Perfil |
| **Pessoa** | Titular da fatura/cartão | Menu **Pessoas** + `pessoa_id` |
| **Responsável** | Quem deve a compra | já existe em Responsáveis |

Exemplo: fatura do cartão da **Maysa** (pessoa) com compra marcada para o responsável **Eu** (Leonardo). São coisas diferentes.

### Não implementar

- Bloquear PDF se o nome ≠ nome do cadastro
- Abas/sessões só pelo texto do nome sem cadastrar pessoa
- Níveis, admin, username
- Trocar o fluxo de responsável

---

## API — Pessoas

Base: `/api/v1/pessoas` (Bearer)

CRUD padrão:

| Método | Rota | Uso |
|--------|------|-----|
| GET | `/lookups` | Notas conceituais |
| GET | `/listar` | Paginação |
| GET | `/listar/{id}` | Detalhe |
| POST | `/cadastrar` | Criar |
| PUT | `/editar` | Editar |
| DELETE | `/excluir/{id}` | Soft delete |
| GET | `/pessoas-list` | Async select |

### Payload cadastrar / editar

```json
{
  "id": 2,
  "nome": "Maysa",
  "sobrenome": "Araujo da Conceicao",
  "cpf_cnpj": "12345678901",
  "ativo": true
}
```

| Campo | Obrigatório | Obs |
|-------|-------------|-----|
| `nome` | Sim | |
| `sobrenome` | Não | |
| `cpf_cnpj` | Não | máscara no front; back guarda dígitos |
| `ativo` | Não | default true; não desativa a principal via regra de produto |

Resposta envelope:

```json
{
  "pessoa": {
    "data": {
      "id": 2,
      "nome": "Maysa",
      "sobrenome": "Araujo da Conceicao",
      "nome_completo": "Maysa Araujo da Conceicao",
      "cpf_cnpj": "12345678901",
      "eh_principal": false,
      "ativo": true
    },
    "status": true,
    "message": "Pessoa cadastrada com sucesso!"
  }
}
```

### Regras de UI — Pessoas

- Menu: **Pessoas** (cadastro simples, lista + form).
- A pessoa com `eh_principal: true` é a do login (criada no cadastro / sincronizada pelo Perfil). **Não** oferecer excluir. Badge “Principal” / “Você”.
- Excluir outras: se 422 com cartão/fatura vinculada, mostrar a mensagem e orientar a reatribuir.
- Async select: `GET /pessoas-list?palavra_chave=` → usar `id` + `nome_completo`.

### Auth / Perfil

Login, cadastro, `GET /me` e `PUT /perfil` devolvem:

```json
{
  "id": 1,
  "name": "Leonardo",
  "sobrenome": "Silva",
  "cpf_cnpj": null,
  "email": "leo@email.com",
  "pessoa_id": 1
}
```

`pessoa_id` = pessoa principal. Editar o Perfil atualiza essa pessoa automaticamente no back.

---

## Cartões — vincular pessoa

No cadastro/edição de cartão, campo opcional:

| Campo | Tipo |
|-------|------|
| `pessoa_id` | select de pessoas (async ou lista) |

Payload do cartão passa a trazer `pessoa_id` e `pessoa_nome`.

Se vazio, o cartão fica sem titular explícito até o import de fatura (ou o usuário escolher depois).

---

## Faturas — filtro por pessoa

`GET /api/v1/faturas/listar?pessoa_id={id}`

Cada grupo de cartão e cada fatura podem trazer:

```json
{
  "pessoa_id": 2,
  "pessoa_nome": "Maysa Araujo da Conceicao"
}
```

UI sugerida na listagem de faturas:

- Filtro **Pessoa** (select: Todas | Principal | demais).
- Chip/label com o nome da pessoa no card do cartão ou na fatura (quando houver).
- Isso **não** é aba por string de nome: só filtra o que tem `pessoa_id`.

---

## Modal — confirmar titular (import PDF/CSV)

Encaixa no fluxo de cadastro de fatura **depois** de senha e metadados.

Ordem dos 422:

```
1. precisa_senha_pdf
2. precisa_confirmar_metadados
3. precisa_confirmar_titular   ← NOVO
4. precisa_selecionar_bandeira / precisa_selecionar_final
5. 200 sucesso
```

### Quando dispara

```json
{
  "error": true,
  "message": "Esta fatura parece estar em nome de outra pessoa. Confirme a quem pertence.",
  "codigo": "precisa_confirmar_titular",
  "precisa_confirmar_titular": true,
  "orientacao": "O nome no PDF não corresponde às pessoas cadastradas nesta conta. Vincule a uma pessoa existente, cadastre uma nova (ex.: cônjuge/adicional) ou confirme que quer importar mesmo assim.",
  "titulares_detectados": ["MAYSA ARAUJO DA CONCEICAO"],
  "titulares_desconhecidos": ["MAYSA ARAUJO DA CONCEICAO"],
  "perfil_nome": "Leonardo da Silva Ferreira",
  "pessoa_sugerida_id": null,
  "pode_cadastrar_pessoa": true,
  "pessoas": [
    { "value": 1, "label": "Leonardo da Silva Ferreira", "eh_principal": true }
  ]
}
```

O match do back é **frouxo** (não exige igualdade). Se o PDF for `LEONARDO S FERREIRA` e a pessoa principal for `Leonardo da Silva Ferreira`, **não** abre este modal.

### UI do modal

Título: **Confirmar titular da fatura**

Texto:

> Detectamos o nome **MAYSA ARAUJO DA CONCEICAO** no arquivo.  
> Seu cadastro é **Leonardo da Silva Ferreira**.  
> A quem pertence esta fatura?

Ações (escolher uma):

1. **Selecionar pessoa existente** — select com `pessoas[]` → retry com `pessoa_id`.
2. **Cadastrar nova pessoa** — campos nome (pré-preenchido com o titular detectado) + sobrenome opcional + CPF opcional → retry com:
   - `cadastrar_pessoa=true`
   - `pessoa_nome` = nome completo detectado **ou** `pessoa_nome` + `pessoa_sobrenome`
3. **Importar mesmo assim** — retry com `confirmar_titular=true` (sem criar pessoa; usa pessoa do cartão se já houver).
4. **Cancelar** — fecha e não reenvia.

Botão primário sugerido: **Confirmar e continuar** (após escolher 1, 2 ou 3).

### Retry (multipart, mesmo arquivo)

Reenviar o `POST /api/v1/faturas/cadastrar` com os campos já confirmados (cartão, mês, ano, senha…) **mais**:

**Opção A — pessoa existente**

```
pessoa_id=2
```

**Opção B — cadastrar pessoa**

```
cadastrar_pessoa=true
pessoa_nome=MAYSA ARAUJO DA CONCEICAO
```

ou

```
cadastrar_pessoa=true
pessoa_nome=Maysa
pessoa_sobrenome=Araujo da Conceicao
pessoa_cpf_cnpj=...
```

**Opção C — seguir sem nova pessoa**

```
confirmar_titular=true
```

Efeito no back: grava `faturas.pessoa_id` e, se aplicável, `cartoes.pessoa_id`.

**Se a pessoa não for a principal:** o back também **cria/reutiliza um responsável** com o nome completo dela e define como padrão da fatura (`faturas.responsavel_id`). As compras importadas do PDF já saem com esse responsável (não com `Eu`).

---

## Responsável automático (obrigatório entender)

| Situação | Responsável padrão da fatura / import |
|----------|--------------------------------------|
| Titular = pessoa principal (você) | `Eu` (já existia) |
| Titular = outra pessoa (ex. Maysa) | Responsável **criado automaticamente** com o nome dela |

### O que o front deve fazer

1. **Não** pedir para o usuário cadastrar o responsável na hora do import do outro titular — o back faz isso.
2. Após sucesso do cadastro da fatura / processamento:
   - Invalidar cache de `/responsaveis` e `/responsaveis-list` (aparece o novo nome).
   - Nas compras da fatura, o select de responsável já vem com a Maysa (ou o nome dela).
3. Na listagem/detalhe da fatura, exibir se vier:
   - `responsavel_id` / `responsavel_nome` = padrão da fatura
   - Continua distinto de `pessoa_id` / `pessoa_nome` (titular do cartão)
4. Tela **Responsáveis**: o novo registro aparece como `tipo: pessoal`, editável/desativável como os demais (exceto não apagar se ainda houver compras — regra já existente se houver). Clique no responsável abre o hub de [visualizar responsável](frontend-prompt-visualizacao-responsavel.md) (totais, em aberto, fatura).
5. Toast opcional após confirmar titular novo: “Responsável *Maysa Araujo* criado e aplicado nesta fatura.”

### Payload pessoa (após criar)

```json
{
  "id": 2,
  "nome": "Maysa",
  "sobrenome": "Araujo da Conceicao",
  "nome_completo": "Maysa Araujo da Conceicao",
  "responsavel_id": 15,
  "eh_principal": false,
  "ativo": true
}
```

### Não confundir

- **Pessoa** = dono do cartão/fatura  
- **Responsável** = quem deve a compra (repasses, filtros, projeção Eu × outros)

Para a fatura da Maysa, o padrão é ela mesma como responsável; o usuário ainda pode mudar compra a compra para `Eu` / `Empresa` se quiser.

---

## Metadados (modal anterior)

Em `precisa_confirmar_metadados`, `sugestao` pode trazer `titulares: ["LEONARDO S FERREIRA"]` (informativo). Não precisa abrir o modal de titular ainda — o gate de titular vem depois, quando cartão/mês/ano já estão definidos.

Opcional no modal de metadados: select de `pessoa_id` antecipado (se o front quiser); se enviar `pessoa_id` no retry de metadados, o modal de titular tende a não aparecer.

---

## Modal — cartão do titular (não sobrescrever)

Se já existe fatura **com PDF** no mesmo cartão/mês e o novo arquivo é de **outra pessoa**:

```json
{
  "error": true,
  "codigo": "precisa_cartao_do_titular",
  "precisa_cartao_do_titular": true,
  "pode_cadastrar_cartao": true,
  "fatura_existente_id": 591,
  "cartao_existente_id": 36,
  "pessoa_existente_nome": "Leonardo Silva",
  "titulares_detectados": ["MAYSA ARAUJO DA CONCEIÇÃO", "Maysa"],
  "orientacao": "Já existe fatura deste mês neste cartão (Leonardo Silva). Faturas de pessoas diferentes precisam de cartões separados — cadastre o cartão desta pessoa nesta tela para as duas coexistirem.",
  "sugestao": {
    "cartao_id": null,
    "cartao_nome_sugerido": "Nubank",
    "mes": 8,
    "ano": 2026,
    "pessoa_id": 2,
    "bandeira_sugerida": "Mastercard"
  },
  "pessoas": [],
  "bandeiras": []
}
```

### Por quê

Uma fatura por **cartão (bandeira) + mês**. Leonardo e Maysa no mesmo mês = **dois cartões** (ex.: Nubank do Leonardo + Nubank da Maysa), não dois PDFs no mesmo cartão.

### UI

Igual ao modo `cadastrar_cartao` do modal de metadados:

1. Explicar que a fatura do mês **já existe** naquele cartão e não será substituída.
2. Pré-preencher nome do cartão (`cartao_nome_sugerido`) e mês/ano.
3. Botão **Cadastrar cartão e fatura** → retry **sem** `cartao_id`, com `cadastrar_cartao=true`, `cartao_nome`, `bandeira`, `mes`, `ano`, e `pessoa_id` se já escolhida.
4. Não oferecer “anexar em cima” da fatura existente.

### Ordem dos 422 (atualizada)

```
1. precisa_senha_pdf
2. precisa_confirmar_metadados
3. precisa_confirmar_titular
4. precisa_cartao_do_titular   ← quando o período já tem fatura de outra pessoa no mesmo cartão
5. precisa_selecionar_bandeira / precisa_selecionar_final
6. 200 sucesso
```

---

## Checklist front

- [ ] Menu **Pessoas** com CRUD simples (nome, sobrenome, CPF/CNPJ)
- [ ] Pessoa principal visível, sem exclusão
- [ ] Cartão: select opcional `pessoa_id`
- [ ] Faturas: filtro `pessoa_id` + label `pessoa_nome`
- [ ] Fluxo de import: tratar `codigo === "precisa_confirmar_titular"`
- [ ] Fluxo de import: tratar `codigo === "precisa_cartao_do_titular"` (cadastrar outro cartão; **não** sobrescrever)
- [ ] Modal titular com 3 saídas (vincular / cadastrar / importar mesmo assim) + cancelar
- [ ] Retry multipart preservando arquivo + campos já confirmados
- [ ] Não exigir igualdade de nome com o perfil
- [ ] Não criar “sessões” por string; só filtro por pessoa cadastrada
- [ ] Após import de outro titular: invalidar lista de responsáveis
- [ ] Exibir `responsavel_nome` da fatura quando vier na API
- [ ] Não exigir cadastro manual de responsável no modal de titular
- [ ] Manter Responsáveis separado (compras) — mas ciente de que outro titular gera responsável automático

---

## Rotas de UI sugeridas

| Rota | Auth |
|------|------|
| `/pessoas` | privada — lista |
| `/pessoas/novo` ou modal | privada |
| `/pessoas/:id` | privada — editar |
| `/faturas` | filtro Pessoa no topo |
| `/cartoes` | campo Pessoa no form |

---

## Evolução futura (não fazer agora)

- Convidar a Maysa para ter login próprio na mesma conta
- Totais do dashboard por pessoa
- Conta de contador com vários “espaços”

O modelo atual (`pessoas` dentro do `user`) já prepara isso sem retrabalho.
