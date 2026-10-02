<?php

declare(strict_types=1);

/**
 * Fotos de comprobantes de módulos que guardan sus registros en KV (p. ej. Caja Chica)
 * y control cruzado para que una misma factura no se cobre en Caja Chica y en Cashback.
 */

const GROOFLOW_RECEIPT_PHOTO_MAX_BYTES = 2_500_000;

/** @return array<string, string> módulo de URL => módulo de menú */
function grooflow_receipt_photo_modules(): array
{
    return ['caja-chica' => 'Caja Chica'];
}

function grooflow_receipts_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done || $pdo->inTransaction()) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_comprobante_fotos (
            modulo VARCHAR(40) NOT NULL,
            ref_id VARCHAR(80) NOT NULL,
            mime VARCHAR(60) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
            photo_hash CHAR(64) NOT NULL,
            data MEDIUMBLOB NOT NULL,
            usuario_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (modulo, ref_id),
            KEY idx_gf_cf_hash (photo_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}

function grooflow_receipt_normalize_number(string $numero): string
{
    return ltrim(preg_replace('/\D/', '', $numero) ?? '', '0');
}

/** Gasto activo de Caja Chica con el mismo RUC + serie + número, o null. */
function grooflow_receipt_petty_cash_match(PDO $pdo, string $ruc, string $serie, string $numero): ?array
{
    $ruc = preg_replace('/\D/', '', $ruc) ?? '';
    $serie = strtoupper(trim($serie));
    $numero = grooflow_receipt_normalize_number($numero);
    if ($ruc === '' || $serie === '' || $numero === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT id,
                   JSON_UNQUOTE(JSON_EXTRACT(payload, '$.requester')) AS requester,
                   JSON_UNQUOTE(JSON_EXTRACT(payload, '$.status')) AS status
            FROM grooflow_caja_chica
            WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, '$.docNumber')) = ?
              AND UPPER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.docSeries')))) = ?
              AND TRIM(LEADING '0' FROM TRIM(COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.voucherNumber')),
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.receiptNumber'))
                  ))) = ?
              AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.status')), '') NOT IN ('voided', 'rejected')
            LIMIT 1
        ");
        $stmt->execute([$ruc, $serie, $numero]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }
    return is_array($row) ? $row : null;
}

/** Factura vigente en Cashback con el mismo RUC + serie + número, o null. */
function grooflow_receipt_cashback_match(PDO $pdo, string $ruc, string $serie, string $numero): ?array
{
    $ruc = preg_replace('/\D/', '', $ruc) ?? '';
    $serie = strtoupper(trim($serie));
    $numero = grooflow_receipt_normalize_number($numero);
    if (strlen($ruc) !== 11 || $serie === '' || $numero === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT id, usuario_nombre, estado FROM grooflow_cashback_facturas
            WHERE emisor_ruc = ? AND UPPER(serie) = ? AND TRIM(LEADING '0' FROM numero) = ? AND estado <> 'rechazada'
            LIMIT 1
        ");
        $stmt->execute([$ruc, $serie, $numero]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }
    return is_array($row) ? $row : null;
}

/**
 * Al guardar Caja Chica: los gastos nuevos no pueden usar una factura ya presentada en Cashback.
 *
 * @param list<mixed> $before
 * @param list<mixed> $after
 */
function grooflow_receipt_assert_petty_cash_not_in_cashback(PDO $pdo, array $before, array $after): void
{
    $known = [];
    foreach ($before as $row) {
        if (is_array($row) && isset($row['id'])) {
            $known[(string) $row['id']] = true;
        }
    }
    foreach ($after as $row) {
        if (!is_array($row) || isset($known[(string) ($row['id'] ?? '')])) {
            continue;
        }
        if (($row['type'] ?? '') !== 'expense' || in_array($row['status'] ?? '', ['voided', 'rejected'], true)) {
            continue;
        }
        $match = grooflow_receipt_cashback_match(
            $pdo,
            (string) ($row['docNumber'] ?? ''),
            (string) ($row['docSeries'] ?? ''),
            (string) ($row['voucherNumber'] ?? $row['receiptNumber'] ?? '')
        );
        if ($match !== null) {
            throw new InvalidArgumentException(sprintf(
                'El comprobante %s-%s ya fue presentado en Cashback por %s. No puede registrarse también en Caja Chica.',
                strtoupper(trim((string) ($row['docSeries'] ?? ''))),
                grooflow_receipt_normalize_number((string) ($row['voucherNumber'] ?? $row['receiptNumber'] ?? '')),
                (string) $match['usuario_nombre']
            ));
        }
    }
}

function grooflow_receipt_module_or_fail(PDO $pdo, string $modulo): string
{
    $menuModule = grooflow_receipt_photo_modules()[$modulo] ?? null;
    if ($menuModule === null) {
        throw new RuntimeException('Módulo de comprobantes no encontrado');
    }
    grooflow_assert_module($pdo, [$menuModule]);
    return $menuModule;
}

/** Registro de Caja Chica (payload) o null si aún no se guardó. */
function grooflow_receipt_petty_cash_row(PDO $pdo, string $refId): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT payload FROM grooflow_caja_chica WHERE id = ? LIMIT 1');
        $stmt->execute([$refId]);
        $raw = $stmt->fetchColumn();
    } catch (Throwable) {
        return null;
    }
    $row = $raw !== false ? grooflow_json_decode((string) $raw) : null;
    return is_array($row) ? $row : null;
}

