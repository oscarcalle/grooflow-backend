<?php

declare(strict_types=1);

/**
 * Sincroniza fuentes operativas (caja chica, transacciones, compras, honorarios) con gastos por centro de costo.
 * Reconciliación idempotente: crea, re-crea si cambió, y anula lo que ya no aplica.
 * Se ejecuta tras cada guardado de la clave KV de cada fuente (fuera de la TX de escritura).
 */

require_once __DIR__ . '/grooflow_mgr_pnl.php';

/** Área operativa (texto libre de caja chica) → nombre base del centro por sede. */
const GROOFLOW_MGR_AREA_ALIASES = [
    'counter' => 'counter',
    'admision' => 'counter',
    'recepcion' => 'counter',
    'atencion al cliente' => 'counter',
    'mantenimiento' => 'mantenimiento',
    'medicina' => 'medicina',
    'medica' => 'medicina',
    'medico' => 'medicina',
    'veterinaria' => 'medicina',
    'peluqueria' => 'peluqueria',
    'grooming' => 'peluqueria',
    'petshop' => 'petshop',
    'pet shop' => 'petshop',
    'tienda' => 'petshop',
    'limpieza' => 'limpieza',
    'pet movil' => 'pet movil',
    'movilidad' => 'pet movil',
    'transporte' => 'pet movil',
    'administracion' => 'administracion de sede',
    'administracion de sede' => 'administracion de sede',
];

/** Áreas sin centro por sede → centro corporativo. */
const GROOFLOW_MGR_AREA_CORPORATE = [
    'logistica' => 'CC-LOGISTICA',
    'compras' => 'CC-COMPRAS',
    'marketing' => 'CC-MARKETING',
    'rrhh' => 'CC-RRHH',
    'recursos humanos' => 'CC-RRHH',
    'contabilidad' => 'CC-CONTABILIDAD',
    'finanzas' => 'CC-FINANZAS',
    'tecnologia' => 'CC-TECNOLOGIA',
    'sistemas' => 'CC-TECNOLOGIA',
    'auditoria' => 'CC-AUDITORIA',
    'gerencia' => 'CC-GERENCIA',
];

function grooflow_mgr_norm_text(?string $s): string
{
    $s = mb_strtolower(trim((string) $s));
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?? '';

    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
}

/** DDL: llamar fuera de transacción. */
function grooflow_mgr_area_map_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_mgr_area_cc (
            area_norm VARCHAR(120) NOT NULL PRIMARY KEY,
            area_label VARCHAR(160) NOT NULL,
            destino VARCHAR(10) NOT NULL DEFAULT 'sede',
            centro_base VARCHAR(160) NULL,
            centro_codigo VARCHAR(40) NULL,
            updated_by VARCHAR(80) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}

/**
 * Mapeo configurable área → centro (tiene prioridad sobre los alias fijos).
 *
 * @return array<string,array{area_label:string,destino:string,centro_base:?string,centro_codigo:?string}>
 */
function grooflow_mgr_area_map_load(PDO $pdo, bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }
    $cache = [];
    try {
        foreach ($pdo->query('SELECT area_norm, area_label, destino, centro_base, centro_codigo FROM grooflow_mgr_area_cc')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $cache[(string) $r['area_norm']] = $r;
        }
    } catch (Throwable) {
        // Tabla aún no creada
    }

    return $cache;
}

/**
 * @param list<array<string,mixed>> $items {area, destino: sede|fijo|auto, centro_base?, centro_codigo?}
 */
function grooflow_mgr_area_map_save(PDO $pdo, array $items, string $by): int
{
    grooflow_mgr_area_map_ensure($pdo);
    $n = 0;
    $del = $pdo->prepare('DELETE FROM grooflow_mgr_area_cc WHERE area_norm=?');
    $up = $pdo->prepare("
        INSERT INTO grooflow_mgr_area_cc (area_norm, area_label, destino, centro_base, centro_codigo, updated_by)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE area_label=VALUES(area_label), destino=VALUES(destino),
            centro_base=VALUES(centro_base), centro_codigo=VALUES(centro_codigo), updated_by=VALUES(updated_by)
    ");
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $label = trim((string) ($it['area'] ?? ''));
        $norm = grooflow_mgr_norm_text($label);
        if ($norm === '') {
            continue;
        }
        $destino = (string) ($it['destino'] ?? 'auto');
        $base = trim((string) ($it['centro_base'] ?? ''));
        $codigo = strtoupper(trim((string) ($it['centro_codigo'] ?? '')));
        if ($destino === 'auto' || ($destino === 'sede' && $base === '') || ($destino === 'fijo' && $codigo === '')) {
            $del->execute([$norm]);
            continue;
        }
        if (!in_array($destino, ['sede', 'fijo'], true)) {
            throw new InvalidArgumentException('Destino inválido para ' . $label);
        }
        $up->execute([$norm, $label, $destino, $destino === 'sede' ? $base : null, $destino === 'fijo' ? $codigo : null, $by]);
        $n++;
    }
    grooflow_mgr_area_map_load($pdo, true);

    return $n;
}

