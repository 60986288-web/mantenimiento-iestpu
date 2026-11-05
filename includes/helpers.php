<?php
declare(strict_types=1);

/** Escapa para HTML (defensa XSS). Usar en TODA salida de datos. */
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $ruta = ''): string
{
    return rtrim($GLOBALS['CFG']['app']['base_url'], '/') . '/' . ltrim($ruta, '/');
}

function redirigir(string $ruta): never
{
    header('Location: ' . url($ruta));
    exit;
}

function flash(string $tipo, string $mensaje): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function flash_consumir(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function es_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

/** Lectura validada de entrada. */
function post_str(string $k, int $max = 255): string
{
    $v = trim((string) ($_POST[$k] ?? ''));
    return mb_substr($v, 0, $max);
}

function post_int(string $k): ?int
{
    $v = $_POST[$k] ?? null;
    return (is_string($v) || is_int($v)) && filter_var($v, FILTER_VALIDATE_INT) !== false ? (int) $v : null;
}

function get_int(string $k): ?int
{
    $v = $_GET[$k] ?? null;
    return (is_string($v) || is_int($v)) && filter_var($v, FILTER_VALIDATE_INT) !== false ? (int) $v : null;
}

function en_lista(?string $v, array $permitidos): ?string
{
    return in_array($v, $permitidos, true) ? $v : null;
}

/** Código único legible: SM-2026-000123 */
function generar_codigo(string $prefijo, int $id): string
{
    return sprintf('%s-%s-%06d', $prefijo, date('Y'), $id);
}

function fecha_hora(?string $f): string
{
    return $f ? date('d/m/Y H:i', strtotime($f)) : '—';
}

function etiqueta_estado(string $estado): string
{
    $mapa = [
        'pendiente' => 'warning', 'asignada' => 'info', 'en_atencion' => 'primary',
        'pendiente_repuestos' => 'secondary', 'resuelta' => 'success', 'cerrada' => 'dark',
        'cancelada' => 'danger',
    ];
    $clase = $mapa[$estado] ?? 'light';
    return '<span class="badge text-bg-' . e($clase) . '">' . e(ucfirst(str_replace('_', ' ', $estado))) . '</span>';
}

function ip_cliente(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}