function grooflow_receipt_assert_can_access(PDO $pdo, string $modulo, string $refId, bool $write): void
{
    $ctx = grooflow_access_context($pdo);
    if (!empty($ctx['admin']) || $modulo !== 'caja-chica') {
        return;
    }
    $row = grooflow_receipt_petty_cash_row($pdo, $refId);
    if ($row === null) {
        if (!$write) {
            $stmt = $pdo->prepare('SELECT usuario_id FROM grooflow_comprobante_fotos WHERE modulo = ? AND ref_id = ?');
            $stmt->execute([$modulo, $refId]);
            if ((string) $stmt->fetchColumn() !== (string) $ctx['id'] && !grooflow_petty_cash_can_view_all($ctx)) {
                throw new RuntimeException('Sin permiso para ver este comprobante');
            }
        }
        return;
    }
    if (!grooflow_petty_cash_row_in_scope($ctx, $row)) {
        throw new RuntimeException('Sin permiso para este comprobante de caja chica');
    }
    if ($write && in_array($row['status'] ?? '', ['approved', 'rejected'], true) && !grooflow_petty_cash_can_audit($ctx)) {
        throw new RuntimeException('Sin permiso para cambiar el comprobante de un gasto ya auditado');
    }
}

function grooflow_receipt_valid_ref(string $refId): string
{
    $refId = trim($refId);
    if (!preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $refId)) {
        throw new InvalidArgumentException('Identificador de registro inválido');
    }
    return $refId;
}

