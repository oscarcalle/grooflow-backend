<?php

declare(strict_types=1);

/**
 * Catálogo operativo de Flota (choferes) — accesible con Gestión Vehicular.
 * No exige módulo RRHH ni allSedes (a diferencia de /rrhh/*).
 */

/**
 * @return list<array{id:string,fullName:string,cargo:?string,source:string,bukId:int}>
 */
function grooflow_fleet_list_choferes(PDO $pdo): array
{
    if (! function_exists('table_exists') || ! table_exists($pdo, 'grooflow_buk_empleados')) {
        return [];
    }

    $sql = "
        SELECT buk_id, full_name, first_name, surname, cargo, especialidad, area, area_asistencia
        FROM grooflow_buk_empleados
        WHERE is_active = 1
          AND missing_from_source = 0
          AND (
            LOWER(COALESCE(cargo, '')) LIKE '%chofer%'
            OR LOWER(COALESCE(cargo, '')) LIKE '%conductor%'
            OR LOWER(COALESCE(especialidad, '')) LIKE '%chofer%'
            OR LOWER(COALESCE(especialidad, '')) LIKE '%conductor%'
            OR LOWER(COALESCE(area, '')) LIKE '%chofer%'
            OR LOWER(COALESCE(area, '')) LIKE '%conductor%'
            OR LOWER(COALESCE(area_asistencia, '')) LIKE '%chofer%'
            OR LOWER(COALESCE(area_asistencia, '')) LIKE '%conductor%'
          )
        ORDER BY full_name ASC, buk_id ASC
        LIMIT 500
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    $seen = [];
    foreach ($rows as $r) {
        $full = trim((string) ($r['full_name'] ?? ''));
        if ($full === '') {
            $full = trim(trim((string) ($r['first_name'] ?? '')) . ' ' . trim((string) ($r['surname'] ?? '')));
        }
        if ($full === '') {
            continue;
        }
        $key = mb_strtolower($full, 'UTF-8');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $cargo = trim((string) ($r['cargo'] ?? ''));
        if ($cargo === '') {
            $cargo = trim((string) ($r['especialidad'] ?? ''));
        }
        if ($cargo === '') {
            $cargo = trim((string) ($r['area'] ?? ''));
        }
        $bukId = (int) ($r['buk_id'] ?? 0);
        $out[] = [
            'id' => 'buk:' . $bukId,
            'fullName' => $full,
            'cargo' => $cargo !== '' ? $cargo : null,
            'source' => 'rrhh',
            'bukId' => $bukId,
        ];
    }

    return $out;
}
