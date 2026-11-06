<?php

declare(strict_types=1);

/**
 * Descuentos de nómina: auditoría autoriza, RRHH ejecuta el cronograma de cuotas.
 */

const GROOFLOW_NOMINA_MODULE = 'Descuentos Nómina';

const GROOFLOW_NOMINA_CONCEPTOS = [
    'Faltante de caja',
    'Servicio no cobrado',
    'Penalidad',
    'Daño o pérdida',
    'Uniforme',
    'Choque / movilidad',
    'Extorno',
    'Otro',
];

function grooflow_nomina_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_nomina_casos (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(24) NOT NULL,
            estado VARCHAR(24) NOT NULL DEFAULT 'borrador',
            origen VARCHAR(16) NOT NULL DEFAULT 'manual',
            extorno_id BIGINT UNSIGNED NULL,
            concepto VARCHAR(80) NOT NULL,
            observaciones TEXT NULL,
            boleta_ref VARCHAR(160) NULL,
            autorizacion_firmada TINYINT(1) NULL,
            sede VARCHAR(120) NULL,
            firma_autorizada TINYINT(1) NOT NULL DEFAULT 0,
            firma_por VARCHAR(190) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_by_nombre VARCHAR(190) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_nomina_codigo (codigo),
            UNIQUE KEY uk_nomina_extorno (extorno_id),
            KEY idx_nomina_estado (estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        if (!$pdo->query("SHOW COLUMNS FROM grooflow_nomina_casos LIKE 'autorizacion_firmada'")->fetch()) {
            $pdo->exec('ALTER TABLE grooflow_nomina_casos ADD COLUMN autorizacion_firmada TINYINT(1) NULL AFTER boleta_ref');
        }
    } catch (Throwable $e) {
        // columna ya existe / carrera entre requests
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_nomina_lineas (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            caso_id BIGINT UNSIGNED NOT NULL,
            buk_id VARCHAR(40) NULL,
            nombre VARCHAR(190) NOT NULL,
            documento VARCHAR(40) NULL,
            cargo VARCHAR(160) NULL,
            sede VARCHAR(120) NULL,
            monto_total DECIMAL(12,2) NOT NULL,
            n_cuotas INT UNSIGNED NOT NULL,
            inicio_periodo CHAR(7) NULL,
            estado VARCHAR(24) NOT NULL DEFAULT 'pendiente',
            liquidacion_fecha DATE NULL,
            liquidacion_nota VARCHAR(400) NULL,
            KEY idx_nomina_linea_caso (caso_id),
            CONSTRAINT fk_nomina_linea_caso FOREIGN KEY (caso_id) REFERENCES grooflow_nomina_casos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_nomina_cuotas (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            linea_id BIGINT UNSIGNED NOT NULL,
            nro INT UNSIGNED NOT NULL,
            periodo CHAR(7) NOT NULL,
            monto DECIMAL(12,2) NOT NULL,
            estado VARCHAR(24) NOT NULL DEFAULT 'pendiente',
            marcada_at DATETIME NULL,
            marcada_by BIGINT UNSIGNED NULL,
            marcada_by_nombre VARCHAR(190) NULL,
            nota VARCHAR(400) NULL,
            KEY idx_nomina_cuota_linea (linea_id),
            KEY idx_nomina_cuota_periodo (periodo),
            CONSTRAINT fk_nomina_cuota_linea FOREIGN KEY (linea_id) REFERENCES grooflow_nomina_lineas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function grooflow_nomina_can(PDO $pdo, string $action): bool
{
    $ctx = grooflow_access_context($pdo);
    if (!empty($ctx['admin'])) {
        return true;
    }
    if (!empty($ctx['actions'][GROOFLOW_NOMINA_MODULE][$action])) {
        return true;
    }
    if ($action === 'editar' && !empty($ctx['actions'][GROOFLOW_NOMINA_MODULE]['configurar'])) {
        return true;
    }
    if (in_array($action, ['ver', 'agregar', 'configurar', 'editar'], true) && !empty($ctx['actions']['Extornos']['configurar'])) {
        return true;
    }
    if ($action === 'ver' && !empty($ctx['actions']['Extornos']['ver'])) {
        return true;
    }
    if (in_array($action, ['ver', 'editar'], true) && !empty($ctx['actions']['Recursos Humanos']['editar'])) {
        return true;
    }
    if ($action === 'ver' && !empty($ctx['actions']['Recursos Humanos']['ver'])) {
        return true;
    }

    return false;
}

function grooflow_nomina_can_correct(PDO $pdo): bool
{
    $ctx = grooflow_access_context($pdo);
    if (!empty($ctx['admin'])) {
        return true;
    }
    $blob = function_exists('grooflow_petty_cash_identity_blob')
        ? grooflow_petty_cash_identity_blob($ctx)
        : '';
    foreach (['rrhh', 'recursos humanos', 'recurso humano', 'auditor', 'gerencia', 'gerente', 'manager', 'administrador'] as $hint) {
        if ($blob !== '' && str_contains($blob, $hint)) {
            return true;
        }
    }
    $perms = is_array($ctx['permissions'] ?? null) ? $ctx['permissions'] : [];
    if (!empty($perms['Recursos Humanos']) || !empty($perms['Auditoría'])) {
        return true;
    }
    if (!empty($ctx['actions']['Recursos Humanos']['editar']) || !empty($ctx['actions']['Auditoría']['ver']) || !empty($ctx['actions']['Extornos']['configurar'])) {
        return true;
    }

    return !empty($ctx['actions'][GROOFLOW_NOMINA_MODULE]['eliminar'])
        || !empty($ctx['actions'][GROOFLOW_NOMINA_MODULE]['editar'])
        || !empty($ctx['actions'][GROOFLOW_NOMINA_MODULE]['configurar']);
}

function grooflow_nomina_require(PDO $pdo, string $action): void
{
    if (!grooflow_nomina_can($pdo, $action)) {
        throw new RuntimeException('No tienes permiso para esta acción de Descuentos de nómina');
    }
}

function grooflow_nomina_user(): array
{
    $user = api_current_user() ?? [];
    $userId = (int) ($user['id'] ?? 0);
    $userName = trim(((string) ($user['nombre'] ?? '')) . ' ' . ((string) ($user['apellido'] ?? '')));
    if ($userName === '') {
        $userName = (string) ($user['email'] ?? 'Usuario');
    }

    return [$user, $userId, $userName];
}

function grooflow_nomina_flag(mixed $v): int
{
    if ($v === true || $v === 1 || $v === '1') {
        return 1;
    }
    if (is_string($v) && in_array(strtolower(trim($v)), ['si', 'sí', 'yes', 'true'], true)) {
        return 1;
    }

    return 0;
}

function grooflow_nomina_str(mixed $v, int $max): ?string
{
    $s = trim((string) ($v ?? ''));

    return $s !== '' ? mb_substr($s, 0, $max) : null;
}

function grooflow_nomina_periodo(mixed $raw, bool $required = false): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        if ($required) {
            throw new GrooflowValidation(['inicio' => 'Indica el mes de inicio.']);
        }

        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})/', $raw, $m) !== 1) {
        throw new GrooflowValidation(['inicio' => 'El periodo debe ser AAAA-MM.']);
    }
    $ym = $m[1] . '-' . $m[2];
    $dt = DateTimeImmutable::createFromFormat('!Y-m', $ym);
    if (!$dt || $dt->format('Y-m') !== $ym) {
        throw new GrooflowValidation(['inicio' => 'Periodo inválido.']);
    }

    return $ym;
}

