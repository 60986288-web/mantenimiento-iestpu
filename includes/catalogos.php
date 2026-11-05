<?php
declare(strict_types=1);

/** Programas (id, codigo, nombre) a los que tiene acceso el usuario, para llenar selectores. */
function programas_para_select(array $u): array
{
    $ids = programas_del_usuario((int) $u['id'], $u['rol']);
    if (!$ids) {
        return [];
    }
    [$f, $p] = filtro_programas('id', $ids);
    return consulta("SELECT id, codigo, nombre FROM programas WHERE activo = 1 AND $f ORDER BY FIELD(codigo,'APSTI','CONT','ENFE','AGRO','ADMIN'), nombre", $p)->fetchAll();
}

const ESTADOS_EQUIPO = ['operativo', 'con_falla', 'en_mantenimiento', 'fuera_de_servicio', 'dado_de_baja'];
const TIPOS_AMBIENTE = ['laboratorio', 'taller', 'oficina', 'aula', 'area_practica', 'simulacion', 'otro'];
const ESTADOS_CONSERVACION = [
    'N'  => 'Nuevo (N)',
    'MB' => 'Muy Bueno (MB)',
    'B'  => 'Bueno (B)',
    'R'  => 'Regular (R)',
    'M'  => 'Malo (M)',
    'MM' => 'Muy Malo (MM)',
];
const FRECUENCIAS_MANT = [
    'no_aplica'  => 'No aplica (Mobiliario/Otro)',
    'mensual'    => 'Mensual (30 días)',
    'trimestral' => 'Trimestral (90 días)',
    'semestral'  => 'Semestral (180 días)',
    'anual'      => 'Anual (365 días)',
];

function texto_estado(string $s): string
{
    return ucfirst(str_replace('_', ' ', $s));
}

function insignia_equipo(string $estado): string
{
    $m = [
        'operativo'         => 'success',
        'con_falla'         => 'warning',
        'en_mantenimiento'  => 'info',
        'fuera_de_servicio' => 'danger',
        'dado_de_baja'      => 'dark',
    ];
    return '<span class="badge text-bg-' . e($m[$estado] ?? 'secondary') . '">' . e(texto_estado($estado)) . '</span>';
}

function insignia_conservacion(?string $c): string
{
    if (!$c) {
        return '<span class="badge text-bg-light text-muted">—</span>';
    }
    $m = ['N' => 'success', 'MB' => 'primary', 'B' => 'info', 'R' => 'secondary', 'M' => 'warning', 'MM' => 'danger'];
    $txt = ESTADOS_CONSERVACION[$c] ?? $c;
    return '<span class="badge text-bg-' . e($m[$c] ?? 'secondary') . '" title="' . e($txt) . '">' . e($c) . '</span>';
}

function insignia_servicio(?string $servicio): string
{
    $s = strtolower((string) $servicio);
    if ($s === 'completo' || $s === 'realizado') {
        return '<span class="badge text-bg-success"><i class="bi bi-check-circle-fill me-1"></i>SERVICIO COMPLETO</span>';
    }
    if ($s === 'pendiente' || $s === 'programado') {
        return '<span class="badge text-bg-warning text-dark"><i class="bi bi-clock-history me-1"></i>SERVICIO PENDIENTE</span>';
    }
    return '<span class="badge text-bg-light text-muted border">No aplica</span>';
}

function insignia_visita(?string $visita): string
{
    $v = strtolower((string) $visita);
    $mapa = [
        'visitado'         => ['success', 'Visitado / Verificado'],
        'programada'       => ['primary', 'Visita Programada'],
        'en_visita'        => ['info',    'En Visita Técnica'],
        'pendiente_visita' => ['danger',  'Pendiente de Visita'],
        'reprogramada'     => ['warning', 'Visita Reprogramada'],
    ];
    if (isset($mapa[$v])) {
        return '<span class="badge text-bg-' . $mapa[$v][0] . '">' . e($mapa[$v][1]) . '</span>';
    }
    return '<span class="badge text-bg-secondary">Sin visita requerida</span>';
}

/**
 * Semáforo profesional para la Fecha Límite de Mantenimiento de cada bien.
 */
function semaforo_mantenimiento(?string $fecha_limite, int $requiere = 1): string
{
    if (!$requiere || !$fecha_limite) {
        return '<span class="text-muted small">No requiere</span>';
    }
    $hoy = new DateTimeImmutable('today');
    $lim = DateTimeImmutable::createFromFormat('!Y-m-d', substr($fecha_limite, 0, 10));
    if (!$lim) {
        return '<span class="text-muted small">—</span>';
    }
    $dias = (int) $hoy->diff($lim)->format('%r%a');
    $fechaFmt = $lim->format('d/m/Y');

    if ($dias < 0) {
        $abs = abs($dias);
        return '<span class="badge text-bg-danger"><i class="bi bi-exclamation-octagon me-1"></i>' . e($fechaFmt) . " (Vencido hace {$abs} d)</span>";
    }
    if ($dias <= 30) {
        return '<span class="badge text-bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>' . e($fechaFmt) . " (En {$dias} d)</span>";
    }
    return '<span class="badge text-bg-success"><i class="bi bi-shield-check me-1"></i>' . e($fechaFmt) . " (En {$dias} d)</span>";
}

/** Valida que sea fecha Y-m-d real y no futura; devuelve null si está vacía. */
function fecha_o_null(string $v): string|false|null
{
    if ($v === '') {
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v || $d > new DateTime('today')) {
        return false;
    }
    return $v;
}

/** Valida cualquier fecha Y-m-d (inclusive futura, para fechas límite y próximas visitas). */
function fecha_valida_o_null(string $v): string|false|null
{
    if ($v === '') {
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) {
        return false;
    }
    return $v;
}
