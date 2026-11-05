<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Llamar al inicio de todo manejador POST. */
function csrf_verificar(): void
{
    $enviado = (string) ($_POST['csrf'] ?? '');
    if ($enviado === '' || !hash_equals(csrf_token(), $enviado)) {
        http_response_code(419);
        exit('Solicitud no válida (token CSRF). Vuelva atrás, recargue la página e intente de nuevo.');
    }
}
