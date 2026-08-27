# Controle de Faturas — Backend API

API REST Laravel para gestão de faturas de cartão de crédito, com upload/parsing de PDF, categorias, responsáveis e dashboard.

## Stack

- Laravel 10 + Sanctum (Bearer Token)
- MySQL
- Spatie PDF-to-Text (`pdftotext`)
- Jobs/Queues para processamento de PDF

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# Configure DB_* no .env (DB_DATABASE=controleFaturaBack)
php artisan migrate --seed
php artisan serve --host=0.0.0.0 --port=5000
```

Usuário demo criado pelo seeder:

- **E-mail:** `demo@demo.com`
- **Senha:** `123456`

Com fila assíncrona (recomendado em produção):

```bash
# .env
QUEUE_CONNECTION=database
php artisan queue:table && php artisan migrate
php artisan queue:work
```

> Em `local` com `QUEUE_CONNECTION=sync` o Job roda sincronamente no request.

Dependência do sistema: `pdftotext` (pacote `poppler-utils`).

```bash
# Debian/Ubuntu
sudo apt install poppler-utils
```

## Autenticação

```http
POST /api/v1/auth/register
POST /api/v1/auth/login              # etapa 4: lembrar_me opcional (só contrato; e-mail fica no front)
POST /api/v1/auth/logout   (Bearer)
GET  /api/v1/auth/me       (Bearer)
PUT  /api/v1/auth/perfil   (Bearer)  # nome, sobrenome, cpf_cnpj, email
POST /api/v1/auth/recuperar-senha    # etapa 3
POST /api/v1/auth/verificar-codigo   # etapa 3
POST /api/v1/auth/redefinir-senha    # etapa 3
```

Envie o header: `Authorization: Bearer {token}`

No registro, o sistema já cria categorias e responsáveis padrão e **já autentica** (token na resposta). Dados de faturas/cadastros são isolados por `user_id` do usuário logado.

Spec (etapas 1–4): [`docs/modules/auth.md`](docs/modules/auth.md)  
Prompt do front (mesmas etapas): [`docs/frontend-prompt-auth.md`](docs/frontend-prompt-auth.md)  
Perfil (ver / editar dados): [`docs/modules/perfil.md`](docs/modules/perfil.md) · [`docs/frontend-prompt-perfil.md`](docs/frontend-prompt-perfil.md)  
Pessoas / titulares (várias pessoas na conta + aviso no import): [`docs/modules/pessoas.md`](docs/modules/pessoas.md) · [`docs/frontend-prompt-pessoas.md`](docs/frontend-prompt-pessoas.md)

Recuperar senha envia o código por e-mail (`MAIL_*`). Em local, use Mailpit:

```bash
docker run -d --name mailpit -p 1025:1025 -p 8025:8025 axllent/mailpit:latest
```

No `.env` com `php artisan serve` no host: `MAIL_HOST=127.0.0.1` e `MAIL_PORT=1025`. UI: http://127.0.0.1:8025. Só use `MAIL_HOST=mailpit` se a API estiver na mesma rede Docker do Mailpit.

## Módulos (padrão CRUD)

Todos os módulos autenticados seguem:

| Método | Rota | Ação |
|--------|------|------|
| GET | `/lookups` | Lookups |
| GET | `/listar` | Listagem paginada |
| GET | `/listar/{id}` | Detalhe |
| POST | `/cadastrar` | Criar |
| PUT | `/editar` | Editar |
| DELETE | `/excluir/{id}` | Soft delete |
| GET | `/{modulo}-list` | Async select |

Prefixos:

- `/api/v1/cartoes`
- `/api/v1/categorias`
- `/api/v1/subcategorias`
- `/api/v1/estabelecimentos`
- `/api/v1/responsaveis` (`GET /visualizar/{id}` — hub com contadores)
- `/api/v1/pessoas`
- `/api/v1/faturas`
- `/api/v1/transacoes`
- `/api/v1/repasses`
- `/api/v1/assinaturas` (detector de cobranças recorrentes)
- `/api/v1/dashboard/resumo` (`ano`, `mes` ou `mes_inicio`+`mes_fim`)
- `/api/v1/dashboard/projecao-faturas`
- `/api/v1/dashboard/ranking-parceladas`
- `/api/v1/dashboard/gastos-criticos`
- `/api/v1/dashboard/raio-x`

### Faturas — extras

```http
GET    /api/v1/faturas/listar         # agrupado por cartão; ordem competência→cartão→status; perPage = faturas
POST   /api/v1/faturas/upload-pdf     # multipart: id, arquivo_pdf, processar_automatico, senha_pdf?, salvar_senha_pdf?
POST   /api/v1/faturas/processar/{id} # reprocessa PDF (body: senha_pdf?, salvar_senha_pdf?)
GET    /api/v1/faturas/pdf/{id}       # visualiza/baixa o PDF original
GET    /api/v1/faturas/impacto-remover-anexo/{id}  # etapa 1: preview ao remover/trocar PDF
POST   /api/v1/faturas/remover-anexo  # etapa 2: motivo=remover; etapa 3: motivo=trocar_pdf + arquivo_pdf
GET    /api/v1/faturas/compras-para-reconcilia/{id}  # etapa 4: compras a conciliar no PDF certo
DELETE /api/v1/faturas/excluir-todas  # reset de testes: body/query confirmar=true
```

Senha de PDF no cartão + modal: [`docs/frontend-prompt-senha-pdf-fatura.md`](docs/frontend-prompt-senha-pdf-fatura.md).  
Remover / trocar PDF (etapas 1–4): [`docs/modules/fatura-anexo-desvincular.md`](docs/modules/fatura-anexo-desvincular.md) · [`docs/frontend-prompt-remover-pdf-fatura.md`](docs/frontend-prompt-remover-pdf-fatura.md).

### Estabelecimentos — extras

```http
DELETE /api/v1/estabelecimentos/excluir-todos  # reset: estabelecimentos + categorias + subcategorias; confirmar=true
GET    /api/v1/estabelecimentos/estatisticas/{id}  # compras, gasto, frequência (query: mes/ano ou data_inicio/data_fim)
GET    /api/v1/lojas/estatisticas/{id}             # totais da loja + cada estabelecimento
GET    /api/v1/responsaveis/visualizar/{id}        # hub: totais, em aberto, repasse, competência (query mes/ano)
```

Prompts do front:

- Auth (cadastro, isolamento, recuperar senha, lembrar-me): [`docs/frontend-prompt-auth.md`](docs/frontend-prompt-auth.md)
- Perfil (nome, sobrenome, CPF/CNPJ, e-mail): [`docs/frontend-prompt-perfil.md`](docs/frontend-prompt-perfil.md)
- Pessoas / titulares + confirmação no import: [`docs/frontend-prompt-pessoas.md`](docs/frontend-prompt-pessoas.md)
- Responsável automático (fatura de outro titular): [`docs/frontend-prompt-responsavel-titular.md`](docs/frontend-prompt-responsavel-titular.md)
- Cartões (grupo → bandeira → número): [`docs/frontend-prompt-cartoes.md`](docs/frontend-prompt-cartoes.md)
- Cores oficiais dos cartões: [`docs/frontend-prompt-cores-cartoes.md`](docs/frontend-prompt-cores-cartoes.md)
- Cartões homologados para PDF (aviso se o valor pode estar errado): [`docs/frontend-prompt-fatura-parser-homologado.md`](docs/frontend-prompt-fatura-parser-homologado.md)
- Cores oficiais das bandeiras: [`docs/frontend-prompt-cores-bandeiras.md`](docs/frontend-prompt-cores-bandeiras.md)
- Faturas (bandeira + agrupamento por final): [`docs/frontend-prompt-faturas.md`](docs/frontend-prompt-faturas.md)
- Remover / trocar PDF da fatura (etapas 1–4): [`docs/frontend-prompt-remover-pdf-fatura.md`](docs/frontend-prompt-remover-pdf-fatura.md)
- Pagamentos e Financiamentos (detalhe da fatura): [`docs/frontend-prompt-fatura-pagamentos-financiamentos.md`](docs/frontend-prompt-fatura-pagamentos-financiamentos.md)
- Fatura do responsável (por competência, todos os cartões): [`docs/frontend-prompt-fatura-responsavel.md`](docs/frontend-prompt-fatura-responsavel.md)
- Repasses do responsável (matriz compra × mês): [`docs/frontend-prompt-repasses-responsavel.md`](docs/frontend-prompt-repasses-responsavel.md)
- Projeção de faturas: [`docs/frontend-prompt-projecao-faturas.md`](docs/frontend-prompt-projecao-faturas.md)
- Simulador de compra / Posso comprar? (veredito 🟢🟡🔴 + overlay da Projeção): [`docs/frontend-prompt-simulador-compra.md`](docs/frontend-prompt-simulador-compra.md) · [`docs/frontend-prompt-posso-comprar.md`](docs/frontend-prompt-posso-comprar.md)
- Compras (seleção de `cartao_numero_id`): [`docs/frontend-prompt-compras.md`](docs/frontend-prompt-compras.md)
- Cadastro manual de compra (modal Nova compra + fatura automática): [`docs/frontend-prompt-cadastro-manual-compra.md`](docs/frontend-prompt-cadastro-manual-compra.md)
- Compra rápida (descrição, valor, data, cartão, parcelas): [`docs/frontend-prompt-compra-rapida.md`](docs/frontend-prompt-compra-rapida.md)
- Validação visual do form de compra (`is-invalid` nos obrigatórios): [`docs/frontend-prompt-validacao-formulario-compra.md`](docs/frontend-prompt-validacao-formulario-compra.md)
- Ranking de parceladas: [`docs/frontend-prompt-ranking-parceladas.md`](docs/frontend-prompt-ranking-parceladas.md)
- Gastos críticos (“onde estou gastando demais?”): [`docs/frontend-prompt-gastos-criticos.md`](docs/frontend-prompt-gastos-criticos.md)
- Dashboard (resumo: selects de ano/mês + intervalo): [`docs/frontend-prompt-dashboard.md`](docs/frontend-prompt-dashboard.md)
- Raio-X Financeiro (interpreta o mês: sinais 🟢🟡🔴 + problema principal): [`docs/frontend-prompt-raio-x.md`](docs/frontend-prompt-raio-x.md)
- Visualização da compra: [`docs/frontend-prompt-visualizacao-compra.md`](docs/frontend-prompt-visualizacao-compra.md)
- Visualização do responsável (contadores + fatura + vínculos): [`docs/frontend-prompt-visualizacao-responsavel.md`](docs/frontend-prompt-visualizacao-responsavel.md)
- Cadastro rápido categoria/subcategoria: [`docs/frontend-prompt-cadastro-rapido-categoria-subcategoria.md`](docs/frontend-prompt-cadastro-rapido-categoria-subcategoria.md)
- Cadastro rápido de cartão (botão + na compra): [`docs/frontend-prompt-cadastro-rapido-cartao.md`](docs/frontend-prompt-cadastro-rapido-cartao.md)
- Limpar faturas/transações (reset testes): [`docs/frontend-prompt-limpar-faturas.md`](docs/frontend-prompt-limpar-faturas.md)
- Limpar estabelecimentos/categorias (reset testes): [`docs/frontend-prompt-limpar-estabelecimentos.md`](docs/frontend-prompt-limpar-estabelecimentos.md)
- Estatísticas estabelecimento/loja: [`docs/frontend-prompt-estatisticas-estabelecimento-loja.md`](docs/frontend-prompt-estatisticas-estabelecimento-loja.md)
- Assinaturas (detector de cobranças recorrentes): [`docs/frontend-prompt-assinaturas.md`](docs/frontend-prompt-assinaturas.md)

### Transações — extras

```http
GET /api/v1/transacoes/exportar                    # CSV (Excel) com os mesmos filtros da listagem
GET /api/v1/transacoes/visualizar/{identificador}  # detalhe da compra (grupo ou id); query mes/ano
GET /api/v1/transacoes/candidatos-conciliacao/{identificador}
POST /api/v1/transacoes/conciliar                  # { compra_id, lancamento_id }
POST /api/v1/transacoes/desvincular
POST /api/v1/transacoes/rejeitar-conciliacao
GET|POST /api/v1/transacoes/anexos                 # anexos da compra (multipart no POST)
GET /api/v1/transacoes/anexos/{id}                 # download
DELETE /api/v1/transacoes/anexos/{id}
GET /api/v1/transacoes/historico/{identificador}
```

Prompt: [`docs/frontend-prompt-cadastro-manual-compra.md`](docs/frontend-prompt-cadastro-manual-compra.md) · Compra rápida: [`docs/frontend-prompt-compra-rapida.md`](docs/frontend-prompt-compra-rapida.md) · Validação do form: [`docs/frontend-prompt-validacao-formulario-compra.md`](docs/frontend-prompt-validacao-formulario-compra.md)

### Repasses do responsável — extras

```http
GET  /api/v1/repasses/matriz              # compra × competência (status de repasse)
POST /api/v1/repasses/quitar-competencia  # quita todas as parcelas em aberto do mês
```

Prompt: [`docs/frontend-prompt-repasses-responsavel.md`](docs/frontend-prompt-repasses-responsavel.md) · Spec: [`docs/modules/repasses.md`](docs/modules/repasses.md)

### Assinaturas — extras

```http
GET  /api/v1/assinaturas/listar                      # detector + gasto anual estimado
GET  /api/v1/assinaturas/listar/{identificador}      # detalhe + cobranças recentes (loja-12 | estabelecimento-45)
POST /api/v1/assinaturas/cadastrar                   # confirmar grupo (identificador) ou compra (transacao_id)
PUT  /api/v1/assinaturas/editar                      # acao: confirmar | ignorar | restaurar | desfazer_confirmacao
```

Prompt: [`docs/frontend-prompt-assinaturas.md`](docs/frontend-prompt-assinaturas.md) · Spec: [`docs/modules/assinaturas.md`](docs/modules/assinaturas.md)

### Gastos críticos — extras

```http
GET /api/v1/dashboard/gastos-criticos?meses=3  # lugar, frequência, evolução, categoria/sub
```

Não é só pizza de categoria: destaques (o que mais gasta × o que mais compra), alertas com frase pronta, rankings de loja/estabelecimento/categoria/subcategoria.

Prompt: [`docs/frontend-prompt-gastos-criticos.md`](docs/frontend-prompt-gastos-criticos.md) · Spec: [`docs/modules/gastos-criticos.md`](docs/modules/gastos-criticos.md)

### Raio-X Financeiro

```http
GET /api/v1/dashboard/raio-x?mes=8&ano=2026  # frases prontas, não KPI
```

Não é o resumo numérico: três sinais (pagamentos, crescimento das faturas, comprometimento da renda) + um problema principal + frase de projeção.

Prompt: [`docs/frontend-prompt-raio-x.md`](docs/frontend-prompt-raio-x.md) · Spec: [`docs/modules/raio-x.md`](docs/modules/raio-x.md)

### Dashboard (resumo)

```http
GET /api/v1/dashboard/resumo?ano=2026
GET /api/v1/dashboard/resumo?ano=2026&mes=7
GET /api/v1/dashboard/resumo?ano=2026&mes_inicio=3&mes_fim=6
```

Totais e gráficos por competência da fatura. Ano todo, um mês ou intervalo no mesmo ano. Front: selects (não input text).

Prompt: [`docs/frontend-prompt-dashboard.md`](docs/frontend-prompt-dashboard.md) · Spec: [`docs/modules/dashboard.md`](docs/modules/dashboard.md)

## Parsers de PDF

Ver guia completo em [`docs/pdf-parsers.md`](docs/pdf-parsers.md).

Parsers **homologados** (fatura real): **Nubank**, **Inter**, **C6**, **Sofisa**, **PicPay** e **Itaú Click**. Os demais caem no **Genérico** (não quebra; valores podem sair errados). Prompt: [`docs/frontend-prompt-fatura-parser-homologado.md`](docs/frontend-prompt-fatura-parser-homologado.md).

## Padrões

Ver `docs/backend-patterns.md` e `docs/crud-template.md`.  
Especificações por módulo em `docs/modules/`.