/** @return array{hash: string, size: int} */
function grooflow_receipt_save_photo(PDO $pdo, string $modulo, string $refId, array $in): array
{
    grooflow_receipt_module_or_fail($pdo, $modulo);
    $refId = grooflow_receipt_valid_ref($refId);
    grooflow_receipts_ensure_schema($pdo);
    grooflow_receipt_assert_can_access($pdo, $modulo, $refId, true);

    $dataUrl = trim((string) ($in['photo'] ?? ''));
    if (!preg_match('#^data:(image/jpeg|image/png|image/webp|application/pdf);base64,(.+)$#s', $dataUrl, $m)) {
        throw new InvalidArgumentException('Formato de archivo no admitido (JPG, PNG, WEBP o PDF).');
    }
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || $bytes === '') {
        throw new InvalidArgumentException('No se pudo leer el archivo adjunto.');
    }
    if (strlen($bytes) > GROOFLOW_RECEIPT_PHOTO_MAX_BYTES) {
        throw new InvalidArgumentException('El archivo supera 2.5 MB.');
    }
    $hash = hash('sha256', $bytes);
    $userId = (int) ((api_current_user() ?? [])['id'] ?? 0);
    $pdo->prepare('
        INSERT INTO grooflow_comprobante_fotos (modulo, ref_id, mime, size_bytes, photo_hash, data, usuario_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE mime = VALUES(mime), size_bytes = VALUES(size_bytes),
            photo_hash = VALUES(photo_hash), data = VALUES(data), usuario_id = VALUES(usuario_id)
    ')->execute([$modulo, $refId, $m[1], strlen($bytes), $hash, $bytes, $userId ?: null]);

    $dup = $pdo->prepare('SELECT ref_id FROM grooflow_comprobante_fotos WHERE photo_hash = ? AND NOT (modulo = ? AND ref_id = ?) LIMIT 1');
    $dup->execute([$hash, $modulo, $refId]);
    $dupRef = $dup->fetchColumn();

    return ['hash' => $hash, 'size' => strlen($bytes), 'duplicateOf' => $dupRef !== false ? (string) $dupRef : null];
}

/** @return array{dataUrl: string, mime: string} */
function grooflow_receipt_get_photo(PDO $pdo, string $modulo, string $refId): array
{
    grooflow_receipt_module_or_fail($pdo, $modulo);
    $refId = grooflow_receipt_valid_ref($refId);
    grooflow_receipts_ensure_schema($pdo);
    grooflow_receipt_assert_can_access($pdo, $modulo, $refId, false);
    $stmt = $pdo->prepare('SELECT mime, data FROM grooflow_comprobante_fotos WHERE modulo = ? AND ref_id = ?');
    $stmt->execute([$modulo, $refId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Comprobante no encontrado');
    }
    return ['mime' => (string) $row['mime'], 'dataUrl' => 'data:' . $row['mime'] . ';base64,' . base64_encode((string) $row['data'])];
}

function grooflow_receipt_delete_photo(PDO $pdo, string $modulo, string $refId): void
{
    grooflow_receipt_module_or_fail($pdo, $modulo);
    $refId = grooflow_receipt_valid_ref($refId);
    grooflow_receipts_ensure_schema($pdo);
    grooflow_receipt_assert_can_access($pdo, $modulo, $refId, true);
    $pdo->prepare('DELETE FROM grooflow_comprobante_fotos WHERE modulo = ? AND ref_id = ?')->execute([$modulo, $refId]);
}

/** Consulta previa desde el formulario: ¿esta factura ya está en Cashback? */
function grooflow_receipt_check(PDO $pdo, string $modulo, array $q): array
{
    grooflow_receipt_module_or_fail($pdo, $modulo);
    $match = grooflow_receipt_cashback_match($pdo, (string) ($q['ruc'] ?? ''), (string) ($q['serie'] ?? ''), (string) ($q['numero'] ?? ''));
    return [
        'cashback' => $match !== null ? ['usuarioNombre' => (string) $match['usuario_nombre'], 'estado' => (string) $match['estado']] : null,
    ];
}

function grooflow_receipts_dispatch(PDO $pdo, string $path, string $method): bool
{
    if (!str_starts_with($path, '/receipts/')) {
        return false;
    }
    if (preg_match('#^/receipts/([a-z-]+)/check$#', $path, $m) && $method === 'GET') {
        api_json_response(['ok' => true] + grooflow_receipt_check($pdo, $m[1], $_GET));
        return true;
    }
    if (preg_match('#^/receipts/([a-z-]+)/([^/]+)/photo$#', $path, $m)) {
        if ($method === 'GET') {
            api_json_response(['ok' => true] + grooflow_receipt_get_photo($pdo, $m[1], $m[2]));
            return true;
        }
        if ($method === 'PUT') {
            api_json_response(['ok' => true] + grooflow_receipt_save_photo($pdo, $m[1], $m[2], api_request_json()));
            return true;
        }
        if ($method === 'DELETE') {
            grooflow_receipt_delete_photo($pdo, $m[1], $m[2]);
            api_json_response(['ok' => true]);
            return true;
        }
    }
    api_json_response(['ok' => false, 'error' => 'Ruta de comprobantes no encontrada'], 404);
    return true;
}
