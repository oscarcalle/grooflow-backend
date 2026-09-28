<?php

declare(strict_types=1);

/**
 * Sincroniza fuentes operativas (caja chica, transacciones) con gastos por centro de costo.
 * Reconciliación idempotente: crea, re-crea si cambió, y anula lo que ya no aplica.
 * Se ejecuta tras cada guardado de data:pettyCash / data:transactions (fuera de la TX de escritura).
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

    if ($areaN !== '' && isset(GROOFLOW_MGR_AREA_CORPORATE[$areaN])) {
        foreach ($centers as $c) {
            if ($c['codigo'] === GROOFLOW_MGR_AREA_CORPORATE[$areaN]) {
                return ['id' => (int) $c['id'], 'codigo' => (string) $c['codigo'], 'via' => 'area_corporativa'];
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
        $target = GROOFLOW_MGR_AREA_ALIASES[$areaN] ?? $areaN;
        foreach ($inSede as $c) {
            $base = grooflow_mgr_norm_text(explode(' - ', (string) $c['nombre'])[0]);
            if ($base === $target || str_starts_with($base, $target) || str_starts_with($target, $base)) {
                return ['id' => (int) $c['id'], 'codigo' => (string) $c['codigo'], 'via' => 'area_sede'];
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
    if (empty($item['id']) || ($item['type'] ?? '') !== 'expense') {
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
    $cuenta = '';
    if ($prov) {
        $cuenta = (string) ($prov['defaultPurchaseAccount'] ?? '') ?: (string) ($prov['accountingAccount'] ?? '');
    }
    $concepto = trim(implode(' · ', array_filter([
        (string) ($item['category'] ?? ''),
        (string) ($item['subcategory'] ?? ''),
        (string) ($item['concept'] ?? ''),
        $desc,
    ])));

    return [
        'fecha' => $fecha,
        'monto' => $amount,
        'concepto' => $concepto !== '' ? $concepto : 'Egreso',
        'cuenta_codigo' => $cuenta,
        'sede_nombre' => (string) ($item['location'] ?? ''),
        'area' => $prov ? (string) ($prov['area'] ?? '') : '',
        'origen_tipo' => 'transaccion',
        'origen_id' => $id,
    ];
}

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
function grooflow_mgr_sync_source(PDO $pdo, string $origen, array $items, array $providersById = [], int $maxNew = 500): array
{
    grooflow_mgr_pnl_ensure_schema($pdo);
    $stats = ['creados' => 0, 'actualizados' => 0, 'anulados' => 0, 'sin_cambio' => 0, 'errores' => 0, 'pendientes_sin_cc' => 0];

    $existing = [];
    $st = $pdo->prepare('SELECT id, origen_id, estado, notas FROM grooflow_gastos_cc WHERE origen_tipo=? AND is_deleted=0');
    $st->execute([$origen]);
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
        if ($cur && str_starts_with((string) $cur['notas'], 'fp:' . $fp)) {
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
function grooflow_mgr_sync_all(PDO $pdo, ?array $only = null): array
{
    $out = [];
    $providers = grooflow_mgr_providers_by_id($pdo);
    if ($only === null || in_array('caja', $only, true)) {
        $out['caja'] = grooflow_mgr_sync_source($pdo, 'caja', (array) (grooflow_kv_get($pdo, 'data:pettyCash') ?? []), $providers);
    }
    if ($only === null || in_array('transaccion', $only, true)) {
        $out['transaccion'] = grooflow_mgr_sync_source($pdo, 'transaccion', (array) (grooflow_kv_get($pdo, 'data:transactions') ?? []), $providers);
    }

    return $out;
}

/** Hook tras guardar un recurso KV. Nunca rompe el guardado. */
function grooflow_mgr_sync_after_write(PDO $pdo, string $key): void
{
    $map = ['data:pettyCash' => 'caja', 'data:transactions' => 'transaccion'];
    if (!isset($map[$key]) || $pdo->inTransaction()) {
        return;
    }
    try {
        grooflow_mgr_sync_all($pdo, [$map[$key]]);
    } catch (Throwable $e) {
        error_log('[grooflow mgr sync] ' . $key . ': ' . $e->getMessage());
    }
}
