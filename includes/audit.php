<?php
declare(strict_types=1);

function auditar(string $accion, ?string $tabla = null, ?int $registro_id = null, array $detalle = [], ?int $usuario_id = null): void
{
    try {
        consulta(
            'INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, detalle, ip) VALUES (?,?,?,?,?,?)',
            [
                $usuario_id ?? ($_SESSION['uid'] ?? null),
                $accion, $tabla, $registro_id,
                $detalle ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null,
                ip_cliente(),
            ]
        );
    } catch (Throwable $e) {
        error_log('Auditoría falló: ' . $e->getMessage());
    }
}

/** Registra un cambio de estado; la tabla solo recibe inserciones. */
function registrar_estado(string $entidad, int $id, ?string $anterior, string $nuevo, ?string $comentario = null): void
{
    consulta(
        'INSERT INTO historial_estados (entidad, entidad_id, estado_anterior, estado_nuevo, usuario_id, comentario) VALUES (?,?,?,?,?,?)',
        [$entidad, $id, $anterior, $nuevo, $_SESSION['uid'] ?? null, $comentario]
    );
}