function grooflow_nomina_add_month(string $ym, int $add): string
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m', $ym) ?: new DateTimeImmutable('first day of this month');

    return $dt->modify(($add >= 0 ? '+' : '') . $add . ' months')->format('Y-m');
}

/** @return list<float> */
function grooflow_nomina_split(float $total, int $count): array
{
    if ($count <= 0) {
        return [];
    }
    $cents = max(0, (int) round($total * 100));
    $base = intdiv($cents, $count);
    $rest = $cents - ($base * $count);
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $extra = $rest > 0 ? 1 : 0;
        $rest -= $extra;
        $out[] = round(($base + $extra) / 100, 2);
    }

    return $out;
}

function grooflow_nomina_next_codigo(PDO $pdo): string
{
    $year = date('Y');
    $prefix = 'DN-' . $year . '-';
    $stmt = $pdo->prepare('SELECT codigo FROM grooflow_nomina_casos WHERE codigo LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$prefix . '%']);
    $last = (string) ($stmt->fetchColumn() ?: '');
    $n = 1;
    if (preg_match('/(\d+)$/', $last, $m) === 1) {
        $n = ((int) $m[1]) + 1;
    }

    return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

function grooflow_nomina_conceptos_list(PDO $pdo): array
{
    grooflow_nomina_ensure_schema($pdo);
    $extra = [];
    $rows = $pdo->query('SELECT DISTINCT concepto FROM grooflow_nomina_casos')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($rows as $concepto) {
        $concepto = trim((string) $concepto);
        if ($concepto !== '' && !in_array($concepto, GROOFLOW_NOMINA_CONCEPTOS, true)) {
            $extra[] = $concepto;
        }
    }
    sort($extra, SORT_NATURAL | SORT_FLAG_CASE);

    return array_merge(GROOFLOW_NOMINA_CONCEPTOS, $extra);
}

function grooflow_nomina_capabilities(PDO $pdo): array
{
    return [
        'canView' => grooflow_nomina_can($pdo, 'ver'),
        'canCreate' => grooflow_nomina_can($pdo, 'agregar') || grooflow_nomina_can($pdo, 'configurar'),
        'canApprove' => grooflow_nomina_can($pdo, 'configurar'),
        'canOperate' => grooflow_nomina_can($pdo, 'editar') || grooflow_nomina_can($pdo, 'configurar'),
        'canEdit' => grooflow_nomina_can_correct($pdo),
        'canDelete' => grooflow_nomina_can_correct($pdo),
        'conceptos' => grooflow_nomina_conceptos_list($pdo),
    ];
}

function grooflow_nomina_find(PDO $pdo, int $id, bool $lock = false): array
{
    $sql = 'SELECT * FROM grooflow_nomina_casos WHERE id = ?' . ($lock ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Descuento no encontrado');
    }

    return $row;
}

function grooflow_nomina_cuotas_of(PDO $pdo, int $lineaId): array
{
    $stmt = $pdo->prepare('SELECT * FROM grooflow_nomina_cuotas WHERE linea_id = ? ORDER BY nro ASC, id ASC');
    $stmt->execute([$lineaId]);
    $items = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = [
            'id' => (string) $r['id'],
            'nro' => (int) $r['nro'],
            'periodo' => (string) $r['periodo'],
            'monto' => (float) $r['monto'],
            'estado' => (string) $r['estado'],
            'marcadaAt' => $r['marcada_at'],
            'marcadaPor' => $r['marcada_by_nombre'],
            'nota' => $r['nota'],
        ];
    }

    return $items;
}

function grooflow_nomina_row_to_app(PDO $pdo, array $row): array
{
    $id = (int) $row['id'];
    $stmt = $pdo->prepare('SELECT * FROM grooflow_nomina_lineas WHERE caso_id = ? ORDER BY id ASC');
    $stmt->execute([$id]);
    $lineas = [];
    while ($ln = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cuotas = grooflow_nomina_cuotas_of($pdo, (int) $ln['id']);
        $lineas[] = [
            'id' => (string) $ln['id'],
            'bukId' => (string) ($ln['buk_id'] ?? ''),
            'nombre' => (string) $ln['nombre'],
            'documento' => $ln['documento'],
            'cargo' => $ln['cargo'],
            'sede' => $ln['sede'],
            'montoTotal' => (float) $ln['monto_total'],
            'nCuotas' => (int) $ln['n_cuotas'],
            'inicioPeriodo' => $ln['inicio_periodo'],
            'estado' => (string) $ln['estado'],
            'liquidacionFecha' => $ln['liquidacion_fecha'],
            'liquidacionNota' => $ln['liquidacion_nota'],
            'cuotas' => $cuotas,
        ];
    }

    return [
        'id' => (string) $id,
        'codigo' => (string) $row['codigo'],
        'estado' => (string) $row['estado'],
        'origen' => (string) $row['origen'],
        'extornoId' => $row['extorno_id'] !== null ? (string) $row['extorno_id'] : null,
        'concepto' => (string) $row['concepto'],
        'observaciones' => $row['observaciones'],
        'boletaRef' => $row['boleta_ref'],
        'autorizacionFirmada' => array_key_exists('autorizacion_firmada', $row) && $row['autorizacion_firmada'] !== null
            ? (bool) $row['autorizacion_firmada']
            : null,
        'sede' => $row['sede'],
        'firmaAutorizada' => (bool) $row['firma_autorizada'],
        'firmaPor' => $row['firma_por'],
        'createdByNombre' => $row['created_by_nombre'],
        'createdAt' => (string) $row['created_at'],
        'updatedAt' => (string) $row['updated_at'],
        'lineas' => $lineas,
    ];
}

/** @param list<array<string,mixed>> $lineasIn */
function grooflow_nomina_insert_lineas(PDO $pdo, int $casoId, array $lineasIn, bool $generate): void
{
    if ($lineasIn === []) {
        throw new GrooflowValidation(['lineas' => 'Agrega al menos un colaborador.']);
    }
    $insL = $pdo->prepare('
        INSERT INTO grooflow_nomina_lineas
            (caso_id, buk_id, nombre, documento, cargo, sede, monto_total, n_cuotas, inicio_periodo, estado)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ');
    $insC = $pdo->prepare('
        INSERT INTO grooflow_nomina_cuotas (linea_id, nro, periodo, monto, estado) VALUES (?,?,?,?,\'pendiente\')
    ');
    foreach ($lineasIn as $i => $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $nombre = grooflow_nomina_str($ln['nombre'] ?? null, 190);
        $monto = round((float) ($ln['monto'] ?? $ln['montoTotal'] ?? 0), 2);
        $n = (int) ($ln['cuotas'] ?? $ln['nCuotas'] ?? 1);
        if ($nombre === null || $monto <= 0 || $n < 1 || $n > 36) {
            throw new GrooflowValidation(['lineas' => 'Completa colaborador, monto y cuotas (1 a 36) en la fila ' . ($i + 1) . '.']);
        }
        $inicio = grooflow_nomina_periodo($ln['inicio'] ?? $ln['inicioPeriodo'] ?? null, false);
        $insL->execute([
            $casoId,
            grooflow_nomina_str($ln['bukId'] ?? null, 40),
            $nombre,
            grooflow_nomina_str($ln['documento'] ?? null, 40),
            grooflow_nomina_str($ln['cargo'] ?? null, 160),
            grooflow_nomina_str($ln['sede'] ?? null, 120),
            $monto,
            $n,
            $inicio,
            $inicio ? 'en_curso' : 'pendiente',
        ]);
        $lineaId = (int) $pdo->lastInsertId();
        if ($generate && $inicio !== null) {
            $parts = grooflow_nomina_split($monto, $n);
            $custom = is_array($ln['montos'] ?? null) ? $ln['montos'] : null;
            if (is_array($custom) && count($custom) === $n) {
                $sum = 0.0;
                foreach ($custom as $c) {
                    $sum += round((float) $c, 2);
                }
                if (abs($sum - $monto) <= 0.05) {
                    $parts = array_map(static fn ($c): float => round((float) $c, 2), $custom);
                }
            }
            foreach ($parts as $k => $part) {
                $insC->execute([$lineaId, $k + 1, grooflow_nomina_add_month($inicio, $k), $part]);
            }
        }
    }
}

function grooflow_nomina_refresh(PDO $pdo, int $casoId): void
{
    $caso = grooflow_nomina_find($pdo, $casoId, true);
    if (in_array((string) $caso['estado'], ['anulado', 'borrador', 'enviado'], true)) {
        return;
    }
    $stmt = $pdo->prepare('
        SELECT l.id, c.estado
        FROM grooflow_nomina_lineas l
        LEFT JOIN grooflow_nomina_cuotas c ON c.linea_id = l.id
        WHERE l.caso_id = ?
    ');
    $stmt->execute([$casoId]);
    $byLine = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $lid = (int) $r['id'];
        $byLine[$lid][] = $r['estado'];
    }
    $open = 0;
    $anyCuota = false;
    foreach ($byLine as $lid => $estados) {
        $pending = 0;
        $has = false;
        foreach ($estados as $est) {
            if ($est === null) {
                continue;
            }
            $has = true;
            $anyCuota = true;
            if ($est === 'pendiente') {
                $pending++;
            }
        }
        $lineaEstado = !$has ? 'pendiente' : ($pending > 0 ? 'en_curso' : 'cerrado');
        $pdo->prepare('UPDATE grooflow_nomina_lineas SET estado = ? WHERE id = ?')->execute([$lineaEstado, $lid]);
        $open += $pending > 0 || !$has ? 1 : 0;
    }
    $next = $anyCuota ? ($open > 0 ? 'en_planilla' : 'cerrado') : 'aprobado';
    $pdo->prepare('UPDATE grooflow_nomina_casos SET estado = ? WHERE id = ?')->execute([$next, $casoId]);
}

function grooflow_nomina_create(PDO $pdo, array $in): array
{
    grooflow_nomina_ensure_schema($pdo);
    if (!grooflow_nomina_can($pdo, 'agregar') && !grooflow_nomina_can($pdo, 'configurar')) {
        grooflow_nomina_require($pdo, 'agregar');
    }
    [, $userId, $userName] = grooflow_nomina_user();
    $concepto = grooflow_nomina_str($in['concepto'] ?? null, 80) ?? 'Otro';
    $aprobar = !empty($in['aprobar']);
    if ($aprobar && !grooflow_nomina_can($pdo, 'configurar')) {
        throw new RuntimeException('No tienes permiso para aprobar descuentos de nómina');
    }
    $enviar = !empty($in['enviar']) || $aprobar;
    $estado = $aprobar ? 'aprobado' : ($enviar ? 'enviado' : 'borrador');
    $lineas = is_array($in['lineas'] ?? null) ? $in['lineas'] : [];
    $sedeCaso = grooflow_nomina_str($in['sede'] ?? null, 120);
    if ($sedeCaso === null) {
        foreach ($lineas as $ln) {
            if (!is_array($ln)) {
                continue;
            }
            $sedeCaso = grooflow_nomina_str($ln['sede'] ?? null, 120);
            if ($sedeCaso !== null) {
                break;
            }
        }
    }

    $id = 0;
    grooflow_atomic($pdo, static function () use ($pdo, $in, $concepto, $estado, $aprobar, $lineas, $userId, $userName, $sedeCaso, &$id): void {
        $codigo = grooflow_nomina_next_codigo($pdo);
        $pdo->prepare('
            INSERT INTO grooflow_nomina_casos
                (codigo, estado, origen, concepto, observaciones, boleta_ref, autorizacion_firmada, sede, firma_autorizada, firma_por, created_by, created_by_nombre)
            VALUES (?,?,\'manual\',?,?,?,?,?,?,?,?,?)
        ')->execute([
            $codigo,
            $estado,
            $concepto,
            grooflow_nomina_str($in['observaciones'] ?? null, 4000),
            grooflow_nomina_str($in['boletaRef'] ?? null, 160),
            grooflow_nomina_flag($in['autorizacionFirmada'] ?? false),
            $sedeCaso,
            $aprobar ? 1 : 0,
            $aprobar ? mb_substr($userName, 0, 190) : null,
            $userId ?: null,
            mb_substr($userName, 0, 190),
        ]);
        $id = (int) $pdo->lastInsertId();
        grooflow_nomina_insert_lineas($pdo, $id, $lineas, $estado === 'aprobado');
        if ($estado === 'aprobado') {
            grooflow_nomina_refresh($pdo, $id);
        }
    });

    return grooflow_nomina_row_to_app($pdo, grooflow_nomina_find($pdo, $id));
}

function grooflow_nomina_update(PDO $pdo, int $id, array $in): array
{
    grooflow_nomina_ensure_schema($pdo);
    if (!grooflow_nomina_can_correct($pdo)) {
        throw new RuntimeException('No tienes permiso para corregir descuentos de nómina');
    }
    $concepto = grooflow_nomina_str($in['concepto'] ?? null, 80) ?? 'Otro';
    $lineas = is_array($in['lineas'] ?? null) ? $in['lineas'] : [];
    if ($lineas === []) {
        throw new GrooflowValidation(['lineas' => 'Agrega al menos un colaborador.']);
    }
    $sedeCaso = grooflow_nomina_str($in['sede'] ?? null, 120);
    if ($sedeCaso === null) {
        foreach ($lineas as $ln) {
            if (!is_array($ln)) {
                continue;
            }
            $sedeCaso = grooflow_nomina_str($ln['sede'] ?? null, 120);
            if ($sedeCaso !== null) {
                break;
            }
        }
    }

    grooflow_atomic($pdo, static function () use ($pdo, $id, $in, $concepto, $lineas, $sedeCaso): void {
        $caso = grooflow_nomina_find($pdo, $id, true);
        if ((string) $caso['estado'] === 'anulado') {
            throw new InvalidArgumentException('No se edita un descuento anulado. Elimínalo si ya no corresponde.');
        }
        $generar = in_array((string) $caso['estado'], ['aprobado', 'en_planilla', 'cerrado'], true);
        $pdo->prepare('UPDATE grooflow_nomina_casos SET concepto = ?, observaciones = ?, boleta_ref = ?, autorizacion_firmada = ?, sede = ? WHERE id = ?')
            ->execute([
                $concepto,
                grooflow_nomina_str($in['observaciones'] ?? null, 4000),
                grooflow_nomina_str($in['boletaRef'] ?? null, 160),
                array_key_exists('autorizacionFirmada', $in) ? grooflow_nomina_flag($in['autorizacionFirmada']) : null,
                $sedeCaso,
                $id,
            ]);
        $keep = [];
        foreach ($lineas as $i => $ln) {
            if (!is_array($ln)) {
                continue;
            }
            $lineaId = (int) ($ln['id'] ?? 0);
            $existing = null;
            if ($lineaId > 0) {
                $stmt = $pdo->prepare('SELECT * FROM grooflow_nomina_lineas WHERE id = ? AND caso_id = ? FOR UPDATE');
                $stmt->execute([$lineaId, $id]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $nombre = grooflow_nomina_str($ln['nombre'] ?? null, 190);
            $monto = round((float) ($ln['monto'] ?? $ln['montoTotal'] ?? 0), 2);
            $n = (int) ($ln['cuotas'] ?? $ln['nCuotas'] ?? 1);
            $inicio = grooflow_nomina_periodo($ln['inicio'] ?? $ln['inicioPeriodo'] ?? null, false);
            if ($nombre === null || $monto <= 0 || $n < 1 || $n > 36) {
                throw new GrooflowValidation(['lineas' => 'Completa colaborador, monto y cuotas en la fila ' . ($i + 1) . '.']);
            }
            $sameSchedule = is_array($existing)
                && abs((float) $existing['monto_total'] - $monto) < 0.001
                && (int) $existing['n_cuotas'] === $n
                && (string) ($existing['inicio_periodo'] ?? '') === (string) ($inicio ?? '');
            if ($sameSchedule) {
                $pdo->prepare('
                    UPDATE grooflow_nomina_lineas
                    SET buk_id = ?, nombre = ?, documento = ?, cargo = ?, sede = ?
                    WHERE id = ?
                ')->execute([
                    grooflow_nomina_str($ln['bukId'] ?? null, 40),
                    $nombre,
                    grooflow_nomina_str($ln['documento'] ?? null, 40),
                    grooflow_nomina_str($ln['cargo'] ?? null, 160),
                    grooflow_nomina_str($ln['sede'] ?? null, 120),
                    $lineaId,
                ]);
                $keep[] = $lineaId;
                continue;
            }
            if (is_array($existing)) {
                $pdo->prepare('DELETE FROM grooflow_nomina_lineas WHERE id = ?')->execute([$lineaId]);
            }
            $before = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM grooflow_nomina_lineas')->fetchColumn();
            grooflow_nomina_insert_lineas($pdo, $id, [$ln], $generar);
            $after = $pdo->prepare('SELECT id FROM grooflow_nomina_lineas WHERE caso_id = ? AND id > ? ORDER BY id DESC LIMIT 1');
            $after->execute([$id, $before]);
            $newId = (int) ($after->fetchColumn() ?: 0);
            if ($newId > 0) {
                $keep[] = $newId;
            }
        }
        if ($keep === []) {
            throw new GrooflowValidation(['lineas' => 'Agrega al menos un colaborador.']);
        }
        $marks = implode(',', array_map('intval', $keep));
        $pdo->exec('DELETE FROM grooflow_nomina_lineas WHERE caso_id = ' . (int) $id . ' AND id NOT IN (' . $marks . ')');
        if ($generar) {
            grooflow_nomina_refresh($pdo, $id);
        }
    });

    return grooflow_nomina_row_to_app($pdo, grooflow_nomina_find($pdo, $id));
}

function grooflow_nomina_delete(PDO $pdo, int $id): void
{
    grooflow_nomina_ensure_schema($pdo);
    if (!grooflow_nomina_can_correct($pdo)) {
        throw new RuntimeException('No tienes permiso para eliminar descuentos de nómina');
    }
    grooflow_atomic($pdo, static function () use ($pdo, $id): void {
        grooflow_nomina_find($pdo, $id, true);
        $pdo->prepare('DELETE FROM grooflow_nomina_casos WHERE id = ?')->execute([$id]);
    });
}

function grooflow_nomina_transition(PDO $pdo, int $id, array $in): array
{
    grooflow_nomina_ensure_schema($pdo);
    $action = (string) ($in['action'] ?? '');
    [, $userId, $userName] = grooflow_nomina_user();
    grooflow_atomic($pdo, static function () use ($pdo, $id, $action, $userId, $userName): void {
        $row = grooflow_nomina_find($pdo, $id, true);
        $estado = (string) $row['estado'];
        if ($action === 'aprobar') {
            grooflow_nomina_require($pdo, 'configurar');
            if (!in_array($estado, ['borrador', 'enviado'], true)) {
                throw new InvalidArgumentException('Solo se aprueban borradores o casos enviados.');
            }
            $pdo->prepare('UPDATE grooflow_nomina_casos SET estado = \'aprobado\', firma_autorizada = 1, firma_por = ? WHERE id = ?')
                ->execute([mb_substr($userName, 0, 190), $id]);
            $lns = $pdo->prepare('SELECT * FROM grooflow_nomina_lineas WHERE caso_id = ?');
            $lns->execute([$id]);
            $insC = $pdo->prepare('INSERT INTO grooflow_nomina_cuotas (linea_id, nro, periodo, monto, estado) VALUES (?,?,?,?,\'pendiente\')');
            while ($ln = $lns->fetch(PDO::FETCH_ASSOC)) {
                $has = $pdo->prepare('SELECT COUNT(*) FROM grooflow_nomina_cuotas WHERE linea_id = ?');
                $has->execute([(int) $ln['id']]);
                if ((int) $has->fetchColumn() > 0 || empty($ln['inicio_periodo'])) {
                    continue;
                }
                $parts = grooflow_nomina_split((float) $ln['monto_total'], (int) $ln['n_cuotas']);
                foreach ($parts as $k => $part) {
                    $insC->execute([(int) $ln['id'], $k + 1, grooflow_nomina_add_month((string) $ln['inicio_periodo'], $k), $part]);
                }
            }
            grooflow_nomina_refresh($pdo, $id);
        } elseif ($action === 'enviar') {
            if (!grooflow_nomina_can($pdo, 'agregar') && !grooflow_nomina_can($pdo, 'configurar')) {
                grooflow_nomina_require($pdo, 'agregar');
            }
            if ($estado !== 'borrador') {
                throw new InvalidArgumentException('Solo se envían borradores.');
            }
            $pdo->prepare('UPDATE grooflow_nomina_casos SET estado = \'enviado\' WHERE id = ?')->execute([$id]);
        } elseif ($action === 'anular') {
            if (!grooflow_nomina_can($pdo, 'eliminar') && !grooflow_nomina_can($pdo, 'configurar')) {
                grooflow_nomina_require($pdo, 'eliminar');
            }
            $marked = $pdo->prepare('
                SELECT COUNT(*) FROM grooflow_nomina_cuotas c
                JOIN grooflow_nomina_lineas l ON l.id = c.linea_id
                WHERE l.caso_id = ? AND c.estado IN (\'descontada\', \'liquidada\')
            ');
            $marked->execute([$id]);
            if ((int) $marked->fetchColumn() > 0) {
                throw new InvalidArgumentException('No se anula un descuento que ya tiene cuotas marcadas.');
            }
            $pdo->prepare('UPDATE grooflow_nomina_casos SET estado = \'anulado\' WHERE id = ?')->execute([$id]);
        } else {
            throw new InvalidArgumentException('Acción inválida.');
        }
    });

    return grooflow_nomina_row_to_app($pdo, grooflow_nomina_find($pdo, $id));
}

function grooflow_nomina_programar(PDO $pdo, int $lineaId, array $in): array
{
    grooflow_nomina_ensure_schema($pdo);
    grooflow_nomina_require($pdo, 'editar');
    $inicio = grooflow_nomina_periodo($in['inicio'] ?? null, true);
    grooflow_atomic($pdo, static function () use ($pdo, $lineaId, $inicio, $in): void {
        $stmt = $pdo->prepare('SELECT * FROM grooflow_nomina_lineas WHERE id = ? FOR UPDATE');
        $stmt->execute([$lineaId]);
        $ln = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ln)) {
            throw new RuntimeException('Colaborador no encontrado');
        }
        $caso = grooflow_nomina_find($pdo, (int) $ln['caso_id'], true);
        if (!in_array((string) $caso['estado'], ['aprobado', 'en_planilla'], true)) {
            throw new InvalidArgumentException('Programa cuotas solo en descuentos aprobados.');
        }
        $marked = $pdo->prepare('SELECT COUNT(*) FROM grooflow_nomina_cuotas WHERE linea_id = ? AND estado <> \'pendiente\'');
        $marked->execute([$lineaId]);
        if ((int) $marked->fetchColumn() > 0) {
            throw new InvalidArgumentException('Ya hay cuotas descontadas. Solo puedes mover las que siguen pendientes.');
        }
        $pdo->prepare('DELETE FROM grooflow_nomina_cuotas WHERE linea_id = ?')->execute([$lineaId]);
        $n = (int) $ln['n_cuotas'];
        $monto = (float) $ln['monto_total'];
        $parts = grooflow_nomina_split($monto, $n);
        $custom = is_array($in['montos'] ?? null) ? $in['montos'] : null;
        if (is_array($custom) && count($custom) === $n) {
            $sum = 0.0;
            foreach ($custom as $c) {
                $sum += round((float) $c, 2);
            }
            if (abs($sum - $monto) <= 0.05) {
                $parts = array_map(static fn ($c): float => round((float) $c, 2), $custom);
            }
        }
        $ins = $pdo->prepare('INSERT INTO grooflow_nomina_cuotas (linea_id, nro, periodo, monto, estado) VALUES (?,?,?,?,\'pendiente\')');
        foreach ($parts as $k => $part) {
            $ins->execute([$lineaId, $k + 1, grooflow_nomina_add_month($inicio, $k), $part]);
        }
        $pdo->prepare('UPDATE grooflow_nomina_lineas SET inicio_periodo = ?, estado = \'en_curso\' WHERE id = ?')->execute([$inicio, $lineaId]);
        grooflow_nomina_refresh($pdo, (int) $ln['caso_id']);
    });
    $casoId = (int) $pdo->query('SELECT caso_id FROM grooflow_nomina_lineas WHERE id = ' . (int) $lineaId)->fetchColumn();

    return grooflow_nomina_row_to_app($pdo, grooflow_nomina_find($pdo, $casoId));
}

function grooflow_nomina_marcar(PDO $pdo, int $cuotaId, array $in): array
{
    grooflow_nomina_ensure_schema($pdo);
    grooflow_nomina_require($pdo, 'editar');
    $hecho = !array_key_exists('hecho', $in) || !empty($in['hecho']);
    [, $userId, $userName] = grooflow_nomina_user();
    $casoId = 0;
    grooflow_atomic($pdo, static function () use ($pdo, $cuotaId, $hecho, $userId, $userName, &$casoId): void {
        $stmt = $pdo->prepare('SELECT c.*, l.caso_id FROM grooflow_nomina_cuotas c JOIN grooflow_nomina_lineas l ON l.id = c.linea_id WHERE c.id = ? FOR UPDATE');
        $stmt->execute([$cuotaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Cuota no encontrada');
        }
        $casoId = (int) $row['caso_id'];
        $caso = grooflow_nomina_find($pdo, $casoId, true);
        if (!in_array((string) $caso['estado'], ['aprobado', 'en_planilla', 'cerrado'], true)) {
            throw new InvalidArgumentException('Este descuento todavía no está aprobado.');
        }
        if ((string) $row['estado'] === 'liquidada' || (string) $row['estado'] === 'absorbida') {
            throw new InvalidArgumentException('Esa cuota ya quedó en la liquidación.');
        }
        if ($hecho) {
            $pdo->prepare('
                UPDATE grooflow_nomina_cuotas
                SET estado = \'descontada\', marcada_at = NOW(), marcada_by = ?, marcada_by_nombre = ?
                WHERE id = ?
            ')->execute([$userId ?: null, mb_substr($userName, 0, 190), $cuotaId]);
        } else {
            $pdo->prepare('
                UPDATE grooflow_nomina_cuotas
                SET estado = \'pendiente\', marcada_at = NULL, marcada_by = NULL, marcada_by_nombre = NULL
                WHERE id = ?
            ')->execute([$cuotaId]);
        }
        grooflow_nomina_refresh($pdo, $casoId);
    });

    return grooflow_nomina_row_to_app($pdo, grooflow_nomina_find($pdo, $casoId));
}

function grooflow_nomina_liquidar(PDO $pdo, int $lineaId, array $in): array
{
    grooflow_nomina_ensure_schema($pdo);
    grooflow_nomina_require($pdo, 'editar');
    $fecha = trim((string) ($in['fecha'] ?? ''));
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    if (!$dt || $dt->format('Y-m-d') !== $fecha) {
        throw new GrooflowValidation(['fecha' => 'Indica la fecha de cese.']);
    }
    $periodo = $dt->format('Y-m');
    $nota = grooflow_nomina_str($in['nota'] ?? 'Liquidación de cese', 400);
    [, $userId, $userName] = grooflow_nomina_user();
    grooflow_atomic($pdo, static function () use ($pdo, $lineaId, $fecha, $periodo, $nota, $userId, $userName): void {
        $stmt = $pdo->prepare('SELECT * FROM grooflow_nomina_lineas WHERE id = ? FOR UPDATE');
        $stmt->execute([$lineaId]);
        $ln = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ln)) {
            throw new RuntimeException('Colaborador no encontrado');
        }
        $caso = grooflow_nomina_find($pdo, (int) $ln['caso_id'], true);
        if (!in_array((string) $caso['estado'], ['aprobado', 'en_planilla'], true)) {
            throw new InvalidArgumentException('Solo se liquida un descuento aprobado.');
        }
        $pend = $pdo->prepare('SELECT COALESCE(SUM(monto),0) FROM grooflow_nomina_cuotas WHERE linea_id = ? AND estado = \'pendiente\'');
        $pend->execute([$lineaId]);
        $saldo = round((float) $pend->fetchColumn(), 2);
        if ($saldo <= 0) {
            $count = $pdo->prepare('SELECT COUNT(*) FROM grooflow_nomina_cuotas WHERE linea_id = ?');
            $count->execute([$lineaId]);
            if ((int) $count->fetchColumn() === 0) {
                $saldo = round((float) $ln['monto_total'], 2);
            }
        }
        if ($saldo <= 0) {
            throw new InvalidArgumentException('No queda saldo por liquidar.');
        }
        $pdo->prepare('UPDATE grooflow_nomina_cuotas SET estado = \'absorbida\' WHERE linea_id = ? AND estado = \'pendiente\'')->execute([$lineaId]);
        $nro = (int) $pdo->query('SELECT COALESCE(MAX(nro),0)+1 FROM grooflow_nomina_cuotas WHERE linea_id = ' . (int) $lineaId)->fetchColumn();
        $pdo->prepare('
            INSERT INTO grooflow_nomina_cuotas
                (linea_id, nro, periodo, monto, estado, marcada_at, marcada_by, marcada_by_nombre, nota)
            VALUES (?,?,?,?,\'liquidada\',NOW(),?,?,?)
        ')->execute([$lineaId, $nro, $periodo, $saldo, $userId ?: null, mb_substr($userName, 0, 190), $nota]);
        $pdo->prepare('UPDATE grooflow_nomina_lineas SET estado = \'liquidado\', liquidacion_fecha = ?, liquidacion_nota = ? WHERE id = ?')
            ->execute([$fecha, $nota, $lineaId]);
        grooflow_nomina_refresh($pdo, (int) $ln['caso_id']);
    });
    $casoId = (int) $pdo->query('SELECT caso_id FROM grooflow_nomina_lineas WHERE id = ' . (int) $lineaId)->fetchColumn();

    return grooflow_nomina_row_to_app($pdo, grooflow_nomina_find($pdo, $casoId));
}

function grooflow_nomina_list(PDO $pdo, array $q): array
{
    grooflow_nomina_ensure_schema($pdo);
    grooflow_nomina_require($pdo, 'ver');
    $where = ['1=1'];
    $params = [];
    $estado = trim((string) ($q['estado'] ?? ''));
    if ($estado !== '') {
        $where[] = 'estado = ?';
        $params[] = $estado;
    } elseif (empty($q['todos'])) {
        $where[] = 'estado <> \'anulado\'';
    }
    $term = trim((string) ($q['q'] ?? ''));
    if ($term !== '') {
        $where[] = '(codigo LIKE ? OR concepto LIKE ? OR sede LIKE ? OR id IN (
            SELECT caso_id FROM grooflow_nomina_lineas WHERE nombre LIKE ? OR documento LIKE ?
        ))';
        $like = '%' . $term . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $sql = 'SELECT * FROM grooflow_nomina_casos WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 400';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = grooflow_nomina_row_to_app($pdo, $row);
    }

    return $items;
}

function grooflow_nomina_stats(PDO $pdo): array
{
    grooflow_nomina_ensure_schema($pdo);
    grooflow_nomina_require($pdo, 'ver');
    $periodo = date('Y-m');
    $sum = static function (PDO $pdo, string $sql, array $params = []): float {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return round((float) $stmt->fetchColumn(), 2);
    };
    $base = '
        FROM grooflow_nomina_cuotas c
        JOIN grooflow_nomina_lineas l ON l.id = c.linea_id
        JOIN grooflow_nomina_casos k ON k.id = l.caso_id
        WHERE k.estado NOT IN (\'anulado\', \'borrador\')
    ';
    $este = $sum($pdo, 'SELECT COALESCE(SUM(c.monto),0) ' . $base . ' AND c.estado = \'pendiente\' AND c.periodo = ?', [$periodo]);
    $desc = $sum($pdo, 'SELECT COALESCE(SUM(c.monto),0) ' . $base . ' AND c.estado IN (\'descontada\', \'liquidada\') AND c.periodo = ?', [$periodo]);
    $pend = $sum($pdo, 'SELECT COALESCE(SUM(c.monto),0) ' . $base . ' AND c.estado = \'pendiente\'');
    $venc = $sum($pdo, 'SELECT COALESCE(SUM(c.monto),0) ' . $base . ' AND c.estado = \'pendiente\' AND c.periodo < ?', [$periodo]);
    $cnt = $pdo->prepare('SELECT COUNT(*) ' . $base . ' AND c.estado = \'pendiente\' AND c.periodo < ?');
    $cnt->execute([$periodo]);
    $vencN = (int) $cnt->fetchColumn();
    $activos = (int) $pdo->query("SELECT COUNT(*) FROM grooflow_nomina_casos WHERE estado IN ('aprobado','en_planilla','enviado')")->fetchColumn();
    $colab = (int) $pdo->query("
        SELECT COUNT(DISTINCT l.buk_id, l.nombre) FROM grooflow_nomina_lineas l
        JOIN grooflow_nomina_casos k ON k.id = l.caso_id
        WHERE k.estado IN ('aprobado','en_planilla') AND l.estado <> 'cerrado'
    ")->fetchColumn();
    $porSede = [];
    $sedeStmt = $pdo->query("
        SELECT COALESCE(NULLIF(TRIM(l.sede), ''), NULLIF(TRIM(k.sede), ''), 'Sin sede') sede,
               COALESCE(SUM(CASE WHEN c.estado = 'pendiente' THEN c.monto ELSE 0 END),0) pendiente,
               COALESCE(SUM(CASE WHEN c.estado IN ('descontada','liquidada') THEN c.monto ELSE 0 END),0) descontado
        FROM grooflow_nomina_cuotas c
        JOIN grooflow_nomina_lineas l ON l.id = c.linea_id
        JOIN grooflow_nomina_casos k ON k.id = l.caso_id
        WHERE k.estado NOT IN ('anulado','borrador')
        GROUP BY sede
        ORDER BY pendiente DESC
        LIMIT 12
    ");
    while ($r = $sedeStmt->fetch(PDO::FETCH_ASSOC)) {
        $porSede[] = [
            'sede' => (string) $r['sede'],
            'pendiente' => (float) $r['pendiente'],
            'descontado' => (float) $r['descontado'],
        ];
    }
    $porConcepto = [];
    $conStmt = $pdo->query("
        SELECT k.concepto,
               COALESCE(SUM(l.monto_total),0) monto,
               COALESCE((
                   SELECT SUM(c.monto)
                   FROM grooflow_nomina_cuotas c
                   JOIN grooflow_nomina_lineas l2 ON l2.id = c.linea_id
                   JOIN grooflow_nomina_casos k2 ON k2.id = l2.caso_id
                   WHERE k2.concepto = k.concepto
                     AND k2.estado NOT IN ('anulado','borrador')
                     AND c.estado = 'pendiente'
               ),0) pendiente
        FROM grooflow_nomina_casos k
        JOIN grooflow_nomina_lineas l ON l.caso_id = k.id
        WHERE k.estado NOT IN ('anulado','borrador')
        GROUP BY k.concepto
        ORDER BY monto DESC
    ");
    while ($r = $conStmt->fetch(PDO::FETCH_ASSOC)) {
        $porConcepto[] = [
            'concepto' => (string) $r['concepto'],
            'monto' => (float) $r['monto'],
            'pendiente' => (float) $r['pendiente'],
        ];
    }

    return [
        'periodo' => $periodo,
        'estePeriodo' => $este,
        'descontadoMes' => $desc,
        'pendiente' => $pend,
        'vencidas' => $venc,
        'vencidasCount' => $vencN,
        'casosActivos' => $activos,
        'colaboradores' => $colab,
        'porSede' => $porSede,
        'porConcepto' => $porConcepto,
    ];
}

function grooflow_nomina_from_extorno(PDO $pdo, int $extornoId): void
{
    grooflow_nomina_ensure_schema($pdo);
    $exists = $pdo->prepare('SELECT id FROM grooflow_nomina_casos WHERE extorno_id = ?');
    $exists->execute([$extornoId]);
    if ($exists->fetchColumn()) {
        return;
    }
    $stmt = $pdo->prepare('SELECT * FROM grooflow_extornos WHERE id = ?');
    $stmt->execute([$extornoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return;
    }
    $extras = [];
    if (is_string($row['campos_extra'] ?? null) && $row['campos_extra'] !== '') {
        $decoded = json_decode((string) $row['campos_extra'], true);
        if (is_array($decoded)) {
            $extras = $decoded;
        }
    }
    $tpl = function_exists('grooflow_extornos_template')
        ? grooflow_extornos_template($pdo, isset($row['plantilla_id']) ? (int) $row['plantilla_id'] : null)
        : ['fields' => []];
    $aplicaKey = null;
    $montoKey = null;
    $detalleKey = null;
    foreach ($tpl['fields'] as $f) {
        if (empty($f['enabled']) || !is_array($f)) {
            continue;
        }
        $key = (string) ($f['key'] ?? '');
        $fold = mb_strtolower((string) ($f['label'] ?? ''));
        if ($aplicaKey === null && str_contains($fold, 'aplica') && (str_contains($fold, 'descuento') || str_contains($fold, 'nómina') || str_contains($fold, 'nomina'))) {
            $aplicaKey = $key;
        }
        if ($montoKey === null && str_contains($fold, 'monto') && str_contains($fold, 'descuento')) {
            $montoKey = $key;
        }
        if ($detalleKey === null && $aplicaKey !== $key && (str_contains($fold, 'nombres') || str_contains($fold, 'a descontar'))) {
            $detalleKey = $key;
        }
    }
    $aplica = mb_strtolower(trim((string) ($aplicaKey ? ($extras[$aplicaKey] ?? '') : '')));
    $yes = in_array($aplica, ['si', 'sí', 's', 'yes', 'aplica', '1'], true) || str_starts_with($aplica, 'si');
    if (!$yes) {
        return;
    }
    $rawDet = (string) ($detalleKey ? ($extras[$detalleKey] ?? '') : '');
    $plan = json_decode($rawDet, true);
    $srcLines = is_array($plan) && is_array($plan['lines'] ?? null) ? $plan['lines'] : [];
    $lineas = [];
    foreach ($srcLines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $nombre = trim((string) ($ln['nombre'] ?? ''));
        $monto = round((float) ($ln['monto'] ?? 0), 2);
        $cuotas = max(1, (int) ($ln['cuotas'] ?? 1));
        if ($nombre === '' || $monto <= 0) {
            continue;
        }
        $lineas[] = [
            'bukId' => (string) ($ln['bukId'] ?? ''),
            'nombre' => $nombre,
            'monto' => $monto,
            'cuotas' => $cuotas,
            'sede' => (string) ($row['sede'] ?? ''),
        ];
    }
    if ($lineas === []) {
        $montoCampo = round((float) ($montoKey ? ($extras[$montoKey] ?? 0) : 0), 2);
        if ($montoCampo <= 0) {
            $montoCampo = round((float) ($row['monto'] ?? 0), 2);
        }
        if ($rawDet !== '' && $montoCampo > 0) {
            $lineas[] = [
                'nombre' => mb_substr($rawDet, 0, 190),
                'monto' => $montoCampo,
                'cuotas' => 1,
                'sede' => (string) ($row['sede'] ?? ''),
            ];
        }
    }
    if ($lineas === []) {
        return;
    }
    $userName = (string) ($row['firma_auditor_by_nombre'] ?? $row['revisado_by_nombre'] ?? 'Auditoría');
    grooflow_atomic($pdo, static function () use ($pdo, $extornoId, $row, $lineas, $userName): void {
        $again = $pdo->prepare('SELECT id FROM grooflow_nomina_casos WHERE extorno_id = ? FOR UPDATE');
        $again->execute([$extornoId]);
        if ($again->fetchColumn()) {
            return;
        }
        $codigo = grooflow_nomina_next_codigo($pdo);
        $obs = trim((string) ($row['motivo'] ?? ''));
        $boleta = trim((string) ($row['boleta'] ?? ''));
        $pdo->prepare('
            INSERT INTO grooflow_nomina_casos
                (codigo, estado, origen, extorno_id, concepto, observaciones, boleta_ref, sede, firma_autorizada, firma_por, created_by_nombre)
            VALUES (?,\'aprobado\',\'extorno\',?,\'Extorno\',?,?,?,1,?,?)
        ')->execute([
            $codigo,
            $extornoId,
            $obs !== '' ? mb_substr($obs, 0, 4000) : null,
            $boleta !== '' ? mb_substr($boleta, 0, 160) : null,
            grooflow_nomina_str($row['sede'] ?? null, 120),
            mb_substr($userName, 0, 190),
            mb_substr($userName, 0, 190),
        ]);
        $casoId = (int) $pdo->lastInsertId();
        grooflow_nomina_insert_lineas($pdo, $casoId, $lineas, false);
    });
}

function grooflow_nomina_anular_por_extorno(PDO $pdo, int $extornoId): void
{
    grooflow_nomina_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT id FROM grooflow_nomina_casos WHERE extorno_id = ?');
    $stmt->execute([$extornoId]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    if ($id <= 0) {
        return;
    }
    $marked = $pdo->prepare('
        SELECT COUNT(*) FROM grooflow_nomina_cuotas c
        JOIN grooflow_nomina_lineas l ON l.id = c.linea_id
        WHERE l.caso_id = ? AND c.estado IN (\'descontada\', \'liquidada\')
    ');
    $marked->execute([$id]);
    if ((int) $marked->fetchColumn() > 0) {
        return;
    }
    $pdo->prepare('UPDATE grooflow_nomina_casos SET estado = \'anulado\' WHERE id = ?')->execute([$id]);
}

function grooflow_nomina_dispatch(PDO $pdo, string $path, string $method): bool
{
    if ($path !== '/nomina-descuentos' && !str_starts_with($path, '/nomina-descuentos/')) {
        return false;
    }
    try {
        if ($path === '/nomina-descuentos/capabilities' && $method === 'GET') {
            api_json_response(['ok' => true] + grooflow_nomina_capabilities($pdo));

            return true;
        }
        if ($path === '/nomina-descuentos/stats' && $method === 'GET') {
            api_json_response(['ok' => true, 'stats' => grooflow_nomina_stats($pdo)]);

            return true;
        }
        if ($path === '/nomina-descuentos' && $method === 'GET') {
            api_json_response(['ok' => true, 'items' => grooflow_nomina_list($pdo, $_GET)]);

            return true;
        }
        if ($path === '/nomina-descuentos' && $method === 'POST') {
            api_json_response(['ok' => true, 'item' => grooflow_nomina_create($pdo, api_request_json())]);

            return true;
        }
        if (preg_match('#^/nomina-descuentos/(\d+)$#', $path, $m) && $method === 'GET') {
            grooflow_nomina_ensure_schema($pdo);
            grooflow_nomina_require($pdo, 'ver');
            api_json_response(['ok' => true, 'item' => grooflow_nomina_row_to_app($pdo, grooflow_nomina_find($pdo, (int) $m[1]))]);

            return true;
        }
        if (preg_match('#^/nomina-descuentos/(\d+)$#', $path, $m) && $method === 'PUT') {
            api_json_response(['ok' => true, 'item' => grooflow_nomina_update($pdo, (int) $m[1], api_request_json())]);

            return true;
        }
        if (preg_match('#^/nomina-descuentos/(\d+)$#', $path, $m) && $method === 'DELETE') {
            grooflow_nomina_delete($pdo, (int) $m[1]);
            api_json_response(['ok' => true]);

            return true;
        }
        if (preg_match('#^/nomina-descuentos/(\d+)/transition$#', $path, $m) && $method === 'POST') {
            api_json_response(['ok' => true, 'item' => grooflow_nomina_transition($pdo, (int) $m[1], api_request_json())]);

            return true;
        }
        if (preg_match('#^/nomina-descuentos/lineas/(\d+)/programar$#', $path, $m) && $method === 'POST') {
            api_json_response(['ok' => true, 'item' => grooflow_nomina_programar($pdo, (int) $m[1], api_request_json())]);

            return true;
        }
        if (preg_match('#^/nomina-descuentos/lineas/(\d+)/liquidar$#', $path, $m) && $method === 'POST') {
            api_json_response(['ok' => true, 'item' => grooflow_nomina_liquidar($pdo, (int) $m[1], api_request_json())]);

            return true;
        }
        if (preg_match('#^/nomina-descuentos/cuotas/(\d+)/marcar$#', $path, $m) && $method === 'POST') {
            api_json_response(['ok' => true, 'item' => grooflow_nomina_marcar($pdo, (int) $m[1], api_request_json())]);

            return true;
        }
        api_json_response(['ok' => false, 'error' => 'Ruta de Descuentos de nómina no encontrada'], 404);
    } catch (GrooflowValidation $e) {
        api_json_response(['ok' => false, 'error' => 'Validación', 'errors' => $e->errors], 422);
    } catch (PDOException $e) {
        api_json_response(['ok' => false, 'error' => 'Error de base de datos en Descuentos de nómina'], 500);
    } catch (InvalidArgumentException $e) {
        api_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    } catch (RuntimeException $e) {
        $code = str_contains(mb_strtolower($e->getMessage()), 'permiso') ? 403 : 404;
        api_json_response(['ok' => false, 'error' => $e->getMessage()], $code);
    } catch (Throwable $e) {
        api_json_response(['ok' => false, 'error' => $e->getMessage() ?: 'Error en Descuentos de nómina'], 500);
    }

    return true;
}
