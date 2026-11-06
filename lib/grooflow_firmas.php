<?php

declare(strict_types=1);

/**
 * Banco de firmas: registro manuscrito por persona y firma electrónica con sello
 * (nombre, documento, correo, fecha, IP y token) sobre el documento.
 */

const GROOFLOW_FIRMAS_MODULE = 'Banco de Firmas';
const GROOFLOW_FIRMAS_MAX_FILE = 4194304;
const GROOFLOW_FIRMAS_MAX_PNG = 1572864;

function grooflow_firmas_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_firmas_banco (
            user_id BIGINT UNSIGNED PRIMARY KEY,
            nombre VARCHAR(160) NOT NULL,
            documento VARCHAR(40) NOT NULL,
            email VARCHAR(160) NOT NULL,
            cargo VARCHAR(120) NOT NULL DEFAULT '',
            area VARCHAR(120) NOT NULL DEFAULT '',
            firma_png MEDIUMBLOB NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_firmas_documentos (
            id VARCHAR(40) PRIMARY KEY,
            titulo VARCHAR(200) NOT NULL,
            nombre_archivo VARCHAR(200) NOT NULL,
            mime VARCHAR(80) NOT NULL,
            sha256 CHAR(64) NOT NULL,
            archivo LONGBLOB NOT NULL,
            modo VARCHAR(16) NOT NULL DEFAULT 'electronica',
            firmante_user_id BIGINT UNSIGNED NOT NULL,
            estado VARCHAR(16) NOT NULL DEFAULT 'pendiente',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            motivo VARCHAR(400) NULL,
            KEY idx_firmas_doc_estado (estado),
            KEY idx_firmas_doc_firmante (firmante_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_firmas_eventos (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            documento_id VARCHAR(40) NOT NULL,
            accion VARCHAR(16) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            nombre VARCHAR(160) NOT NULL,
            documento_num VARCHAR(40) NOT NULL,
            email VARCHAR(160) NOT NULL,
            cargo VARCHAR(120) NOT NULL DEFAULT '',
            ip VARCHAR(64) NOT NULL,
            token CHAR(32) NOT NULL,
            fecha DATETIME NOT NULL,
            firma_png MEDIUMBLOB NULL,
            UNIQUE KEY uk_firmas_token (token),
            KEY idx_firmas_evt_doc (documento_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function grooflow_firmas_ctx(PDO $pdo): array
{
    return grooflow_access_context($pdo);
}

function grooflow_firmas_can(PDO $pdo, string $action): bool
{
    $ctx = grooflow_firmas_ctx($pdo);
    if (!empty($ctx['admin'])) {
        return true;
    }
    $perms = $ctx['actions'][GROOFLOW_FIRMAS_MODULE] ?? [];
    if ($action === 'ver') {
        return !empty($perms['ver']) || !empty($ctx['permissions'][GROOFLOW_FIRMAS_MODULE]);
    }

    return !empty($perms[$action]);
}

function grooflow_firmas_require(PDO $pdo, string $action): void
{
    if (!grooflow_firmas_can($pdo, $action)) {
        throw new RuntimeException('No tienes permiso para esta acción del banco de firmas');
    }
}

function grooflow_firmas_user(): array
{
    $user = api_current_user() ?? [];
    $userId = (int) ($user['id'] ?? 0);
    $userName = trim(((string) ($user['nombre'] ?? '')) . ' ' . ((string) ($user['apellido'] ?? '')));
    if ($userName === '') {
        $userName = (string) ($user['email'] ?? 'Usuario');
    }

    return [$user, $userId, $userName];
}

function grooflow_firmas_sees_all(PDO $pdo): bool
{
    $ctx = grooflow_firmas_ctx($pdo);

    return !empty($ctx['admin']) || !empty($ctx['actions'][GROOFLOW_FIRMAS_MODULE]['configurar']);
}

function grooflow_firmas_str(mixed $v, int $max): string
{
    $s = trim((string) ($v ?? ''));

    return mb_substr($s, 0, $max);
}

function grooflow_firmas_ip(): string
{
    $fwd = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($fwd !== '') {
        $first = trim(explode(',', $fwd)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    return $ip !== '' ? $ip : '0.0.0.0';
}

function grooflow_firmas_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
}

function grooflow_firmas_fmt(string $mysql): string
{
    try {
        $dt = new DateTimeImmutable($mysql, new DateTimeZone('America/Lima'));
    } catch (Throwable $e) {
        return $mysql;
    }

    return $dt->format('d/m/Y') . ' a las ' . $dt->format('H:i');
}

function grooflow_firmas_decode_data_url(string $dataUrl, int $max, array $allowedMime): string
{
    $dataUrl = trim($dataUrl);
    if (!preg_match('#^data:([a-z0-9.+/-]+);base64,(.+)$#i', $dataUrl, $m)) {
        throw new InvalidArgumentException('El archivo no tiene un formato válido');
    }
    $mime = strtolower($m[1]);
    if (!in_array($mime, $allowedMime, true)) {
        throw new InvalidArgumentException('Tipo de archivo no permitido');
    }
    $bin = base64_decode($m[2], true);
    if ($bin === false || $bin === '') {
        throw new InvalidArgumentException('No se pudo leer el archivo');
    }
    if (strlen($bin) > $max) {
        throw new InvalidArgumentException('El archivo supera el tamaño permitido');
    }

    return $bin;
}

function grooflow_firmas_banco_public(array $row, bool $withPng): array
{
    $png = $row['firma_png'] ?? null;
    $has = is_string($png) && $png !== '';
    $out = [
        'userId' => (int) $row['user_id'],
        'nombre' => (string) $row['nombre'],
        'documento' => (string) $row['documento'],
        'email' => (string) $row['email'],
        'cargo' => (string) $row['cargo'],
        'area' => (string) $row['area'],
        'hasFirma' => $has,
        'updatedAt' => grooflow_firmas_fmt((string) $row['updated_at']),
    ];
    if ($withPng && $has) {
        $out['firmaPng'] = 'data:image/png;base64,' . base64_encode($png);
    }

    return $out;
}

function grooflow_firmas_suggest(PDO $pdo, int $userId): array
{
    $nombre = '';
    $email = '';
    $documento = '';
    try {
        $stmt = $pdo->prepare('SELECT nombre, apellido, email, identificacion FROM app_usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $nombre = trim(((string) ($row['nombre'] ?? '')) . ' ' . ((string) ($row['apellido'] ?? '')));
        $email = (string) ($row['email'] ?? '');
        $documento = trim((string) ($row['identificacion'] ?? ''));
    } catch (Throwable $e) {
        [, , $nombre] = grooflow_firmas_user();
        $email = (string) ((api_current_user() ?? [])['email'] ?? '');
    }

    return [
        'userId' => $userId,
        'nombre' => $nombre,
        'documento' => $documento,
        'email' => $email,
        'cargo' => '',
        'area' => '',
        'hasFirma' => false,
        'firmaPng' => null,
        'updatedAt' => '',
    ];
}

function grooflow_firmas_banco_get(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM grooflow_firmas_banco WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function grooflow_firmas_banco_list(PDO $pdo): array
{
    grooflow_firmas_require($pdo, 'ver');
    $rows = $pdo->query('SELECT user_id, nombre, documento, email, cargo, area, CASE WHEN firma_png IS NULL OR OCTET_LENGTH(firma_png) = 0 THEN NULL ELSE \'1\' END AS firma_png, updated_at FROM grooflow_firmas_banco ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static fn (array $row): array => grooflow_firmas_banco_public($row, false), $rows);
}

function grooflow_firmas_banco_mia(PDO $pdo): array
{
    grooflow_firmas_require($pdo, 'ver');
    [, $userId] = grooflow_firmas_user();
    $row = grooflow_firmas_banco_get($pdo, $userId);
    if (!$row) {
        return grooflow_firmas_suggest($pdo, $userId);
    }

    return grooflow_firmas_banco_public($row, true);
}

function grooflow_firmas_banco_save(PDO $pdo, array $body): array
{
    grooflow_firmas_require($pdo, 'ver');
    [, $userId] = grooflow_firmas_user();
    $nombre = grooflow_firmas_str($body['nombre'] ?? '', 160);
    $documento = grooflow_firmas_str($body['documento'] ?? '', 40);
    $email = strtolower(grooflow_firmas_str($body['email'] ?? '', 160));
    $cargo = grooflow_firmas_str($body['cargo'] ?? '', 120);
    $area = grooflow_firmas_str($body['area'] ?? '', 120);
    if ($nombre === '' || $documento === '' || $email === '') {
        throw new InvalidArgumentException('Nombre, número de documento y correo son obligatorios');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('El correo no es válido');
    }
    $png = null;
    $touchPng = array_key_exists('firmaPng', $body);
    if ($touchPng && is_string($body['firmaPng']) && trim($body['firmaPng']) !== '') {
        $png = grooflow_firmas_decode_data_url($body['firmaPng'], GROOFLOW_FIRMAS_MAX_PNG, ['image/png', 'image/jpeg', 'image/webp']);
    }
    $existing = grooflow_firmas_banco_get($pdo, $userId);
    if ($existing) {
        if ($touchPng) {
            $stmt = $pdo->prepare('UPDATE grooflow_firmas_banco SET nombre=?, documento=?, email=?, cargo=?, area=?, firma_png=?, updated_at=? WHERE user_id=?');
            $stmt->execute([$nombre, $documento, $email, $cargo, $area, $png, grooflow_firmas_now()->format('Y-m-d H:i:s'), $userId]);
        } else {
            $stmt = $pdo->prepare('UPDATE grooflow_firmas_banco SET nombre=?, documento=?, email=?, cargo=?, area=?, updated_at=? WHERE user_id=?');
            $stmt->execute([$nombre, $documento, $email, $cargo, $area, grooflow_firmas_now()->format('Y-m-d H:i:s'), $userId]);
        }
    } else {
        $stmt = $pdo->prepare('INSERT INTO grooflow_firmas_banco (user_id, nombre, documento, email, cargo, area, firma_png, updated_at) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$userId, $nombre, $documento, $email, $cargo, $area, $png, grooflow_firmas_now()->format('Y-m-d H:i:s')]);
    }
    $row = grooflow_firmas_banco_get($pdo, $userId);

    return grooflow_firmas_banco_public($row ?: [], true);
}

function grooflow_firmas_sello_from(array $evt): array
{
    $sello = [
        'nombre' => (string) $evt['nombre'],
        'documento' => (string) $evt['documento_num'],
        'email' => (string) $evt['email'],
        'cargo' => (string) $evt['cargo'],
        'fecha' => grooflow_firmas_fmt((string) $evt['fecha']),
        'ip' => (string) $evt['ip'],
        'token' => (string) $evt['token'],
        'accion' => (string) $evt['accion'],
    ];
    if (!empty($evt['firma_png']) && is_string($evt['firma_png'])) {
        $sello['firmaPng'] = 'data:image/png;base64,' . base64_encode($evt['firma_png']);
    }

    return $sello;
}

function grooflow_firmas_doc_public(array $row, ?array $sello): array
{
    return [
        'id' => (string) $row['id'],
        'titulo' => (string) $row['titulo'],
        'nombreArchivo' => (string) $row['nombre_archivo'],
        'mime' => (string) $row['mime'],
        'sha256' => (string) $row['sha256'],
        'modo' => (string) $row['modo'],
        'estado' => (string) $row['estado'],
        'firmanteUserId' => (int) $row['firmante_user_id'],
        'createdBy' => (int) $row['created_by'],
        'createdAt' => grooflow_firmas_fmt((string) $row['created_at']),
        'motivo' => (string) ($row['motivo'] ?? ''),
        'sello' => $sello,
    ];
}

function grooflow_firmas_eventos_map(PDO $pdo, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT documento_id, accion, user_id, nombre, documento_num, email, cargo, ip, token, fecha FROM grooflow_firmas_eventos WHERE documento_id IN ($place)");
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $evt) {
        $map[(string) $evt['documento_id']] = grooflow_firmas_sello_from($evt);
    }

    return $map;
}

function grooflow_firmas_doc_list(PDO $pdo): array
{
    grooflow_firmas_require($pdo, 'ver');
    [, $userId] = grooflow_firmas_user();
    if (grooflow_firmas_sees_all($pdo)) {
        $rows = $pdo->query('SELECT id, titulo, nombre_archivo, mime, sha256, modo, firmante_user_id, estado, created_by, created_at, motivo FROM grooflow_firmas_documentos ORDER BY created_at DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $stmt = $pdo->prepare('SELECT id, titulo, nombre_archivo, mime, sha256, modo, firmante_user_id, estado, created_by, created_at, motivo FROM grooflow_firmas_documentos WHERE created_by = ? OR firmante_user_id = ? ORDER BY created_at DESC LIMIT 200');
        $stmt->execute([$userId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $map = grooflow_firmas_eventos_map($pdo, array_column($rows, 'id'));
    $names = [];
    foreach (grooflow_firmas_banco_list_raw($pdo) as $b) {
        $names[(int) $b['user_id']] = (string) $b['nombre'];
    }
    $out = [];
    foreach ($rows as $row) {
        $item = grooflow_firmas_doc_public($row, $map[(string) $row['id']] ?? null);
        $item['firmanteNombre'] = $names[(int) $row['firmante_user_id']] ?? '';
        $out[] = $item;
    }

    return $out;
}

function grooflow_firmas_banco_list_raw(PDO $pdo): array
{
    return $pdo->query('SELECT user_id, nombre FROM grooflow_firmas_banco')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function grooflow_firmas_find(PDO $pdo, string $id, bool $withFile = false): array
{
    $cols = $withFile
        ? '*'
        : 'id, titulo, nombre_archivo, mime, sha256, modo, firmante_user_id, estado, created_by, created_at, motivo';
    $stmt = $pdo->prepare("SELECT $cols FROM grooflow_firmas_documentos WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Documento no encontrado');
    }
    [, $userId] = grooflow_firmas_user();
    if (!grooflow_firmas_sees_all($pdo) && (int) $row['created_by'] !== $userId && (int) $row['firmante_user_id'] !== $userId) {
        throw new RuntimeException('No tienes permiso para ver este documento');
    }

    return $row;
}

function grooflow_firmas_doc_create(PDO $pdo, array $body): array
{
    grooflow_firmas_require($pdo, 'agregar');
    [, $userId] = grooflow_firmas_user();
    $titulo = grooflow_firmas_str($body['titulo'] ?? '', 200);
    $nombre = grooflow_firmas_str($body['nombreArchivo'] ?? 'documento.pdf', 200);
    $modo = ($body['modo'] ?? '') === 'manuscrita' ? 'manuscrita' : 'electronica';
    $firmante = (int) ($body['firmanteUserId'] ?? 0);
    if ($titulo === '') {
        throw new InvalidArgumentException('Indica el título del documento');
    }
    if ($firmante <= 0) {
        throw new InvalidArgumentException('Elige quién debe firmar');
    }
    $signer = grooflow_firmas_banco_get($pdo, $firmante);
    if (!$signer) {
        throw new InvalidArgumentException('Esa persona todavía no tiene registro en el banco de firmas');
    }
    if ($modo === 'manuscrita' && (!is_string($signer['firma_png']) || $signer['firma_png'] === '')) {
        throw new InvalidArgumentException('Esa persona no tiene firma manuscrita registrada');
    }
    $bin = grooflow_firmas_decode_data_url((string) ($body['dataUrl'] ?? ''), GROOFLOW_FIRMAS_MAX_FILE, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);
    $mime = 'application/pdf';
    if (preg_match('#^data:([a-z0-9.+/-]+);base64,#i', (string) $body['dataUrl'], $m)) {
        $mime = strtolower($m[1]);
    }
    $id = 'fd-' . bin2hex(random_bytes(8));
    $stmt = $pdo->prepare('INSERT INTO grooflow_firmas_documentos (id, titulo, nombre_archivo, mime, sha256, archivo, modo, firmante_user_id, estado, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $id,
        $titulo,
        $nombre,
        $mime,
        hash('sha256', $bin),
        $bin,
        $modo,
        $firmante,
        'pendiente',
        $userId,
        grooflow_firmas_now()->format('Y-m-d H:i:s'),
    ]);

    return grooflow_firmas_doc_public(grooflow_firmas_find($pdo, $id), null) + ['firmanteNombre' => (string) $signer['nombre']];
}

function grooflow_firmas_act(PDO $pdo, string $id, string $accion, array $body): array
{
    grooflow_firmas_require($pdo, 'ver');
    [, $userId] = grooflow_firmas_user();
    $row = grooflow_firmas_find($pdo, $id, true);
    if ((string) $row['estado'] !== 'pendiente') {
        throw new InvalidArgumentException('Este documento ya fue firmado o rechazado');
    }
    if ((int) $row['firmante_user_id'] !== $userId) {
        throw new RuntimeException('Solo la persona asignada puede firmar o rechazar este documento');
    }
    $signerId = (int) $row['firmante_user_id'];
    $signer = grooflow_firmas_banco_get($pdo, $signerId);
    if (!$signer) {
        throw new InvalidArgumentException('Falta el registro de identidad en el banco de firmas');
    }
    $motivo = grooflow_firmas_str($body['motivo'] ?? '', 400);
    if ($accion === 'rechazado' && $motivo === '') {
        throw new InvalidArgumentException('Indica el motivo del rechazo');
    }
    $now = grooflow_firmas_now();
    $token = bin2hex(random_bytes(16));
    $png = ($accion === 'firmado' && (string) $row['modo'] === 'manuscrita') ? ($signer['firma_png'] ?? null) : null;
    $pdo->beginTransaction();
    try {
        $evt = $pdo->prepare('INSERT INTO grooflow_firmas_eventos (documento_id, accion, user_id, nombre, documento_num, email, cargo, ip, token, fecha, firma_png) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $evt->execute([
            $id,
            $accion,
            $signerId,
            $signer['nombre'],
            $signer['documento'],
            $signer['email'],
            $signer['cargo'],
            grooflow_firmas_ip(),
            $token,
            $now->format('Y-m-d H:i:s'),
            $png,
        ]);
        $upd = $pdo->prepare('UPDATE grooflow_firmas_documentos SET estado = ?, motivo = ? WHERE id = ? AND estado = \'pendiente\'');
        $upd->execute([$accion === 'firmado' ? 'firmado' : 'rechazado', $motivo !== '' ? $motivo : null, $id]);
        if ($upd->rowCount() !== 1) {
            throw new RuntimeException('No se pudo registrar la firma');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $fresh = grooflow_firmas_find($pdo, $id);
    $sello = grooflow_firmas_sello_from([
        'nombre' => $signer['nombre'],
        'documento_num' => $signer['documento'],
        'email' => $signer['email'],
        'cargo' => $signer['cargo'],
        'ip' => grooflow_firmas_ip(),
        'token' => $token,
        'fecha' => $now->format('Y-m-d H:i:s'),
        'accion' => $accion,
        'firma_png' => $png,
    ]);

    return grooflow_firmas_doc_public($fresh, $sello) + ['firmanteNombre' => (string) $signer['nombre']];
}

function grooflow_firmas_doc_one(PDO $pdo, string $id): array
{
    grooflow_firmas_require($pdo, 'ver');
    $row = grooflow_firmas_find($pdo, $id);
    $stmt = $pdo->prepare('SELECT accion, nombre, documento_num, email, cargo, ip, token, fecha, firma_png FROM grooflow_firmas_eventos WHERE documento_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$id]);
    $evt = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $names = [];
    foreach (grooflow_firmas_banco_list_raw($pdo) as $b) {
        $names[(int) $b['user_id']] = (string) $b['nombre'];
    }
    $item = grooflow_firmas_doc_public($row, $evt ? grooflow_firmas_sello_from($evt) : null);
    $item['firmanteNombre'] = $names[(int) $row['firmante_user_id']] ?? '';

    return $item;
}

function grooflow_firmas_delete(PDO $pdo, string $id): void
{
    grooflow_firmas_require($pdo, 'eliminar');
    $row = grooflow_firmas_find($pdo, $id);
    if ((string) $row['estado'] !== 'pendiente') {
        throw new InvalidArgumentException('Solo se puede eliminar un documento que todavía no se firma');
    }
    $pdo->prepare('DELETE FROM grooflow_firmas_documentos WHERE id = ?')->execute([$id]);
}

function grooflow_firmas_send_file(PDO $pdo, string $id): void
{
    grooflow_firmas_require($pdo, 'ver');
    $row = grooflow_firmas_find($pdo, $id, true);
    $mime = (string) $row['mime'];
    $name = (string) $row['nombre_archivo'];
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
    header('Content-Length: ' . strlen((string) $row['archivo']));
    echo $row['archivo'];
    exit;
}

function grooflow_firmas_pdf_text(string $text): string
{
    $map = [
        'á' => "\xE1", 'é' => "\xE9", 'í' => "\xED", 'ó' => "\xF3", 'ú' => "\xFA",
        'Á' => "\xC1", 'É' => "\xC9", 'Í' => "\xCD", 'Ó' => "\xD3", 'Ú' => "\xDA",
        'ñ' => "\xF1", 'Ñ' => "\xD1", 'ü' => "\xFC", 'Ü' => "\xDC",
    ];
    $text = strtr($text, $map);
    $text = preg_replace('/[^\x20-\x7E\xA0-\xFF]/', ' ', $text) ?? $text;

    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function grooflow_firmas_constancia_pdf(array $doc, array $sello): string
{
    $lines = [
        ['18', 'Firmado electronicamente por:'],
        ['11', ''],
        ['12', 'Nombre'],
        ['12', (string) $sello['nombre']],
        ['12', 'Numero de Documento'],
        ['12', (string) $sello['documento']],
        ['12', 'Email'],
        ['12', (string) $sello['email']],
        ['12', 'Fecha'],
        ['12', (string) $sello['fecha']],
        ['12', 'IP'],
        ['12', (string) $sello['ip']],
        ['12', 'Token'],
        ['11', (string) $sello['token']],
        ['11', ''],
        ['11', 'Documento: ' . (string) $doc['nombre_archivo']],
        ['10', 'SHA-256: ' . (string) $doc['sha256']],
        ['10', 'Este sello valida ese archivo. Si el archivo cambia, el token deja de coincidir.'],
    ];
    $y = 760;
    $ops = "BT /F1 18 Tf 50 $y Td (" . grooflow_firmas_pdf_text('Constancia de firma electronica') . ") Tj ET\n";
    $y = 720;
    foreach ($lines as [$size, $text]) {
        if ($text === '') {
            $y -= 10;
            continue;
        }
        $ops .= "BT /F1 $size Tf 50 $y Td (" . grooflow_firmas_pdf_text($text) . ") Tj ET\n";
        $y -= ((int) $size) + 8;
    }
    $objects = [];
    $objects[] = "1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n";
    $objects[] = "2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj\n";
    $objects[] = "3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>endobj\n";
    $objects[] = "4 0 obj<< /Length " . strlen($ops) . " >>stream\n" . $ops . "endstream\nendobj\n";
    $objects[] = "5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj\n";
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= $obj;
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";

    return $pdf;
}

function grooflow_firmas_send_constancia(PDO $pdo, string $id): void
{
    grooflow_firmas_require($pdo, 'ver');
    $row = grooflow_firmas_find($pdo, $id);
    if ((string) $row['estado'] !== 'firmado') {
        throw new InvalidArgumentException('El documento todavía no está firmado');
    }
    $stmt = $pdo->prepare('SELECT * FROM grooflow_firmas_eventos WHERE documento_id = ? AND accion = \'firmado\' LIMIT 1');
    $stmt->execute([$id]);
    $evt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$evt) {
        throw new RuntimeException('No se encontró el sello de firma');
    }
    $pdf = grooflow_firmas_constancia_pdf($row, grooflow_firmas_sello_from($evt));
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="constancia-' . rawurlencode((string) $evt['token']) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function grooflow_firmas_capabilities(PDO $pdo): array
{
    [, $userId] = grooflow_firmas_user();

    return [
        'canView' => grooflow_firmas_can($pdo, 'ver'),
        'canAdd' => grooflow_firmas_can($pdo, 'agregar'),
        'canEdit' => grooflow_firmas_can($pdo, 'editar'),
        'canDelete' => grooflow_firmas_can($pdo, 'eliminar'),
        'canConfigure' => grooflow_firmas_sees_all($pdo),
        'userId' => $userId,
    ];
}

function grooflow_firmas_dispatch(PDO $pdo, string $path, string $method): void
{
    grooflow_firmas_ensure_schema($pdo);
    try {
        if ($path === '/firmas/capabilities' && $method === 'GET') {
            grooflow_firmas_require($pdo, 'ver');
            api_json_response(['ok' => true] + grooflow_firmas_capabilities($pdo));
        }
        if ($path === '/firmas/banco' && $method === 'GET') {
            api_json_response(['ok' => true, 'items' => grooflow_firmas_banco_list($pdo)]);
        }
        if ($path === '/firmas/banco/mia' && $method === 'GET') {
            api_json_response(['ok' => true, 'item' => grooflow_firmas_banco_mia($pdo)]);
        }
        if ($path === '/firmas/banco/mia' && $method === 'PUT') {
            api_json_response(['ok' => true, 'item' => grooflow_firmas_banco_save($pdo, api_request_json())]);
        }
        if ($path === '/firmas/documentos' && $method === 'GET') {
            api_json_response(['ok' => true, 'items' => grooflow_firmas_doc_list($pdo)]);
        }
        if ($path === '/firmas/documentos' && $method === 'POST') {
            api_json_response(['ok' => true, 'item' => grooflow_firmas_doc_create($pdo, api_request_json())]);
        }
        if (preg_match('#^/firmas/documentos/([A-Za-z0-9-]+)$#', $path, $m) && $method === 'GET') {
            api_json_response(['ok' => true, 'item' => grooflow_firmas_doc_one($pdo, $m[1])]);
        }
        if (preg_match('#^/firmas/documentos/([A-Za-z0-9-]+)/archivo$#', $path, $m) && $method === 'GET') {
            grooflow_firmas_send_file($pdo, $m[1]);
        }
        if (preg_match('#^/firmas/documentos/([A-Za-z0-9-]+)/constancia$#', $path, $m) && $method === 'GET') {
            grooflow_firmas_send_constancia($pdo, $m[1]);
        }
        if (preg_match('#^/firmas/documentos/([A-Za-z0-9-]+)/firmar$#', $path, $m) && $method === 'POST') {
            api_json_response(['ok' => true, 'item' => grooflow_firmas_act($pdo, $m[1], 'firmado', api_request_json())]);
        }
        if (preg_match('#^/firmas/documentos/([A-Za-z0-9-]+)/rechazar$#', $path, $m) && $method === 'POST') {
            api_json_response(['ok' => true, 'item' => grooflow_firmas_act($pdo, $m[1], 'rechazado', api_request_json())]);
        }
        if (preg_match('#^/firmas/documentos/([A-Za-z0-9-]+)$#', $path, $m) && $method === 'DELETE') {
            grooflow_firmas_delete($pdo, $m[1]);
            api_json_response(['ok' => true]);
        }
        api_json_response(['ok' => false, 'error' => 'Ruta de firmas no encontrada'], 404);
    } catch (InvalidArgumentException $e) {
        api_json_response(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        $status = str_contains(strtolower($msg), 'permiso') ? 403 : (str_contains(strtolower($msg), 'no encontrado') ? 404 : 400);
        api_json_response(['ok' => false, 'error' => $msg], $status);
    }
}
