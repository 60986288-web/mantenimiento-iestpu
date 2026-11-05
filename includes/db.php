<?php
declare(strict_types=1);

/** Conexión PDO única. Consultas siempre preparadas (sin emulación). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = $GLOBALS['CFG']['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['puerto'], $c['nombre'], $c['charset']);
    try {
        $pdo = new PDO($dsn, $c['usuario'], $c['clave'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '-05:00'");
    } catch (PDOException $e) {
        error_log('Error de conexión BD: ' . $e->getMessage());
        http_response_code(500);
        exit('No se pudo conectar con la base de datos.');
    }
    return $pdo;
}

function consulta(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
