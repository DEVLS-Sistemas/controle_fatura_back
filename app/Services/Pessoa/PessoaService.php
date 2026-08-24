<?php

namespace App\Services\Pessoa;

use App\Models\Cartao;
use App\Models\Fatura;
use App\Models\Pessoa;
use App\Models\Responsavel;
use App\Models\User;
use App\Services\PaginateService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PessoaService
{
    public function handleLookupsPessoa(): array
    {
        return [
            'tipos_nota' => [
                'Pessoa = titular da fatura/cartão (dono do plástico).',
                'Responsável = quem deve a compra (já existe em /responsaveis).',
                'Ao vincular fatura a outro titular, o back cria o responsável com o nome da pessoa e usa como padrão do import.',
                'Não confundir os dois.',
            ],
        ];
    }

    public function handleAddPessoa(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->pessoa = $this->createPessoa($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleEditPessoa(object $atributes): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->pessoa = $this->updatePessoa($atributes);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function handleDeletePessoa(int|string $id): object
    {
        try {
            DB::beginTransaction();

            $result = (object) [];
            $result->pessoa = $this->deletePessoa($id);

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    public function createPessoa(object $atributes): object
    {
        $nome = trim((string) ($atributes->nome ?? ''));
        $sobrenome = $this->nullableTrim($atributes->sobrenome ?? null);
        $cpfCnpj = $this->normalizeCpfCnpj($atributes->cpf_cnpj ?? null);

        if ($nome === '') {
            throw new Exception('O nome da pessoa é obrigatório', 422);
        }

        $pessoa = new Pessoa([
            'user_id' => Auth::id(),
            'nome' => $nome,
            'sobrenome' => $sobrenome,
            'cpf_cnpj' => $cpfCnpj,
            'eh_principal' => false,
            'ativo' => filter_var($atributes->ativo ?? true, FILTER_VALIDATE_BOOLEAN),
        ]);

        if (!$pessoa->save()) {
            throw new Exception('Não foi possível cadastrar a pessoa', 500);
        }

        $this->ensureResponsavelForPessoa($pessoa);

        return (object) [
            'data' => $pessoa->fresh()->toListArray(),
            'status' => true,
            'message' => 'Pessoa cadastrada com sucesso!',
        ];
    }

    public function updatePessoa(object $atributes): object
    {
        if (empty($atributes->id)) {
            throw new Exception('ID da pessoa é obrigatório', 422);
        }

        $record = Pessoa::where('id', $atributes->id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$record) {
            throw new Exception('Pessoa não encontrada', 404);
        }

        $attrs = get_object_vars($atributes);

        if (array_key_exists('nome', $attrs)) {
            $nome = trim((string) ($atributes->nome ?? ''));
            if ($nome === '') {
                throw new Exception('O nome da pessoa é obrigatório', 422);
            }
            $record->nome = $nome;
        }

        if (array_key_exists('sobrenome', $attrs)) {
            $record->sobrenome = $this->nullableTrim($atributes->sobrenome);
        }

        if (array_key_exists('cpf_cnpj', $attrs)) {
            $record->cpf_cnpj = $this->normalizeCpfCnpj($atributes->cpf_cnpj);
        }

        if (array_key_exists('ativo', $attrs) && !$record->eh_principal) {
            $record->ativo = filter_var($atributes->ativo, FILTER_VALIDATE_BOOLEAN);
        }

        // Não permite desmarcar eh_principal / criar segundo principal via edit.
        unset($attrs['eh_principal'], $attrs['user_id']);

        if (!$record->save()) {
            throw new Exception('Não foi possível editar a pessoa', 500);
        }

        if ($record->eh_principal) {
            $this->syncUserFromPrincipal($record);
        } else {
            $this->ensureResponsavelForPessoa($record->fresh());
            $this->syncResponsavelNomeFromPessoa($record->fresh());
        }

        return (object) [
            'data' => $record->fresh()->toListArray(),
            'status' => true,
            'message' => 'Pessoa alterada com sucesso!',
        ];
    }

    public function deletePessoa(int|string $id): object
    {
        $record = Pessoa::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$record) {
            throw new Exception('Pessoa não encontrada', 404);
        }

        if ($record->eh_principal) {
            throw new Exception('Não é possível excluir a pessoa principal da conta', 422);
        }

        $temCartao = Cartao::where('pessoa_id', $record->id)->whereNull('deleted_at')->exists();
        $temFatura = Fatura::where('pessoa_id', $record->id)->whereNull('deleted_at')->exists();

        if ($temCartao || $temFatura) {
            throw new Exception('Não é possível excluir pessoa com cartão ou fatura vinculada. Desative ou reatribua antes.', 422);
        }

        if (!$record->delete()) {
            throw new Exception('Não foi possível excluir a pessoa', 500);
        }

        return (object) [
            'data' => [],
            'status' => true,
            'message' => 'Pessoa excluída com sucesso!',
        ];
    }

    public function getPessoaPaginate(object $atributes): array
    {
        $query = DB::table('pessoas as ent')
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', Auth::id())
            ->select(
                'ent.id',
                'ent.nome',
                'ent.sobrenome',
                'ent.cpf_cnpj',
                'ent.eh_principal',
                'ent.ativo',
                'ent.created_at',
                'ent.updated_at',
                DB::raw("TRIM(CONCAT(ent.nome, ' ', COALESCE(ent.sobrenome, ''))) as nome_completo"),
            )
            ->orderByDesc('ent.eh_principal')
            ->orderBy('ent.nome');

        if (!empty($atributes->nome)) {
            $chave = $atributes->nome;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%')
                    ->orWhere('ent.sobrenome', 'like', '%' . $chave . '%');
            });
        }

        if (isset($atributes->ativo) && $atributes->ativo !== '') {
            $query->where('ent.ativo', filter_var($atributes->ativo, FILTER_VALIDATE_BOOLEAN));
        }

        if (!empty($atributes->palavra_chave)) {
            $chave = $atributes->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%')
                    ->orWhere('ent.sobrenome', 'like', '%' . $chave . '%')
                    ->orWhere('ent.cpf_cnpj', 'like', '%' . $chave . '%');
            });
        }

        $paginate = new PaginateService();
        $resultado = $paginate->_paginate(
            $query,
            $atributes->page,
            $atributes->perPage,
            ['path' => $atributes->url, 'query' => $atributes->query]
        );
        $resultado->appends((array) $atributes);

        return collect($resultado)->toArray();
    }

    public function getPessoaId(int|string $id): array
    {
        $data = DB::table('pessoas as ent')
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', Auth::id())
            ->where('ent.id', $id)
            ->select(
                'ent.id',
                'ent.nome',
                'ent.sobrenome',
                'ent.cpf_cnpj',
                'ent.eh_principal',
                'ent.ativo',
                'ent.created_at',
                'ent.updated_at',
                DB::raw("TRIM(CONCAT(ent.nome, ' ', COALESCE(ent.sobrenome, ''))) as nome_completo"),
            )
            ->first();

        if (!$data) {
            throw new Exception('Pessoa não encontrada', 404);
        }

        return collect($data)->toArray();
    }

    public function getPessoaAsync(object $params): array
    {
        $query = DB::table('pessoas as ent')
            ->whereNull('ent.deleted_at')
            ->where('ent.user_id', Auth::id())
            ->where('ent.ativo', true)
            ->select(
                'ent.id',
                'ent.nome',
                'ent.sobrenome',
                'ent.eh_principal',
                DB::raw("TRIM(CONCAT(ent.nome, ' ', COALESCE(ent.sobrenome, ''))) as nome_completo"),
            )
            ->orderByDesc('ent.eh_principal')
            ->orderBy('ent.nome');

        if (!empty($params->palavra_chave)) {
            $chave = $params->palavra_chave;
            $query->where(function ($q) use ($chave) {
                $q->where('ent.nome', 'like', '%' . $chave . '%')
                    ->orWhere('ent.sobrenome', 'like', '%' . $chave . '%');
            });
            $query->limit(10);
        }

        return $query->get()->toArray();
    }

    /**
     * Garante pessoa principal do usuário (cadastro / migração / perfil).
     */
    public function ensurePrincipalForUser(User $user): Pessoa
    {
        $principal = Pessoa::where('user_id', $user->id)
            ->where('eh_principal', true)
            ->first();

        if ($principal) {
            $this->ensureResponsavelForPessoa($principal);

            return $principal;
        }

        $principal = Pessoa::create([
            'user_id' => $user->id,
            'nome' => $user->name ?: 'Usuário',
            'sobrenome' => $user->sobrenome,
            'cpf_cnpj' => $user->cpf_cnpj,
            'eh_principal' => true,
            'ativo' => true,
        ]);

        $this->ensureResponsavelForPessoa($principal);

        return $principal;
    }

    public function syncPrincipalFromUser(User $user): Pessoa
    {
        $principal = $this->ensurePrincipalForUser($user);
        $principal->nome = $user->name ?: $principal->nome;
        $principal->sobrenome = $user->sobrenome;
        $principal->cpf_cnpj = $user->cpf_cnpj;
        $principal->ativo = true;
        $principal->save();

        return $principal;
    }

    /**
     * Cria pessoa a partir do nome detectado no PDF (ex.: "MAYSA ARAUJO DA CONCEICAO").
     */
    public function createFromNomeCompleto(int $userId, string $nomeCompleto, ?string $cpfCnpj = null): Pessoa
    {
        $parts = preg_split('/\s+/', trim($nomeCompleto)) ?: [];
        $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));

        if ($parts === []) {
            throw new Exception('Nome da pessoa é obrigatório', 422);
        }

        $nome = $parts[0];
        $sobrenome = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null;

        $pessoa = new Pessoa([
            'user_id' => $userId,
            'nome' => mb_convert_case(mb_strtolower($nome, 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
            'sobrenome' => $sobrenome !== null
                ? mb_convert_case(mb_strtolower($sobrenome, 'UTF-8'), MB_CASE_TITLE, 'UTF-8')
                : null,
            'cpf_cnpj' => $this->normalizeCpfCnpj($cpfCnpj),
            'eh_principal' => false,
            'ativo' => true,
        ]);

        $pessoa->save();
        $this->ensureResponsavelForPessoa($pessoa);

        return $pessoa->fresh();
    }

    public function assertPessoaDoUsuario(int $pessoaId, int $userId): Pessoa
    {
        $pessoa = Pessoa::where('id', $pessoaId)
            ->where('user_id', $userId)
            ->where('ativo', true)
            ->first();

        if (!$pessoa) {
            throw new Exception('Pessoa inválida', 422);
        }

        return $pessoa;
    }

    /**
     * Garante um responsável vinculado à pessoa.
     * - Principal → responsável "Eu" (já seedado no register)
     * - Outro titular → cria/reutiliza responsável com o nome completo da pessoa
     */
    public function ensureResponsavelForPessoa(Pessoa $pessoa): Responsavel
    {
        $isEu = static function (?Responsavel $responsavel): bool {
            return $responsavel !== null
                && mb_strtolower(trim((string) $responsavel->nome), 'UTF-8') === 'eu';
        };

        if ($pessoa->responsavel_id) {
            $linked = Responsavel::where('id', $pessoa->responsavel_id)
                ->where('user_id', $pessoa->user_id)
                ->first();
            if ($linked) {
                if (!$pessoa->eh_principal && $isEu($linked)) {
                    $pessoa->responsavel_id = null;
                } else {
                    if (!$linked->ativo) {
                        $linked->ativo = true;
                        $linked->save();
                    }

                    return $linked;
                }
            }
        }

        if ($pessoa->eh_principal) {
            $responsavel = Responsavel::withTrashed()
                ->where('user_id', $pessoa->user_id)
                ->where('nome', 'Eu')
                ->first();

            if ($responsavel) {
                if ($responsavel->trashed()) {
                    $responsavel->restore();
                }
                $responsavel->ativo = true;
                $responsavel->save();
            } else {
                $responsavel = Responsavel::create([
                    'user_id' => $pessoa->user_id,
                    'nome' => 'Eu',
                    'tipo' => 'pessoal',
                    'ativo' => true,
                ]);
            }
        } else {
            $nomeResp = $pessoa->nomeCompleto();
            if ($nomeResp === '') {
                $nomeResp = $pessoa->nome;
            }

            $responsavel = Responsavel::withTrashed()
                ->where('user_id', $pessoa->user_id)
                ->whereRaw('LOWER(nome) = ?', [mb_strtolower($nomeResp, 'UTF-8')])
                ->first();

            if ($responsavel) {
                if ($responsavel->trashed()) {
                    $responsavel->restore();
                }
                $responsavel->nome = $nomeResp;
                $responsavel->tipo = 'pessoal';
                $responsavel->ativo = true;
                $responsavel->save();
            } else {
                $responsavel = Responsavel::create([
                    'user_id' => $pessoa->user_id,
                    'nome' => $nomeResp,
                    'tipo' => 'pessoal',
                    'ativo' => true,
                ]);
            }
        }

        if ((int) $pessoa->responsavel_id !== (int) $responsavel->id) {
            $pessoa->responsavel_id = $responsavel->id;
            $pessoa->save();
        }

        return $responsavel;
    }

    public function syncResponsavelNomeFromPessoa(Pessoa $pessoa): void
    {
        if ($pessoa->eh_principal || !$pessoa->responsavel_id) {
            return;
        }

        $responsavel = Responsavel::where('id', $pessoa->responsavel_id)
            ->where('user_id', $pessoa->user_id)
            ->first();

        if (!$responsavel) {
            return;
        }

        $nomeResp = $pessoa->nomeCompleto();
        if ($nomeResp !== '' && $responsavel->nome !== $nomeResp) {
            $responsavel->nome = $nomeResp;
            $responsavel->save();
        }
    }

    private function syncUserFromPrincipal(Pessoa $pessoa): void
    {
        $user = User::find($pessoa->user_id);
        if (!$user) {
            return;
        }

        $user->name = $pessoa->nome;
        $user->sobrenome = $pessoa->sobrenome;
        $user->cpf_cnpj = $pessoa->cpf_cnpj;
        $user->save();
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeCpfCnpj(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($value ?? '')) ?? '';

        if ($digits === '') {
            return null;
        }

        $length = strlen($digits);
        if ($length !== 11 && $length !== 14) {
            throw new Exception('CPF/CNPJ inválido', 422);
        }

        return $digits;
    }
}
