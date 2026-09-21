<?php

declare(strict_types=1);

/**
 * Catálogo operativo de colaboradores (SST / Uniformes).
 * Accesible con Accidentes de Trabajo | Entrega de Uniformes | RRHH
 * (sin exigir allSedes como /rrhh/*).
 */

/**
 * @return list<array<string, mixed>>
 */
function grooflow_hr_list_colaboradores(PDO $pdo): array
{
    if (! function_exists('table_exists') || ! table_exists($pdo, 'grooflow_buk_empleados')) {
        return [];
    }

    $sql = "
        SELECT
            buk_id,
            full_name,
            first_name,
            surname,
            document_number,
            email,
            cargo,
            especialidad,
            area,
            area_asistencia,
            contract_type,
            start_date,
            active_since,
            sede,
            linked_usuario_id,
            payload
        FROM grooflow_buk_empleados
        WHERE is_active = 1
          AND COALESCE(is_terminated, 0) = 0
        ORDER BY full_name ASC, buk_id ASC
        LIMIT 5000
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $full = trim((string) ($r['full_name'] ?? ''));
        if ($full === '') {
            $full = trim(trim((string) ($r['first_name'] ?? '')) . ' ' . trim((string) ($r['surname'] ?? '')));
        }
        if ($full === '') {
            continue;
        }

        $payload = [];
        if (! empty($r['payload'])) {
            if (is_array($r['payload'])) {
                $payload = $r['payload'];
            } elseif (is_string($r['payload'])) {
                $decoded = json_decode($r['payload'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
        }
        $normalized = is_array($payload['normalized'] ?? null) ? $payload['normalized'] : [];

        $cargo = trim((string) ($r['cargo'] ?? ''));
        if ($cargo === '' && ! empty($normalized['cargo'])) {
            $cargo = trim((string) $normalized['cargo']);
        }
        if ($cargo === '') {
            $cargo = trim((string) ($r['especialidad'] ?? ''));
        }

        $areaPadre = '';
        foreach (['orgAreaParentName', 'org_area_parent_name'] as $k) {
            if (! empty($normalized[$k])) {
                $areaPadre = trim((string) $normalized[$k]);
                break;
            }
        }
        if ($areaPadre === '' && ! empty($normalized['orgAreaName'])) {
            $areaPadre = trim((string) $normalized['orgAreaName']);
        }
        if ($areaPadre === '') {
            $areaPadre = trim((string) ($r['area'] ?? ''));
        }
        if ($areaPadre === '') {
            $areaPadre = trim((string) ($r['area_asistencia'] ?? ''));
        }

        $contract = trim((string) ($r['contract_type'] ?? ''));
        if ($contract === '' && ! empty($normalized['contractType'])) {
            $contract = trim((string) $normalized['contractType']);
        }

        $activeSince = trim((string) ($r['active_since'] ?? ''));
        if ($activeSince === '' && ! empty($normalized['activeSince'])) {
            $activeSince = trim((string) $normalized['activeSince']);
        }
        if ($activeSince !== '' && strlen($activeSince) > 10) {
            $activeSince = substr($activeSince, 0, 10);
        }

        $start = trim((string) ($r['start_date'] ?? ''));
        if ($start === '' && ! empty($normalized['startDate'])) {
            $start = trim((string) $normalized['startDate']);
        }
        if ($start !== '' && strlen($start) > 10) {
            $start = substr($start, 0, 10);
        }

        $costCenter = '';
        if (! function_exists('grooflow_rrhh_extract_cost_center_code')) {
            require_once __DIR__ . '/grooflow_rrhh.php';
        }
        if (function_exists('grooflow_rrhh_extract_cost_center_code')) {
            $costCenter = grooflow_rrhh_extract_cost_center_code($r);
        }

        $out[] = [
            'bukId' => (int) ($r['buk_id'] ?? 0),
            'fullName' => $full,
            'documentNumber' => trim((string) ($r['document_number'] ?? '')) ?: null,
            'email' => trim((string) ($r['email'] ?? '')) ?: null,
            'cargo' => $cargo !== '' ? $cargo : null,
            'orgAreaParentName' => $areaPadre !== '' ? $areaPadre : null,
            'contractType' => $contract !== '' ? $contract : null,
            'activeSince' => $activeSince !== '' ? $activeSince : null,
            'startDate' => $start !== '' ? $start : null,
            'sede' => trim((string) ($r['sede'] ?? '')) ?: null,
            'costCenter' => $costCenter !== '' ? $costCenter : null,
            'linkedUsuarioId' => isset($r['linked_usuario_id']) && $r['linked_usuario_id'] !== null && $r['linked_usuario_id'] !== ''
                ? (string) $r['linked_usuario_id']
                : null,
        ];
    }

    return $out;
}
