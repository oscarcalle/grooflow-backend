<?php

declare(strict_types=1);

/**
 * Fases 3–8: reglas, gastos CC, motor de distribución, personal, reportes y feed P&L.
 * Solo migraciones aditivas. No muta ledger de caja/tx; registra gastos propios con origen opcional.
 */

function grooflow_cost_centers_ops_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_reglas_distribucion (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(40) NOT NULL,
            nombre VARCHAR(160) NOT NULL,
            metodo ENUM('PORCENTAJE','POR_VENTAS','POR_M2','POR_HEADCOUNT','IGUAL') NOT NULL DEFAULT 'PORCENTAJE',
            descripcion VARCHAR(255) NULL DEFAULT '',
            vigencia_desde DATE NULL,
            vigencia_hasta DATE NULL,
            estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_gf_regla_codigo (codigo),
            KEY idx_gf_regla_estado (estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_reglas_distribucion_detalle (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            regla_id INT UNSIGNED NOT NULL,
            centro_costo_id INT UNSIGNED NOT NULL,
            porcentaje DECIMAL(6,2) NULL,
            criterio VARCHAR(120) NULL DEFAULT '',
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_gf_regla_det_regla (regla_id),
            KEY idx_gf_regla_det_cc (centro_costo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_gastos_cc (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            fecha DATE NOT NULL,
            monto DECIMAL(14,2) NOT NULL,
            moneda VARCHAR(8) NOT NULL DEFAULT 'PEN',
            concepto VARCHAR(255) NOT NULL DEFAULT '',
            sede_nombre VARCHAR(120) NULL,
            origen_tipo ENUM('manual','caja','transaccion','factura','personal') NOT NULL DEFAULT 'manual',
            origen_id VARCHAR(80) NULL,
            centro_costo_origen_id INT UNSIGNED NULL,
            tipo_asignacion ENUM('DIRECTO','REGLA','PERSONAL','SIN_ASIGNAR') NOT NULL DEFAULT 'SIN_ASIGNAR',
            regla_id INT UNSIGNED NULL,
            colaborador_id VARCHAR(64) NULL,
            periodo VARCHAR(7) NOT NULL,
            estado ENUM('pendiente','distribuido','anulado') NOT NULL DEFAULT 'pendiente',
            notas VARCHAR(255) NULL DEFAULT '',
            created_by VARCHAR(80) NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_gf_gasto_fecha (fecha),
            KEY idx_gf_gasto_periodo (periodo),
            KEY idx_gf_gasto_estado (estado),
            KEY idx_gf_gasto_colab (colaborador_id),
            KEY idx_gf_gasto_regla (regla_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_gasto_distribucion (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            gasto_id INT UNSIGNED NOT NULL,
            centro_costo_id INT UNSIGNED NOT NULL,
            porcentaje DECIMAL(6,2) NOT NULL,
            monto DECIMAL(14,2) NOT NULL,
            regla_id INT UNSIGNED NULL,
            metodo_snapshot VARCHAR(40) NOT NULL DEFAULT 'PORCENTAJE',
            colaborador_id VARCHAR(64) NULL,
            batch_id VARCHAR(40) NOT NULL,
            is_reversed TINYINT(1) NOT NULL DEFAULT 0,
            reversed_at DATETIME NULL,
            created_by VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_gf_gdist_gasto (gasto_id),
            KEY idx_gf_gdist_cc (centro_costo_id),
            KEY idx_gf_gdist_batch (batch_id),
            KEY idx_gf_gdist_rev (is_reversed)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function grooflow_cc_periodo_from_fecha(string $fecha): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $fecha, $m)) {
        throw new InvalidArgumentException('fecha inválida (YYYY-MM-DD)');
    }

    return $m[1] . '-' . $m[2];
}

/** @return list<array<string,mixed>> */
function grooflow_reglas_list(PDO $pdo, bool $onlyActive = true): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    grooflow_cost_centers_ops_ensure_schema($pdo);
    $sql = 'SELECT * FROM grooflow_reglas_distribucion WHERE is_deleted=0';
    if ($onlyActive) {
        $sql .= " AND estado='activo'";
    }
    $sql .= ' ORDER BY codigo ASC';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = grooflow_regla_hydrate($pdo, $r);
    }

    return $out;
}

/** @param array<string,mixed> $row */
function grooflow_regla_hydrate(PDO $pdo, array $row): array
{
    $id = (int) $row['id'];
    $st = $pdo->prepare('
        SELECT d.*, cc.codigo AS centro_codigo, cc.nombre AS centro_nombre
        FROM grooflow_reglas_distribucion_detalle d
        LEFT JOIN grooflow_centros_costo cc ON cc.id = d.centro_costo_id
        WHERE d.regla_id=? AND d.is_deleted=0
        ORDER BY d.sort_order ASC, d.id ASC
    ');
    $st->execute([$id]);
    $row['detalle'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return $row;
}

function grooflow_regla_get(PDO $pdo, int $id): ?array
{
    grooflow_cost_centers_ensure_schema($pdo);
    grooflow_cost_centers_ops_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM grooflow_reglas_distribucion WHERE id=? AND is_deleted=0 LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return grooflow_regla_hydrate($pdo, $row);
}

/**
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function grooflow_regla_save(PDO $pdo, array $data, ?int $id = null): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    grooflow_cost_centers_ops_ensure_schema($pdo);
    $codigo = strtoupper(trim((string) ($data['codigo'] ?? '')));
    $nombre = trim((string) ($data['nombre'] ?? ''));
    if ($codigo === '' || $nombre === '') {
        throw new InvalidArgumentException('codigo y nombre obligatorios');
    }
    $metodo = (string) ($data['metodo'] ?? 'PORCENTAJE');
    $allowed = ['PORCENTAJE', 'POR_VENTAS', 'POR_M2', 'POR_HEADCOUNT', 'IGUAL'];
    if (!in_array($metodo, $allowed, true)) {
        throw new InvalidArgumentException('metodo inválido');
    }
    $detalle = $data['detalle'] ?? [];
    if (!is_array($detalle) || $detalle === []) {
        throw new InvalidArgumentException('La regla requiere al menos un centro en detalle');
    }

    $lines = [];
    $sum = 0.0;
    $seen = [];
    foreach ($detalle as $i => $d) {
        if (!is_array($d)) {
            continue;
        }
        $ccId = (int) ($d['centro_costo_id'] ?? 0);
        if ($ccId <= 0 || isset($seen[$ccId])) {
            throw new InvalidArgumentException('detalle: centro_costo_id inválido o duplicado');
        }
        $seen[$ccId] = true;
        $chk = $pdo->prepare('SELECT id FROM grooflow_centros_costo WHERE id=? AND is_deleted=0 LIMIT 1');
        $chk->execute([$ccId]);
        if (!$chk->fetchColumn()) {
            throw new InvalidArgumentException("Centro {$ccId} no existe");
        }
        $pct = isset($d['porcentaje']) && $d['porcentaje'] !== '' && $d['porcentaje'] !== null
            ? round((float) $d['porcentaje'], 2)
            : null;
        if ($metodo === 'PORCENTAJE') {
            if ($pct === null || $pct <= 0) {
                throw new InvalidArgumentException('PORCENTAJE requiere porcentaje > 0 en cada línea');
            }
            $sum += $pct;
        } elseif ($metodo === 'IGUAL') {
            $pct = null;
        }
        $lines[] = [
            'centro_costo_id' => $ccId,
            'porcentaje' => $pct,
            'criterio' => trim((string) ($d['criterio'] ?? '')),
            'sort_order' => (int) ($d['sort_order'] ?? $i),
        ];
    }
    if ($lines === []) {
        throw new InvalidArgumentException('detalle vacío');
    }
    if ($metodo === 'PORCENTAJE' && abs($sum - 100.0) > 0.02) {
        throw new InvalidArgumentException('La suma de porcentajes de la regla debe ser 100% (actual: ' . round($sum, 2) . '%)');
    }
    if ($metodo === 'IGUAL') {
        $n = count($lines);
        $each = round(100 / $n, 2);
        $acc = 0.0;
        foreach ($lines as $i => &$ln) {
            $ln['porcentaje'] = $i === $n - 1 ? round(100 - $acc, 2) : $each;
            $acc += (float) $ln['porcentaje'];
        }
        unset($ln);
    }
    // Stubs POR_VENTAS / POR_M2 / POR_HEADCOUNT: si traen %, validar 100; si no, repartir igual.
    if (in_array($metodo, ['POR_VENTAS', 'POR_M2', 'POR_HEADCOUNT'], true)) {
        $hasPct = true;
        $sum2 = 0.0;
        foreach ($lines as $ln) {
            if ($ln['porcentaje'] === null) {
                $hasPct = false;
                break;
            }
            $sum2 += (float) $ln['porcentaje'];
        }
        if (!$hasPct) {
            $n = count($lines);
            $each = round(100 / $n, 2);
            $acc = 0.0;
            foreach ($lines as $i => &$ln) {
                $ln['porcentaje'] = $i === $n - 1 ? round(100 - $acc, 2) : $each;
                $acc += (float) $ln['porcentaje'];
            }
            unset($ln);
        } elseif (abs($sum2 - 100.0) > 0.02) {
            throw new InvalidArgumentException('Si se indican porcentajes, deben sumar 100%');
        }
    }

    $estado = (string) ($data['estado'] ?? 'activo');
    if (!in_array($estado, ['activo', 'inactivo'], true)) {
        $estado = 'activo';
    }
    $descripcion = trim((string) ($data['descripcion'] ?? ''));
    $vd = trim((string) ($data['vigencia_desde'] ?? ''));
    $vh = trim((string) ($data['vigencia_hasta'] ?? ''));
    $vd = $vd !== '' ? $vd : null;
    $vh = $vh !== '' ? $vh : null;

    $pdo->beginTransaction();
    try {
        if ($id) {
            $pdo->prepare('
                UPDATE grooflow_reglas_distribucion
                SET codigo=?, nombre=?, metodo=?, descripcion=?, vigencia_desde=?, vigencia_hasta=?, estado=?
                WHERE id=? AND is_deleted=0
            ')->execute([$codigo, $nombre, $metodo, $descripcion ?: null, $vd, $vh, $estado, $id]);
            $pdo->prepare('UPDATE grooflow_reglas_distribucion_detalle SET is_deleted=1 WHERE regla_id=?')->execute([$id]);
        } else {
            $pdo->prepare('
                INSERT INTO grooflow_reglas_distribucion
                    (codigo, nombre, metodo, descripcion, vigencia_desde, vigencia_hasta, estado)
                VALUES (?,?,?,?,?,?,?)
            ')->execute([$codigo, $nombre, $metodo, $descripcion ?: null, $vd, $vh, $estado]);
            $id = (int) $pdo->lastInsertId();
        }
        $ins = $pdo->prepare('
            INSERT INTO grooflow_reglas_distribucion_detalle
                (regla_id, centro_costo_id, porcentaje, criterio, sort_order)
            VALUES (?,?,?,?,?)
        ');
        foreach ($lines as $ln) {
            $ins->execute([
                $id,
                $ln['centro_costo_id'],
                $ln['porcentaje'],
                $ln['criterio'] !== '' ? $ln['criterio'] : null,
                $ln['sort_order'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $item = grooflow_regla_get($pdo, $id);
    if (!$item) {
        throw new RuntimeException('No se pudo releer la regla');
    }

    return $item;
}

function grooflow_regla_delete(PDO $pdo, int $id): void
{
    grooflow_cost_centers_ops_ensure_schema($pdo);
    $pdo->prepare("UPDATE grooflow_reglas_distribucion SET is_deleted=1, estado='inactivo' WHERE id=?")->execute([$id]);
}

/**
 * Simula distribución sin persistir.
 *
 * @return array{regla_id:int,metodo:string,monto:float,lines:list<array<string,mixed>>,nota?:string}
 */
function grooflow_regla_simulate(PDO $pdo, int $reglaId, float $monto): array
{
    $regla = grooflow_regla_get($pdo, $reglaId);
    if (!$regla) {
        throw new InvalidArgumentException('Regla no encontrada');
    }
    if ($monto < 0) {
        throw new InvalidArgumentException('monto inválido');
    }
    $metodo = (string) $regla['metodo'];
    $nota = null;
    if (in_array($metodo, ['POR_VENTAS', 'POR_M2', 'POR_HEADCOUNT'], true)) {
        $nota = "Método {$metodo}: usando porcentajes de plantilla (drivers en tiempo real aún no conectados).";
    }
    $lines = [];
    $acc = 0.0;
    $det = $regla['detalle'];
    $n = count($det);
    foreach ($det as $i => $d) {
        $pct = round((float) ($d['porcentaje'] ?? 0), 2);
        $amt = $i === $n - 1 ? round($monto - $acc, 2) : round($monto * $pct / 100, 2);
        $acc += $amt;
        $lines[] = [
            'centro_costo_id' => (int) $d['centro_costo_id'],
            'centro_codigo' => $d['centro_codigo'] ?? null,
            'centro_nombre' => $d['centro_nombre'] ?? null,
            'porcentaje' => $pct,
            'monto' => $amt,
        ];
    }

    return [
        'regla_id' => $reglaId,
        'metodo' => $metodo,
        'monto' => round($monto, 2),
        'lines' => $lines,
        'nota' => $nota,
    ];
}

/**
 * @param array<string,mixed> $params
 * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
 */
function grooflow_gastos_cc_page(PDO $pdo, array $params): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    grooflow_cost_centers_ops_ensure_schema($pdo);
    $page = max(1, (int) ($params['page'] ?? 1));
    $pageSize = min(100, max(5, (int) ($params['pageSize'] ?? 25)));
    $estado = trim((string) ($params['estado'] ?? ''));
    $periodo = trim((string) ($params['periodo'] ?? ''));
    $search = trim((string) ($params['search'] ?? ''));

    $where = ['g.is_deleted=0'];
    $bind = [];
    if ($estado !== '' && in_array($estado, ['pendiente', 'distribuido', 'anulado'], true)) {
        $where[] = 'g.estado=?';
        $bind[] = $estado;
    }
    if ($periodo !== '' && preg_match('/^\d{4}-\d{2}$/', $periodo)) {
        $where[] = 'g.periodo=?';
        $bind[] = $periodo;
    }
    if ($search !== '') {
        $where[] = '(g.concepto LIKE ? OR g.sede_nombre LIKE ? OR g.origen_id LIKE ? OR g.colaborador_id LIKE ?)';
        $like = '%' . $search . '%';
        array_push($bind, $like, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);
    $countSt = $pdo->prepare("SELECT COUNT(*) FROM grooflow_gastos_cc g WHERE {$whereSql}");
    $countSt->execute($bind);
    $total = (int) $countSt->fetchColumn();
    $offset = ($page - 1) * $pageSize;
    $st = $pdo->prepare("
        SELECT g.*,
               cc.codigo AS centro_origen_codigo, cc.nombre AS centro_origen_nombre,
               r.codigo AS regla_codigo, r.nombre AS regla_nombre
        FROM grooflow_gastos_cc g
        LEFT JOIN grooflow_centros_costo cc ON cc.id = g.centro_costo_origen_id
        LEFT JOIN grooflow_reglas_distribucion r ON r.id = g.regla_id
        WHERE {$whereSql}
        ORDER BY g.fecha DESC, g.id DESC
        LIMIT {$pageSize} OFFSET {$offset}
    ");
    $st->execute($bind);

    return [
        'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
    ];
}

/**
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function grooflow_gastos_cc_save(PDO $pdo, array $data, ?int $id = null): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    grooflow_cost_centers_ops_ensure_schema($pdo);
    $fecha = trim((string) ($data['fecha'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        throw new InvalidArgumentException('fecha obligatoria (YYYY-MM-DD)');
    }
    $monto = round((float) ($data['monto'] ?? 0), 2);
    if ($monto <= 0) {
        throw new InvalidArgumentException('monto debe ser > 0');
    }
    $concepto = trim((string) ($data['concepto'] ?? ''));
    if ($concepto === '') {
        throw new InvalidArgumentException('concepto obligatorio');
    }
    $tipo = (string) ($data['tipo_asignacion'] ?? 'SIN_ASIGNAR');
    $allowedTipo = ['DIRECTO', 'REGLA', 'PERSONAL', 'SIN_ASIGNAR'];
    if (!in_array($tipo, $allowedTipo, true)) {
        throw new InvalidArgumentException('tipo_asignacion inválido');
    }
    $origenTipo = (string) ($data['origen_tipo'] ?? 'manual');
    $allowedOrigen = ['manual', 'caja', 'transaccion', 'factura', 'personal'];
    if (!in_array($origenTipo, $allowedOrigen, true)) {
        $origenTipo = 'manual';
    }
    $ccOrigen = isset($data['centro_costo_origen_id']) && $data['centro_costo_origen_id'] !== '' && $data['centro_costo_origen_id'] !== null
        ? (int) $data['centro_costo_origen_id']
        : null;
    $reglaId = isset($data['regla_id']) && $data['regla_id'] !== '' && $data['regla_id'] !== null
        ? (int) $data['regla_id']
        : null;
    $colab = trim((string) ($data['colaborador_id'] ?? ''));
    $colab = $colab !== '' ? grooflow_ccc_normalize_colaborador_id($colab) : null;

    if ($tipo === 'DIRECTO' && !$ccOrigen) {
        throw new InvalidArgumentException('DIRECTO requiere centro_costo_origen_id');
    }
    if ($tipo === 'REGLA' && !$reglaId) {
        throw new InvalidArgumentException('REGLA requiere regla_id');
    }
    if ($tipo === 'PERSONAL' && !$colab) {
        throw new InvalidArgumentException('PERSONAL requiere colaborador_id');
    }
    if ($reglaId) {
        $r = grooflow_regla_get($pdo, $reglaId);
        if (!$r) {
            throw new InvalidArgumentException('regla_id no existe');
        }
    }
    if ($ccOrigen) {
        $chk = $pdo->prepare('SELECT id FROM grooflow_centros_costo WHERE id=? AND is_deleted=0 LIMIT 1');
        $chk->execute([$ccOrigen]);
        if (!$chk->fetchColumn()) {
            throw new InvalidArgumentException('centro_costo_origen_id no existe');
        }
    }

    $periodo = grooflow_cc_periodo_from_fecha($fecha);
    $sede = trim((string) ($data['sede_nombre'] ?? '')) ?: null;
    $origenId = trim((string) ($data['origen_id'] ?? '')) ?: null;
    $notas = trim((string) ($data['notas'] ?? '')) ?: null;
    $createdBy = trim((string) ($data['created_by'] ?? '')) ?: null;
    $moneda = trim((string) ($data['moneda'] ?? 'PEN')) ?: 'PEN';

    if ($id) {
        $cur = $pdo->prepare('SELECT estado FROM grooflow_gastos_cc WHERE id=? AND is_deleted=0 LIMIT 1');
        $cur->execute([$id]);
        $est = $cur->fetchColumn();
        if ($est === false) {
            throw new InvalidArgumentException('Gasto no encontrado');
        }
        if ((string) $est === 'distribuido') {
            throw new InvalidArgumentException('No se puede editar un gasto ya distribuido (anule la distribución primero)');
        }
        $pdo->prepare('
            UPDATE grooflow_gastos_cc SET
                fecha=?, monto=?, moneda=?, concepto=?, sede_nombre=?, origen_tipo=?, origen_id=?,
                centro_costo_origen_id=?, tipo_asignacion=?, regla_id=?, colaborador_id=?, periodo=?, notas=?
            WHERE id=? AND is_deleted=0
        ')->execute([
            $fecha, $monto, $moneda, $concepto, $sede, $origenTipo, $origenId,
            $ccOrigen, $tipo, $reglaId, $colab, $periodo, $notas, $id,
        ]);
    } else {
        $pdo->prepare('
            INSERT INTO grooflow_gastos_cc
                (fecha, monto, moneda, concepto, sede_nombre, origen_tipo, origen_id,
                 centro_costo_origen_id, tipo_asignacion, regla_id, colaborador_id, periodo, estado, notas, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,"pendiente",?,?)
        ')->execute([
            $fecha, $monto, $moneda, $concepto, $sede, $origenTipo, $origenId,
            $ccOrigen, $tipo, $reglaId, $colab, $periodo, $notas, $createdBy,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    $item = grooflow_gastos_cc_get($pdo, $id);
    if (!$item) {
        throw new RuntimeException('No se pudo releer el gasto');
    }

    return $item;
}

function grooflow_gastos_cc_get(PDO $pdo, int $id): ?array
{
    grooflow_cost_centers_ops_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT g.*,
               cc.codigo AS centro_origen_codigo, cc.nombre AS centro_origen_nombre,
               r.codigo AS regla_codigo, r.nombre AS regla_nombre
        FROM grooflow_gastos_cc g
        LEFT JOIN grooflow_centros_costo cc ON cc.id = g.centro_costo_origen_id
        LEFT JOIN grooflow_reglas_distribucion r ON r.id = g.regla_id
        WHERE g.id=? AND g.is_deleted=0 LIMIT 1
    ');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $dst = $pdo->prepare('
        SELECT d.*, cc.codigo AS centro_codigo, cc.nombre AS centro_nombre
        FROM grooflow_gasto_distribucion d
        LEFT JOIN grooflow_centros_costo cc ON cc.id = d.centro_costo_id
        WHERE d.gasto_id=? AND d.is_reversed=0
        ORDER BY d.id ASC
    ');
    $dst->execute([$id]);
    $row['distribucion'] = $dst->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return $row;
}

function grooflow_gastos_cc_delete(PDO $pdo, int $id): void
{
    grooflow_cost_centers_ops_ensure_schema($pdo);
    $cur = $pdo->prepare('SELECT estado FROM grooflow_gastos_cc WHERE id=? AND is_deleted=0 LIMIT 1');
    $cur->execute([$id]);
    $est = (string) $cur->fetchColumn();
    if ($est === 'distribuido') {
        throw new InvalidArgumentException('Anule la distribución antes de desactivar el gasto');
    }
    $pdo->prepare("UPDATE grooflow_gastos_cc SET is_deleted=1, estado='anulado' WHERE id=?")->execute([$id]);
}

/**
 * Aplica distribución y deja snapshot inmutable.
 *
 * @return array<string,mixed>
 */
function grooflow_gasto_distribute(PDO $pdo, int $gastoId, ?string $createdBy = null): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    grooflow_cost_centers_ops_ensure_schema($pdo);
    $gasto = grooflow_gastos_cc_get($pdo, $gastoId);
    if (!$gasto) {
        throw new InvalidArgumentException('Gasto no encontrado');
    }
    if ((string) $gasto['estado'] === 'anulado') {
        throw new InvalidArgumentException('Gasto anulado');
    }
    if ((string) $gasto['estado'] === 'distribuido' && !empty($gasto['distribucion'])) {
        throw new InvalidArgumentException('Ya está distribuido; anule primero para redistribuir');
    }

    $tipo = (string) $gasto['tipo_asignacion'];
    $monto = (float) $gasto['monto'];
    $lines = [];
    $metodo = 'DIRECTO';
    $reglaId = null;

    if ($tipo === 'DIRECTO') {
        $ccId = (int) ($gasto['centro_costo_origen_id'] ?? 0);
        if ($ccId <= 0) {
            throw new InvalidArgumentException('Falta centro origen');
        }
        $lines[] = ['centro_costo_id' => $ccId, 'porcentaje' => 100.0, 'monto' => $monto];
        $metodo = 'DIRECTO';
    } elseif ($tipo === 'REGLA') {
        $reglaId = (int) ($gasto['regla_id'] ?? 0);
        $sim = grooflow_regla_simulate($pdo, $reglaId, $monto);
        $metodo = (string) $sim['metodo'];
        foreach ($sim['lines'] as $ln) {
            $lines[] = [
                'centro_costo_id' => (int) $ln['centro_costo_id'],
                'porcentaje' => (float) $ln['porcentaje'],
                'monto' => (float) $ln['monto'],
            ];
        }
    } elseif ($tipo === 'PERSONAL') {
        $colab = (string) ($gasto['colaborador_id'] ?? '');
        $fecha = (string) $gasto['fecha'];
        $resolved = grooflow_ccc_resolve($pdo, $colab, $fecha);
        if (empty($resolved['completo']) || empty($resolved['lines'])) {
            throw new InvalidArgumentException('Colaborador sin asignación vigente al 100% en la fecha del gasto');
        }
        $metodo = 'PERSONAL';
        $acc = 0.0;
        $n = count($resolved['lines']);
        foreach ($resolved['lines'] as $i => $ln) {
            $pct = round((float) $ln['porcentaje'], 2);
            $amt = $i === $n - 1 ? round($monto - $acc, 2) : round($monto * $pct / 100, 2);
            $acc += $amt;
            $lines[] = [
                'centro_costo_id' => (int) $ln['centro_costo_id'],
                'porcentaje' => $pct,
                'monto' => $amt,
            ];
        }
    } else {
        throw new InvalidArgumentException('Defina tipo_asignacion (DIRECTO, REGLA o PERSONAL) antes de distribuir');
    }

    $batch = 'B' . date('YmdHis') . substr((string) mt_rand(1000, 9999), 0);
    $pdo->beginTransaction();
    try {
        // Soft-reverse any leftover active lines (safety).
        $pdo->prepare('UPDATE grooflow_gasto_distribucion SET is_reversed=1, reversed_at=NOW() WHERE gasto_id=? AND is_reversed=0')
            ->execute([$gastoId]);
        $ins = $pdo->prepare('
            INSERT INTO grooflow_gasto_distribucion
                (gasto_id, centro_costo_id, porcentaje, monto, regla_id, metodo_snapshot, colaborador_id, batch_id, created_by)
            VALUES (?,?,?,?,?,?,?,?,?)
        ');
        foreach ($lines as $ln) {
            $ins->execute([
                $gastoId,
                $ln['centro_costo_id'],
                $ln['porcentaje'],
                $ln['monto'],
                $reglaId,
                $metodo,
                $gasto['colaborador_id'] ?? null,
                $batch,
                $createdBy,
            ]);
        }
        $pdo->prepare("UPDATE grooflow_gastos_cc SET estado='distribuido' WHERE id=?")->execute([$gastoId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $out = grooflow_gastos_cc_get($pdo, $gastoId);
    if (!$out) {
        throw new RuntimeException('Error al releer gasto distribuido');
    }
    $out['batch_id'] = $batch;

    return $out;
}

function grooflow_gasto_reverse(PDO $pdo, int $gastoId): array
{
    grooflow_cost_centers_ops_ensure_schema($pdo);
    $gasto = grooflow_gastos_cc_get($pdo, $gastoId);
    if (!$gasto) {
        throw new InvalidArgumentException('Gasto no encontrado');
    }
    $pdo->prepare('UPDATE grooflow_gasto_distribucion SET is_reversed=1, reversed_at=NOW() WHERE gasto_id=? AND is_reversed=0')
        ->execute([$gastoId]);
    $pdo->prepare("UPDATE grooflow_gastos_cc SET estado='pendiente' WHERE id=? AND is_deleted=0")->execute([$gastoId]);

    $out = grooflow_gastos_cc_get($pdo, $gastoId);
    if (!$out) {
        throw new RuntimeException('Error al releer gasto');
    }

    return $out;
}

/**
 * Alta de gasto de personal + distribución inmediata según % vigente.
 *
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function grooflow_gasto_personal_create(PDO $pdo, array $data): array
{
    $data['tipo_asignacion'] = 'PERSONAL';
    $data['origen_tipo'] = 'personal';
    $item = grooflow_gastos_cc_save($pdo, $data, null);
    $createdBy = trim((string) ($data['created_by'] ?? '')) ?: null;

    return grooflow_gasto_distribute($pdo, (int) $item['id'], $createdBy);
}

/**
 * Reportes agregados (subset operativo de reportes 1–13 del brief).
 *
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function grooflow_cc_reports(PDO $pdo, array $params): array
{
    grooflow_cost_centers_ensure_schema($pdo);
    grooflow_cost_centers_ops_ensure_schema($pdo);
    $periodo = trim((string) ($params['periodo'] ?? date('Y-m')));
    if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) {
        throw new InvalidArgumentException('periodo inválido (YYYY-MM)');
    }

    $byCc = $pdo->prepare("
        SELECT cc.id, cc.codigo, cc.nombre, cc.tipo, cc.sede_nombre,
               ROUND(SUM(d.monto),2) AS total, COUNT(DISTINCT d.gasto_id) AS n_gastos
        FROM grooflow_gasto_distribucion d
        JOIN grooflow_gastos_cc g ON g.id = d.gasto_id AND g.is_deleted=0 AND g.estado='distribuido'
        JOIN grooflow_centros_costo cc ON cc.id = d.centro_costo_id
        WHERE d.is_reversed=0 AND g.periodo=?
        GROUP BY cc.id, cc.codigo, cc.nombre, cc.tipo, cc.sede_nombre
        ORDER BY total DESC
    ");
    $byCc->execute([$periodo]);

    $bySede = $pdo->prepare("
        SELECT COALESCE(cc.sede_nombre, '(Sin sede)') AS sede,
               ROUND(SUM(d.monto),2) AS total
        FROM grooflow_gasto_distribucion d
        JOIN grooflow_gastos_cc g ON g.id = d.gasto_id AND g.is_deleted=0 AND g.estado='distribuido'
        JOIN grooflow_centros_costo cc ON cc.id = d.centro_costo_id
        WHERE d.is_reversed=0 AND g.periodo=?
        GROUP BY COALESCE(cc.sede_nombre, '(Sin sede)')
        ORDER BY total DESC
    ");
    $bySede->execute([$periodo]);

    $byBu = $pdo->prepare("
        SELECT COALESCE(bu.nombre, '(Sin BU)') AS unidad_negocio,
               COALESCE(bu.codigo, '') AS codigo,
               ROUND(SUM(d.monto),2) AS total
        FROM grooflow_gasto_distribucion d
        JOIN grooflow_gastos_cc g ON g.id = d.gasto_id AND g.is_deleted=0 AND g.estado='distribuido'
        JOIN grooflow_centros_costo cc ON cc.id = d.centro_costo_id
        LEFT JOIN grooflow_unidades_negocio bu ON bu.id = cc.unidad_negocio_id
        WHERE d.is_reversed=0 AND g.periodo=?
        GROUP BY bu.id, bu.nombre, bu.codigo
        ORDER BY total DESC
    ");
    $byBu->execute([$periodo]);

    $byArea = $pdo->prepare("
        SELECT COALESCE(a.nombre, '(Sin área)') AS area,
               ROUND(SUM(d.monto),2) AS total
        FROM grooflow_gasto_distribucion d
        JOIN grooflow_gastos_cc g ON g.id = d.gasto_id AND g.is_deleted=0 AND g.estado='distribuido'
        JOIN grooflow_centros_costo cc ON cc.id = d.centro_costo_id
        LEFT JOIN grooflow_org_areas a ON a.id = cc.area_id
        WHERE d.is_reversed=0 AND g.periodo=?
        GROUP BY a.id, a.nombre
        ORDER BY total DESC
    ");
    $byArea->execute([$periodo]);

    $byTipo = $pdo->prepare("
        SELECT g.tipo_asignacion, ROUND(SUM(g.monto),2) AS total, COUNT(*) AS n
        FROM grooflow_gastos_cc g
        WHERE g.is_deleted=0 AND g.periodo=? AND g.estado='distribuido'
        GROUP BY g.tipo_asignacion
    ");
    $byTipo->execute([$periodo]);

    $pending = $pdo->prepare("
        SELECT COUNT(*) AS n, ROUND(COALESCE(SUM(monto),0),2) AS total
        FROM grooflow_gastos_cc
        WHERE is_deleted=0 AND estado='pendiente' AND periodo=?
    ");
    $pending->execute([$periodo]);
    $pendRow = $pending->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'total' => 0];

    $personal = $pdo->prepare("
        SELECT g.colaborador_id, ROUND(SUM(g.monto),2) AS total, COUNT(*) AS n
        FROM grooflow_gastos_cc g
        WHERE g.is_deleted=0 AND g.periodo=? AND g.tipo_asignacion='PERSONAL' AND g.estado='distribuido'
        GROUP BY g.colaborador_id
        ORDER BY total DESC
        LIMIT 50
    ");
    $personal->execute([$periodo]);

    return [
        'periodo' => $periodo,
        'por_centro' => $byCc->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'por_sede' => $bySede->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'por_unidad_negocio' => $byBu->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'por_area' => $byArea->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'por_tipo_asignacion' => $byTipo->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'pendientes' => [
            'n' => (int) $pendRow['n'],
            'total' => (float) $pendRow['total'],
        ],
        'personal' => $personal->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ];
}

/**
 * Feed clasificado para Estado de Resultados (dual con P&L legacy).
 *
 * @return array{periodo:string,items:list<array<string,mixed>>,total:float}
 */
function grooflow_cc_pnl_feed(PDO $pdo, string $periodo): array
{
    $reports = grooflow_cc_reports($pdo, ['periodo' => $periodo]);
    $items = [];
    $total = 0.0;
    foreach ($reports['por_centro'] as $row) {
        $amt = (float) $row['total'];
        $total += $amt;
        $items[] = [
            'centro_costo_id' => (int) $row['id'],
            'codigo' => $row['codigo'],
            'nombre' => $row['nombre'],
            'tipo' => $row['tipo'],
            'sede_nombre' => $row['sede_nombre'],
            'monto' => $amt,
            'naturaleza' => 'gasto',
        ];
    }

    return [
        'periodo' => $periodo,
        'items' => $items,
        'total' => round($total, 2),
        'nota' => 'Feed dimensional CC. El P&L por categorías financieras sigue disponible en paralelo.',
    ];
}