/** Catálogo para la UI: áreas conocidas, bases de centros por sede, centros activos y mapeo actual. */
function grooflow_mgr_area_map_catalog(PDO $pdo): array
{
    grooflow_mgr_area_map_ensure($pdo);
    $areas = [];
    $add = static function ($a) use (&$areas) {
        $a = trim((string) $a);
        if ($a !== '') {
            $areas[grooflow_mgr_norm_text($a)] ??= $a;
        }
    };
    $sys = grooflow_kv_get($pdo, 'settings:system');
    foreach ((array) ($sys['providers']['areas'] ?? []) as $a) {
        $add($a);
    }
    foreach ((array) (grooflow_kv_get($pdo, 'data:pettyCash') ?? []) as $t) {
        if (is_array($t)) {
            $add($t['area'] ?? '');
        }
    }
    foreach ((array) (grooflow_kv_get($pdo, 'data:providers') ?? []) as $p) {
        if (is_array($p)) {
            $add($p['area'] ?? '');
        }
    }
    $map = grooflow_mgr_area_map_load($pdo, true);
    foreach ($map as $r) {
        $add($r['area_label']);
    }

    $centers = $pdo->query("
        SELECT codigo, nombre, sede_nombre FROM grooflow_centros_costo
        WHERE is_deleted=0 AND estado='activo' ORDER BY codigo
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $bases = [];
    foreach ($centers as $c) {
        if (trim((string) ($c['sede_nombre'] ?? '')) !== '') {
            $b = trim(explode(' - ', (string) $c['nombre'])[0]);
            $bases[grooflow_mgr_norm_text($b)] ??= $b;
        }
    }

    $baseFor = static function (string $target) use ($bases): ?string {
        foreach ($bases as $bn => $label) {
            if ($bn === $target || str_starts_with($bn, $target) || str_starts_with($target, $bn)) {
                return $label;
            }
        }

        return null;
    };
    $items = [];
    foreach ($areas as $norm => $label) {
        $cfg = $map[$norm] ?? null;
        $base = $baseFor(GROOFLOW_MGR_AREA_ALIASES[$norm] ?? $norm);
        $auto = isset(GROOFLOW_MGR_AREA_CORPORATE[$norm])
            ? 'Fijo: ' . GROOFLOW_MGR_AREA_CORPORATE[$norm]
            : ($base !== null ? 'Por sede: ' . $base : 'Gastos generales de sede');
        $items[] = [
            'area' => $label,
            'destino' => $cfg['destino'] ?? 'auto',
            'centro_base' => $cfg['centro_base'] ?? null,
            'centro_codigo' => $cfg['centro_codigo'] ?? null,
            'automatico' => $auto,
        ];
    }
    usort($items, static fn ($a, $b) => strcmp(grooflow_mgr_norm_text($a['area']), grooflow_mgr_norm_text($b['area'])));
    $baseList = array_values($bases);
    sort($baseList);

    return [
        'items' => $items,
        'bases' => $baseList,
        'centros' => array_map(static fn ($c) => ['codigo' => $c['codigo'], 'nombre' => $c['nombre'], 'sede' => $c['sede_nombre']], $centers),
    ];
}

/**
 * Centro de costo según área operativa + sede (ej. Mantenimiento + Benavides → MAN-BEN).
 * Sin match de área pero con sede → Gastos Generales de Sede.
 *
 * @return array{id:int,codigo:string,via:string}|null
 */
function grooflow_mgr_resolve_cc_by_area(PDO $pdo, ?string $area, ?string $sede): ?array
{
    static $centers = null;
    if ($centers === null) {
        $centers = $pdo->query("
            SELECT id, codigo, nombre, sede_nombre FROM grooflow_centros_costo
            WHERE is_deleted=0 AND estado='activo'
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $areaN = grooflow_mgr_norm_text($area);
    $sedeN = grooflow_mgr_norm_text($sede);
    $cfg = $areaN !== '' ? (grooflow_mgr_area_map_load($pdo)[$areaN] ?? null) : null;

    $fixed = $cfg && $cfg['destino'] === 'fijo'
        ? strtoupper((string) $cfg['centro_codigo'])
        : ($cfg ? null : (GROOFLOW_MGR_AREA_CORPORATE[$areaN] ?? null));
    if ($fixed !== null && $fixed !== '') {
        foreach ($centers as $c) {
            if (strtoupper((string) $c['codigo']) === $fixed) {
                return ['id' => (int) $c['id'], 'codigo' => (string) $c['codigo'], 'via' => $cfg ? 'area_config' : 'area_corporativa'];
            }
        }
    }

    if ($sedeN === '') {
        return null;
    }
    $inSede = array_values(array_filter($centers, static fn ($c) => grooflow_mgr_norm_text($c['sede_nombre'] ?? '') === $sedeN));
    if ($inSede === []) {
        return null;
    }

    if ($areaN !== '') {
        $target = $cfg && $cfg['destino'] === 'sede'
            ? grooflow_mgr_norm_text((string) $cfg['centro_base'])
            : (GROOFLOW_MGR_AREA_ALIASES[$areaN] ?? $areaN);
        foreach ($inSede as $c) {
            $base = grooflow_mgr_norm_text(explode(' - ', (string) $c['nombre'])[0]);
            if ($base === $target || str_starts_with($base, $target) || str_starts_with($target, $base)) {
                return ['id' => (int) $c['id'], 'codigo' => (string) $c['codigo'], 'via' => $cfg ? 'area_config' : 'area_sede'];
            }
        }
    }

    foreach ($inSede as $c) {
        if (str_starts_with(grooflow_mgr_norm_text((string) $c['nombre']), 'gastos generales de sede')) {
            return ['id' => (int) $c['id'], 'codigo' => (string) $c['codigo'], 'via' => 'generales_sede'];
        }
    }

    return null;
}

function grooflow_mgr_iso_date(mixed $v): string
{
    $s = (string) $v;
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
        // Fechas JS serializadas a medianoche Lima llegan como T05:00Z del mismo día
        return $m[1];
    }

    return date('Y-m-d');
}

/**
 * Normaliza un item de origen a payload de ingest, o null si no aplica a centros de costo.
 *
 * @param array<string,mixed> $item
 * @param array<string,array<string,mixed>> $providersById
 * @return array<string,mixed>|null
 */
function grooflow_mgr_source_payload(string $origen, array $item, array $providersById): ?array
{
    if (empty($item['id'])) {
        return null;
    }
    if ($origen === 'compra' || $origen === 'honorario') {
        return grooflow_mgr_invoice_payload($origen, $item, $providersById);
    }
    if (($item['type'] ?? '') !== 'expense') {
        return null;
    }
    $amount = round((float) ($item['amount'] ?? 0), 2);
    if ($amount <= 0) {
        return null;
    }
    $fecha = grooflow_mgr_iso_date($item['documentDate'] ?? $item['date'] ?? '');

    if ($origen === 'caja') {
        // Solo lo auditado entra al P&L gerencial
        if (($item['status'] ?? '') !== 'approved') {
            return null;
        }

        return [
            'fecha' => $fecha,
            'monto' => (float) ($item['amountBI'] ?? 0) > 0 ? round((float) $item['amountBI'], 2) : $amount,
            'concepto' => trim((string) ($item['category'] ?? '') . ' · ' . (string) ($item['description'] ?? '')),
            'cuenta_codigo' => (string) ($item['accountingAccount'] ?? ''),
            'sede_nombre' => (string) ($item['location'] ?? ''),
            'area' => (string) ($item['area'] ?? ''),
            'origen_tipo' => 'caja',
            'origen_id' => (string) $item['id'],
        ];
    }

    // Transacciones: excluir proyecciones del flujo y fechas futuras
    $id = (string) $item['id'];
    $desc = (string) ($item['description'] ?? '');
    if (str_starts_with($id, 'cf') || stripos($desc, 'proyecci') !== false || $fecha > date('Y-m-d')) {
        return null;
    }
    $prov = !empty($item['providerId']) ? ($providersById[(string) $item['providerId']] ?? null) : null;
    $concepto = trim(implode(' · ', array_filter([
        (string) ($item['category'] ?? ''),
        (string) ($item['subcategory'] ?? ''),
        (string) ($item['concept'] ?? ''),
        $desc,
    ])));
    $area = trim((string) ($item['area'] ?? ''));

    return [
        'fecha' => $fecha,
        'monto' => $amount,
        'concepto' => $concepto !== '' ? $concepto : 'Egreso',
        'cuenta_codigo' => grooflow_mgr_provider_account($prov),
        'sede_nombre' => (string) ($item['location'] ?? ''),
        'area' => $area !== '' ? $area : ($prov ? (string) ($prov['area'] ?? '') : ''),
        'origen_tipo' => 'transaccion',
        'origen_id' => $id,
    ];
}

/** Cuenta global del proveedor (misma política que el frontend). */
function grooflow_mgr_provider_account(?array $prov): string
{
    if (!$prov) {
        return '';
    }

    return trim((string) ($prov['accountingAccount'] ?? ''))
        ?: trim((string) ($prov['defaultPurchaseAccount'] ?? ''))
        ?: trim((string) ($prov['defaultProfessionalFeeAccount'] ?? ''));
}

/**
 * Compras aprobadas (data:requests) y honorarios aprobados (data:feeReceipts) → origen_tipo 'factura'.
 *
 * @param array<string,mixed> $item
 * @param array<string,array<string,mixed>> $providersById
 * @return array<string,mixed>|null
 */
function grooflow_mgr_invoice_payload(string $origen, array $item, array $providersById): ?array
{
    $status = (string) ($item['status'] ?? '');
    $amount = round((float) ($item['amount'] ?? 0), 2);
    if ($amount <= 0) {
        return null;
    }
    if ($origen === 'compra') {
        if ($status !== 'approved') {
            return null;
        }
        $prov = $providersById[(string) ($item['providerId'] ?? '')] ?? null;

        return [
            'fecha' => grooflow_mgr_iso_date($item['requestDate'] ?? ''),
            'monto' => $amount,
            'concepto' => trim((string) ($item['description'] ?? '')) ?: 'Compra ' . (string) ($item['providerName'] ?? ''),
            'cuenta_codigo' => grooflow_mgr_provider_account($prov),
            'sede_nombre' => (string) ($item['location'] ?? ''),
            'area' => $prov ? (string) ($prov['area'] ?? '') : '',
            'origen_tipo' => 'factura',
            'origen_id' => 'purchase:' . $item['id'],
        ];
    }
    if (!in_array($status, ['approved', 'requested_payment', 'paid'], true)) {
        return null;
    }
    $prov = $providersById[(string) ($item['professionalId'] ?? '')] ?? null;

    return [
        'fecha' => grooflow_mgr_iso_date($item['issueDate'] ?? ''),
        'monto' => $amount,
        'concepto' => trim((string) ($item['description'] ?? '')) ?: 'Honorario ' . (string) ($item['receiptNumber'] ?? ''),
        'cuenta_codigo' => grooflow_mgr_provider_account($prov),
        'sede_nombre' => (string) ($item['location'] ?? ''),
        'area' => $prov ? (string) ($prov['area'] ?? '') : '',
        'origen_tipo' => 'factura',
        'origen_id' => 'fee:' . $item['id'],
    ];
}

/** origen lógico → [origen_tipo en gastos_cc, prefijo de origen_id, clave KV] */
const GROOFLOW_MGR_SYNC_SOURCES = [
    'caja' => ['caja', '', 'data:pettyCash'],
    'transaccion' => ['transaccion', '', 'data:transactions'],
    'compra' => ['factura', 'purchase:', 'data:requests'],
    'honorario' => ['factura', 'fee:', 'data:feeReceipts'],
];

/** @param array<string,mixed> $p */
function grooflow_mgr_payload_fingerprint(array $p): string
{
    return substr(md5(json_encode([
        $p['fecha'], $p['monto'], preg_replace('/\D+/', '', (string) $p['cuenta_codigo']),
        grooflow_mgr_norm_text($p['sede_nombre']), grooflow_mgr_norm_text($p['area']),
    ])), 0, 10);
}

function grooflow_mgr_void_gasto(PDO $pdo, int $id, string $estado): void
{
    if ($estado === 'distribuido') {
        grooflow_gasto_reverse($pdo, $id);
    }
    $pdo->prepare("UPDATE grooflow_gastos_cc SET is_deleted=1, estado='anulado' WHERE id=?")->execute([$id]);
}

/**
 * @param list<array<string,mixed>> $items
 * @param array<string,array<string,mixed>> $providersById
 * @return array<string,int>
 */
function grooflow_mgr_sync_source(PDO $pdo, string $origen, array $items, array $providersById = [], int $maxNew = 500, array $forceAreas = []): array
{
    $force = array_flip(array_filter(array_map('grooflow_mgr_norm_text', $forceAreas)));
    grooflow_mgr_pnl_ensure_schema($pdo);
    grooflow_mgr_area_map_ensure($pdo);
    $stats = ['creados' => 0, 'actualizados' => 0, 'anulados' => 0, 'sin_cambio' => 0, 'errores' => 0, 'pendientes_sin_cc' => 0];
    [$tipo, $prefix] = GROOFLOW_MGR_SYNC_SOURCES[$origen] ?? [$origen, ''];

    $existing = [];
    $st = $pdo->prepare('SELECT id, origen_id, estado, notas FROM grooflow_gastos_cc WHERE origen_tipo=? AND origen_id LIKE ? AND is_deleted=0');
    $st->execute([$tipo, $prefix . '%']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $existing[(string) $r['origen_id']] = $r;
    }

    $seen = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $payload = grooflow_mgr_source_payload($origen, $item, $providersById);
        if ($payload === null) {
            continue;
        }
        $oid = $payload['origen_id'];
        $seen[$oid] = true;
        $fp = grooflow_mgr_payload_fingerprint($payload);
        $payload['notas'] = 'fp:' . $fp . ' · sync ' . $origen;

        $cur = $existing[$oid] ?? null;
        $forced = isset($force[grooflow_mgr_norm_text($payload['area'])]);
        if ($cur && !$forced && str_starts_with((string) $cur['notas'], 'fp:' . $fp)) {
            $stats['sin_cambio']++;
            continue;
        }
        if ($stats['creados'] + $stats['actualizados'] >= $maxNew) {
            continue;
        }
        try {
            if ($cur) {
                grooflow_mgr_void_gasto($pdo, (int) $cur['id'], (string) $cur['estado']);
            }
            $res = grooflow_mgr_ingest_expense($pdo, $payload + ['auto_distribute' => true, 'created_by' => 'sync']);
            $stats[$cur ? 'actualizados' : 'creados']++;
            if (($res['gasto']['tipo_asignacion'] ?? '') === 'SIN_ASIGNAR') {
                $stats['pendientes_sin_cc']++;
            }
        } catch (Throwable $e) {
            $stats['errores']++;
            error_log('[grooflow mgr sync] ' . $origen . ' ' . $oid . ': ' . $e->getMessage());
        }
    }

    foreach ($existing as $oid => $cur) {
        if (isset($seen[$oid])) {
            continue;
        }
        try {
            grooflow_mgr_void_gasto($pdo, (int) $cur['id'], (string) $cur['estado']);
            $stats['anulados']++;
        } catch (Throwable $e) {
            $stats['errores']++;
        }
    }

    return $stats;
}

/** @return array<string,array<string,mixed>> */
function grooflow_mgr_providers_by_id(PDO $pdo): array
{
    $out = [];
    foreach ((array) (grooflow_kv_get($pdo, 'data:providers') ?? []) as $p) {
        if (is_array($p) && !empty($p['id'])) {
            $out[(string) $p['id']] = $p;
        }
    }

    return $out;
}

/** @return array<string,array<string,int>> */
function grooflow_mgr_sync_all(PDO $pdo, ?array $only = null, array $forceAreas = []): array
{
    $out = [];
    $providers = grooflow_mgr_providers_by_id($pdo);
    foreach (GROOFLOW_MGR_SYNC_SOURCES as $origen => [, , $kvKey]) {
        if ($only === null || in_array($origen, $only, true)) {
            $out[$origen] = grooflow_mgr_sync_source($pdo, $origen, array_values((array) (grooflow_kv_get($pdo, $kvKey) ?? [])), $providers, 500, $forceAreas);
        }
    }

    return $out;
}

/** Hook tras guardar un recurso KV. Nunca rompe el guardado. */
function grooflow_mgr_sync_after_write(PDO $pdo, string $key): void
{
    $origen = null;
    foreach (GROOFLOW_MGR_SYNC_SOURCES as $o => [, , $kvKey]) {
        if ($kvKey === $key) {
            $origen = $o;
        }
    }
    if ($origen === null || $pdo->inTransaction()) {
        return;
    }
    try {
        grooflow_mgr_sync_all($pdo, [$origen]);
    } catch (Throwable $e) {
        error_log('[grooflow mgr sync] ' . $key . ': ' . $e->getMessage());
    }
}
