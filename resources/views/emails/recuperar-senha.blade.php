@component('mail::message')
# Redefinir senha

Olá,

Use o código abaixo no **{{ $nomeApp }}** para criar uma nova senha. Ele vale por **{{ $minutosValidade }} minutos**.

@component('mail::panel')
# {{ $codigo }}
@endcomponent

Se você não pediu essa redefinição, ignore este e-mail. Ninguém consegue alterar sua senha sem esse código.

Obrigado,<br>
{{ $nomeApp }}
@endcomponent
