<?php
// config/api_helpers.php - helpers shared by the JSON endpoints (configure/api.php, operator/api.php).
// Expects $conn (mysqli) from config/database.php to be in scope.

class UserError extends RuntimeException {}

function run(string $sql, array $p = []): mysqli_stmt
{
    global $conn;
    $s = $conn->prepare($sql);
    $s->execute($p ?: null);
    return $s;
}
function rows(string $sql, array $p = []): array { return run($sql, $p)->get_result()->fetch_all(MYSQLI_ASSOC); }
function one(string $sql, array $p = []): ?array { return rows($sql, $p)[0] ?? null; }
function val(string $sql, array $p = []) { $r = one($sql, $p); return $r ? array_values($r)[0] : null; }

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
