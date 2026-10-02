<?php

declare(strict_types=1);

/**
 * Cashback por facturas: el colaborador sustenta facturas de consumo a nombre de la empresa
 * (crédito fiscal IGV) y recibe un reconocimiento configurable que se acumula hasta un umbral.
 *
 * Permisos (acciones del módulo "Cashback"):
 *  - ver / agregar: registrar y consultar sus propias facturas.
 *  - editar: bandeja de validación (aprobar / observar / rechazar).
 *  - exportar: reportes globales.
 *  - configurar: reglas y liquidaciones.
 */

const GROOFLOW_CASHBACK_MODULE = 'Cashback';
const GROOFLOW_CASHBACK_PHOTO_MAX_BYTES = 2_500_000;
const GROOFLOW_CASHBACK_STATES = ['en_revision', 'observada', 'aprobada', 'rechazada', 'liquidada'];

function grooflow_cashback_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_cashback_facturas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            usuario_id INT UNSIGNED NOT NULL,
            usuario_nombre VARCHAR(190) NOT NULL DEFAULT '',
            colaborador_buk_id INT UNSIGNED NULL,
            colaborador_doc VARCHAR(40) NULL,
            sede VARCHAR(160) NULL,
            emisor_ruc CHAR(11) NOT NULL,
            emisor_nombre VARCHAR(255) NULL,
            emisor_estado VARCHAR(40) NULL,
            emisor_condicion VARCHAR(40) NULL,
            tipo_doc CHAR(2) NOT NULL DEFAULT '01',
            serie VARCHAR(8) NOT NULL,
            numero VARCHAR(12) NOT NULL,
            fecha_emision DATE NOT NULL,
            periodo CHAR(7) NOT NULL,
            moneda CHAR(3) NOT NULL DEFAULT 'PEN',
            base DECIMAL(12,2) NOT NULL DEFAULT 0,
            igv DECIMAL(12,2) NOT NULL DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            comprador_ruc CHAR(11) NULL,
            categoria VARCHAR(40) NOT NULL,
            motivo VARCHAR(255) NOT NULL DEFAULT '',
            centro_costo VARCHAR(80) NULL,
            qr_raw VARCHAR(500) NULL,
            photo_hash CHAR(64) NULL,
            alertas JSON NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'en_revision',
            cashback_monto DECIMAL(12,2) NULL,
            regla_snapshot JSON NULL,
            revisor_id INT UNSIGNED NULL,
            revisor_nombre VARCHAR(190) NULL,
            revisado_at DATETIME NULL,
            nota_revision VARCHAR(500) NULL,
            liquidacion_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_gf_cb_doc (emisor_ruc, tipo_doc, serie, numero),
            KEY idx_gf_cb_user (usuario_id, estado),
            KEY idx_gf_cb_estado (estado, periodo),
            KEY idx_gf_cb_hash (photo_hash),
            KEY idx_gf_cb_liq (liquidacion_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_cashback_fotos (
            factura_id BIGINT UNSIGNED NOT NULL,
            mime VARCHAR(60) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
            data MEDIUMBLOB NOT NULL,
            PRIMARY KEY (factura_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_cashback_liquidaciones (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            periodo CHAR(7) NOT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'generada',
            tratamiento VARCHAR(20) NOT NULL DEFAULT 'reembolso',
            colaboradores INT UNSIGNED NOT NULL DEFAULT 0,
            facturas INT UNSIGNED NOT NULL DEFAULT 0,
            igv_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            monto_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            essalud_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            lineas JSON NULL,
            creado_por INT UNSIGNED NULL,
            creado_por_nombre VARCHAR(190) NULL,
            pagado_at DATETIME NULL,
            nota VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_gf_cb_liq_periodo (periodo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_cashback_config (
            id TINYINT UNSIGNED NOT NULL,
            payload JSON NOT NULL,
            updated_by INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}

/** @return array<string, mixed> */
function grooflow_cashback_default_settings(): array
{
    return [
        'companyRucs' => [],
        'calcMode' => 'pct_igv',
        'pctIgv' => 50.0,
        'pctTotal' => 5.0,
        'flatAmount' => 3.0,
        'capAtIgv' => true,
        'releaseThreshold' => 50.0,
        'maxDaysOld' => 45,
        'payoutTreatment' => 'reembolso',
        'essaludRate' => 9.0,
        'categories' => [
            ['id' => 'alimentacion', 'label' => 'Alimentación', 'enabled' => true],
            ['id' => 'traslados', 'label' => 'Traslados', 'enabled' => true],
            ['id' => 'oficina', 'label' => 'Artículos de oficina', 'enabled' => true],
            ['id' => 'mantenimiento', 'label' => 'Equipos de mantenimiento', 'enabled' => true],
            ['id' => 'limpieza', 'label' => 'Limpieza', 'enabled' => true],
        ],
        'excludedNote' => 'No se aceptan facturas con bebidas alcohólicas ni bienes de uso personal.',
    ];
}

/** @return array<string, mixed> */
function grooflow_cashback_normalize_settings(array $raw): array
{
    $d = grooflow_cashback_default_settings();
    $num = static function (mixed $v, float $fallback, float $min, float $max): float {
        if (!is_numeric($v)) {
            return $fallback;
        }
        return max($min, min($max, round((float) $v, 2)));
    };
    $rucs = [];
    foreach ((array) ($raw['companyRucs'] ?? []) as $ruc) {
        $ruc = preg_replace('/\D/', '', (string) $ruc) ?? '';
        if (strlen($ruc) === 11 && !in_array($ruc, $rucs, true)) {
            $rucs[] = $ruc;
        }
    }
    $mode = (string) ($raw['calcMode'] ?? $d['calcMode']);
    if (!in_array($mode, ['pct_igv', 'pct_total', 'flat'], true)) {
        $mode = $d['calcMode'];
    }
    $categories = [];
    $seen = [];
    foreach ((array) ($raw['categories'] ?? $d['categories']) as $cat) {
        if (!is_array($cat)) {
            continue;
        }
        $label = trim((string) ($cat['label'] ?? ''));
        $id = trim((string) ($cat['id'] ?? ''));
        if ($id === '') {
            $id = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label) ?? '');
        }
        if ($id === '' || $label === '' || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $categories[] = ['id' => substr($id, 0, 40), 'label' => mb_substr($label, 0, 80), 'enabled' => ($cat['enabled'] ?? true) !== false];
    }
    if ($categories === []) {
        $categories = $d['categories'];
    }

    return [
        'companyRucs' => $rucs,
        'calcMode' => $mode,
        'pctIgv' => $num($raw['pctIgv'] ?? null, $d['pctIgv'], 0, 100),
        'pctTotal' => $num($raw['pctTotal'] ?? null, $d['pctTotal'], 0, 100),
        'flatAmount' => $num($raw['flatAmount'] ?? null, $d['flatAmount'], 0, 100000),
        'capAtIgv' => ($raw['capAtIgv'] ?? $d['capAtIgv']) !== false,
        'releaseThreshold' => $num($raw['releaseThreshold'] ?? null, $d['releaseThreshold'], 0, 1000000),
        'maxDaysOld' => (int) $num($raw['maxDaysOld'] ?? null, (float) $d['maxDaysOld'], 1, 400),
        'payoutTreatment' => ($raw['payoutTreatment'] ?? '') === 'remuneracion' ? 'remuneracion' : 'reembolso',
        'essaludRate' => $num($raw['essaludRate'] ?? null, $d['essaludRate'], 0, 100),
        'categories' => $categories,
        'excludedNote' => mb_substr(trim((string) ($raw['excludedNote'] ?? $d['excludedNote'])), 0, 400),
    ];
}

/** @return array<string, mixed> */
function grooflow_cashback_get_settings(PDO $pdo): array
{
    grooflow_cashback_ensure_schema($pdo);
    $raw = $pdo->query('SELECT payload, updated_at FROM grooflow_cashback_config WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    $settings = grooflow_cashback_normalize_settings(is_array($raw) ? (array) (grooflow_json_decode((string) $raw['payload']) ?? []) : []);
    $settings['updatedAt'] = is_array($raw) ? (string) $raw['updated_at'] : null;

    return $settings;
}

/** @return array<string, mixed> */
function grooflow_cashback_save_settings(PDO $pdo, array $input): array
{
    grooflow_cashback_require_action($pdo, 'configurar');
    $settings = grooflow_cashback_normalize_settings($input);
    $user = api_current_user() ?? [];
    $pdo->prepare('
        INSERT INTO grooflow_cashback_config (id, payload, updated_by) VALUES (1, ?, ?)
        ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_by = VALUES(updated_by)
    ')->execute([grooflow_json_encode($settings), (int) ($user['id'] ?? 0) ?: null]);
    grooflow_audit_insert($pdo, $user, 'cashback.settings', ['entity' => 'cashback_config', 'settings' => $settings]);

    return grooflow_cashback_get_settings($pdo);
}

function grooflow_cashback_can(PDO $pdo, string $action): bool
{
    $ctx = grooflow_access_context($pdo);
    if (!empty($ctx['admin'])) {
        return true;
    }
    return !empty($ctx['actions'][GROOFLOW_CASHBACK_MODULE][$action]);
}

function grooflow_cashback_require_action(PDO $pdo, string $action): void
{
    if (!grooflow_cashback_can($pdo, $action)) {
        throw new RuntimeException('No tienes permiso para esta acción de Cashback');
    }
}

function grooflow_cashback_is_reviewer(PDO $pdo): bool
{
    return grooflow_cashback_can($pdo, 'editar') || grooflow_cashback_can($pdo, 'configurar') || grooflow_cashback_can($pdo, 'exportar');
}

function grooflow_cashback_user_name(array $row): string
{
    $name = trim(((string) ($row['nombre'] ?? '')) . ' ' . ((string) ($row['apellido'] ?? '')));
    if ($name === '') {
        $name = trim((string) ($row['usuario'] ?? $row['email'] ?? ''));
    }
    return mb_substr($name !== '' ? $name : ('Usuario ' . (int) ($row['id'] ?? 0)), 0, 190);
}

/** @return array{linked: bool, bukId: ?int, nombre: ?string, documento: ?string, sede: ?string, area: ?string} */
function grooflow_cashback_colaborador_for_user(PDO $pdo, int $userId): array
{
    $empty = ['linked' => false, 'bukId' => null, 'nombre' => null, 'documento' => null, 'sede' => null, 'area' => null];
    try {
        $stmt = $pdo->prepare('
            SELECT buk_id, full_name, document_number, sede, area
            FROM grooflow_buk_empleados
            WHERE linked_usuario_id = ? AND is_active = 1
            ORDER BY buk_id DESC LIMIT 1
        ');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return $empty;
    }
    if (!is_array($row)) {
        return $empty;
    }

    return [
        'linked' => true,
        'bukId' => (int) $row['buk_id'],
        'nombre' => (string) $row['full_name'],
        'documento' => $row['document_number'] !== null ? (string) $row['document_number'] : null,
        'sede' => $row['sede'] !== null ? (string) $row['sede'] : null,
        'area' => $row['area'] !== null ? (string) $row['area'] : null,
    ];
}

/** Cálculo del reconocimiento según la regla vigente. */
function grooflow_cashback_compute(array $settings, float $igv, float $total): float
{
    $amount = match ($settings['calcMode']) {
        'pct_total' => $total * ((float) $settings['pctTotal']) / 100,
        'flat' => (float) $settings['flatAmount'],
        default => $igv * ((float) $settings['pctIgv']) / 100,
    };
    if (!empty($settings['capAtIgv'])) {
        $amount = min($amount, $igv);
    }
    return max(0.0, round($amount, 2));
}

function grooflow_cashback_money(mixed $v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_string($v)) {
        $v = str_replace([',', 'S/', ' '], ['', '', ''], $v);
    }
    return is_numeric($v) ? round((float) $v, 2) : null;
}

/**
 * Valida y normaliza la factura enviada por el colaborador.
 *
 * @return array{data: array<string, mixed>, alerts: list<string>}
 */
function grooflow_cashback_validate_input(array $settings, array $in): array
{
    $errors = [];
    $alerts = [];

    $emisorRuc = preg_replace('/\D/', '', (string) ($in['emisorRuc'] ?? '')) ?? '';
    if (strlen($emisorRuc) !== 11 || !in_array(substr($emisorRuc, 0, 2), ['10', '15', '17', '20'], true)) {
        $errors['emisorRuc'] = 'RUC del emisor inválido (11 dígitos).';
    }

    $tipo = trim((string) ($in['tipoDoc'] ?? '01'));
    $serie = strtoupper(trim((string) ($in['serie'] ?? '')));
    if ($tipo === '03' || str_starts_with($serie, 'B')) {
        $errors['serie'] = 'Es una boleta: no genera crédito fiscal. Pide factura con el RUC de la empresa.';
    } elseif ($tipo !== '01') {
        $errors['serie'] = 'Solo se aceptan facturas (tipo 01).';
    } elseif (!preg_match('/^(F[A-Z0-9]{3}|E001|\d{4})$/', $serie)) {
        $errors['serie'] = 'Serie de factura inválida (ej. F001, E001 o 0001).';
    }

    $numeroRaw = preg_replace('/\D/', '', (string) ($in['numero'] ?? '')) ?? '';
    $numero = ltrim($numeroRaw, '0');
    if ($numero === '' || strlen($numero) > 8) {
        $errors['numero'] = 'Número de factura inválido.';
    }

    $fecha = trim((string) ($in['fechaEmision'] ?? ''));
    $fechaDt = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    $today = new DateTimeImmutable('today');
    if (!$fechaDt || $fechaDt->format('Y-m-d') !== $fecha) {
        $errors['fechaEmision'] = 'Fecha de emisión inválida.';
    } elseif ($fechaDt > $today) {
        $errors['fechaEmision'] = 'La fecha de emisión no puede ser futura.';
    } elseif ($fechaDt < $today->modify('-' . (int) $settings['maxDaysOld'] . ' days')) {
        $errors['fechaEmision'] = 'La factura supera los ' . (int) $settings['maxDaysOld'] . ' días de antigüedad permitidos.';
    }

    $total = grooflow_cashback_money($in['total'] ?? null);
    $igv = grooflow_cashback_money($in['igv'] ?? null);
    $base = grooflow_cashback_money($in['base'] ?? null);
    if ($total === null || $total <= 0) {
        $errors['total'] = 'Ingresa el importe total.';
    }
    if ($igv === null || $igv <= 0) {
        $errors['igv'] = 'La factura debe tener IGV (las exoneradas o inafectas no generan crédito fiscal).';
    }
    if ($total !== null && $igv !== null && $igv > 0 && $total > 0) {
        if ($igv >= $total) {
            $errors['igv'] = 'El IGV no puede ser mayor o igual al total.';
        } else {
            if ($base === null || $base <= 0) {
                $base = round($total - $igv, 2);
            }
            $rate = $base > 0 ? $igv / $base : 0;
            $okRate = ($rate >= 0.17 && $rate <= 0.19) || ($rate >= 0.095 && $rate <= 0.105);
            if (!$okRate) {
                $alerts[] = sprintf('El IGV no corresponde al 18%% de la base (%.1f%%).', $rate * 100);
            }
            if (abs(($base + $igv) - $total) > 0.1) {
                $alerts[] = 'Base + IGV no coincide con el total (puede incluir ICBPER, propina u otros cargos).';
            }
        }
    }

    $compradorRuc = preg_replace('/\D/', '', (string) ($in['compradorRuc'] ?? '')) ?? '';
    $companyRucs = (array) $settings['companyRucs'];
    if ($companyRucs === []) {
        $alerts[] = 'Aún no se configuró el RUC de la empresa en Cashback.';
    } elseif (!in_array($compradorRuc, $companyRucs, true)) {
        $errors['compradorRuc'] = 'La factura debe estar emitida al RUC de la empresa (' . implode(' / ', $companyRucs) . ').';
    }

    $categoria = trim((string) ($in['categoria'] ?? ''));
    $catOk = false;
    foreach ((array) $settings['categories'] as $cat) {
        if (($cat['id'] ?? '') === $categoria && !empty($cat['enabled'])) {
            $catOk = true;
            break;
        }
    }
    if (!$catOk) {
        $errors['categoria'] = 'Elige una categoría permitida.';
    }

    $motivo = trim((string) ($in['motivo'] ?? ''));
    if (mb_strlen($motivo) < 4) {
        $errors['motivo'] = 'Describe brevemente el consumo.';
    }

    if (($in['declaraSinExcluidos'] ?? false) !== true) {
        $errors['declaraSinExcluidos'] = 'Debes confirmar que la factura no incluye bebidas alcohólicas ni bienes personales.';
    }

    $estado = strtoupper(trim((string) ($in['emisorEstado'] ?? '')));
    $condicion = strtoupper(trim((string) ($in['emisorCondicion'] ?? '')));
    if ($estado !== '' && $estado !== 'ACTIVO') {
        $alerts[] = "Emisor con estado SUNAT {$estado}.";
    }
    if ($condicion !== '' && $condicion !== 'HABIDO') {
        $alerts[] = "Emisor con condición SUNAT {$condicion}.";
    }
    if ($total !== null && $total >= 1000) {
        $alerts[] = 'Importe alto para un consumo de colaborador.';
    }

    if ($errors !== []) {
        throw new GrooflowValidation($errors);
    }

    return [
        'data' => [
            'emisor_ruc' => $emisorRuc,
            'emisor_nombre' => mb_substr(trim((string) ($in['emisorNombre'] ?? '')), 0, 255) ?: null,
            'emisor_estado' => $estado !== '' ? mb_substr($estado, 0, 40) : null,
            'emisor_condicion' => $condicion !== '' ? mb_substr($condicion, 0, 40) : null,
            'tipo_doc' => '01',
            'serie' => $serie,
            'numero' => $numero,
            'fecha_emision' => $fecha,
            'periodo' => substr($fecha, 0, 7),
            'base' => $base,
            'igv' => $igv,
            'total' => $total,
            'comprador_ruc' => $compradorRuc !== '' ? $compradorRuc : null,
            'categoria' => $categoria,
            'motivo' => mb_substr($motivo, 0, 255),
            'centro_costo' => mb_substr(trim((string) ($in['centroCosto'] ?? '')), 0, 80) ?: null,
            'qr_raw' => mb_substr(trim((string) ($in['qrRaw'] ?? '')), 0, 500) ?: null,
        ],
        'alerts' => $alerts,
    ];
}

/** @return array{mime: string, bytes: string, hash: string}|null */
function grooflow_cashback_decode_photo(mixed $dataUrl, bool $required): ?array
{
    $dataUrl = is_string($dataUrl) ? trim($dataUrl) : '';
    if ($dataUrl === '') {
        if ($required) {
            throw new GrooflowValidation(['photo' => 'Adjunta la foto o PDF de la factura.']);
        }
        return null;
    }
    if (!preg_match('#^data:(image/jpeg|image/png|image/webp|application/pdf);base64,(.+)$#s', $dataUrl, $m)) {
        throw new GrooflowValidation(['photo' => 'Formato de archivo no admitido (JPG, PNG, WEBP o PDF).']);
    }
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || $bytes === '') {
        throw new GrooflowValidation(['photo' => 'No se pudo leer el archivo adjunto.']);
    }
    if (strlen($bytes) > GROOFLOW_CASHBACK_PHOTO_MAX_BYTES) {
        throw new GrooflowValidation(['photo' => 'El archivo supera 2.5 MB. Toma la foto de nuevo o comprime el PDF.']);
    }

    return ['mime' => $m[1], 'bytes' => $bytes, 'hash' => hash('sha256', $bytes)];
}

/** @return array<string, mixed> */
function grooflow_cashback_row_to_app(array $row): array
{
    return [
        'id' => (string) $row['id'],
        'usuarioId' => (string) $row['usuario_id'],
        'usuarioNombre' => (string) $row['usuario_nombre'],
        'colaboradorBukId' => $row['colaborador_buk_id'] !== null ? (int) $row['colaborador_buk_id'] : null,
        'colaboradorDoc' => $row['colaborador_doc'],
        'sede' => $row['sede'],
        'emisorRuc' => (string) $row['emisor_ruc'],
        'emisorNombre' => $row['emisor_nombre'],
        'emisorEstado' => $row['emisor_estado'],
        'emisorCondicion' => $row['emisor_condicion'],
        'tipoDoc' => (string) $row['tipo_doc'],
        'serie' => (string) $row['serie'],
        'numero' => (string) $row['numero'],
        'fechaEmision' => (string) $row['fecha_emision'],
        'periodo' => (string) $row['periodo'],
        'base' => (float) $row['base'],
        'igv' => (float) $row['igv'],
        'total' => (float) $row['total'],
        'compradorRuc' => $row['comprador_ruc'],
        'categoria' => (string) $row['categoria'],
        'motivo' => (string) $row['motivo'],
        'centroCosto' => $row['centro_costo'],
        'alertas' => (array) (grooflow_json_decode($row['alertas'] ?? null) ?? []),
        'estado' => (string) $row['estado'],
        'cashbackMonto' => $row['cashback_monto'] !== null ? (float) $row['cashback_monto'] : null,
        'revisorNombre' => $row['revisor_nombre'],
        'revisadoAt' => $row['revisado_at'],
        'notaRevision' => $row['nota_revision'],
        'liquidacionId' => $row['liquidacion_id'] !== null ? (string) $row['liquidacion_id'] : null,
        'hasPhoto' => $row['photo_hash'] !== null,
        'createdAt' => (string) $row['created_at'],
        'updatedAt' => (string) $row['updated_at'],
    ];
}

/** @return array<string, mixed> */
function grooflow_cashback_find(PDO $pdo, int $id, bool $forUpdate = false): array
{
    $stmt = $pdo->prepare('SELECT * FROM grooflow_cashback_facturas WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Factura no encontrada');
    }
    return $row;
}

function grooflow_cashback_assert_unique(PDO $pdo, array $data, ?string $photoHash, int $exceptId): void
{
    $stmt = $pdo->prepare('
        SELECT id, usuario_nombre FROM grooflow_cashback_facturas
        WHERE emisor_ruc = ? AND tipo_doc = ? AND serie = ? AND numero = ? AND id <> ?
        LIMIT 1
    ');
    $stmt->execute([$data['emisor_ruc'], $data['tipo_doc'], $data['serie'], $data['numero'], $exceptId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new GrooflowConflict("La factura {$data['serie']}-{$data['numero']} de este emisor ya fue registrada.");
    }
    require_once __DIR__ . '/grooflow_receipts.php';
    if (grooflow_receipt_petty_cash_match($pdo, $data['emisor_ruc'], $data['serie'], $data['numero']) !== null) {
        throw new GrooflowConflict("La factura {$data['serie']}-{$data['numero']} ya fue rendida en Caja Chica (pagada con dinero de la empresa).");
    }
    if ($photoHash !== null) {
        $stmt = $pdo->prepare('SELECT id FROM grooflow_cashback_facturas WHERE photo_hash = ? AND id <> ? LIMIT 1');
        $stmt->execute([$photoHash, $exceptId]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new GrooflowConflict('Esta misma foto ya se usó en otra factura.');
        }
    }
}

/** @return array<string, mixed> */
function grooflow_cashback_balance_for_user(PDO $pdo, int $userId, array $settings): array
{
    $stmt = $pdo->prepare('
        SELECT estado, COUNT(*) AS n, COALESCE(SUM(cashback_monto), 0) AS cb, COALESCE(SUM(igv), 0) AS igv
        FROM grooflow_cashback_facturas WHERE usuario_id = ? GROUP BY estado
    ');
    $stmt->execute([$userId]);
    $by = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $by[(string) $r['estado']] = $r;
    }
    $pending = round((float) ($by['aprobada']['cb'] ?? 0), 2);
    $threshold = (float) $settings['releaseThreshold'];

    return [
        'acumulado' => $pending,
        'umbral' => $threshold,
        'faltante' => max(0, round($threshold - $pending, 2)),
        'liberable' => $pending > 0 && $pending >= $threshold,
        'enRevision' => (int) ($by['en_revision']['n'] ?? 0),
        'observadas' => (int) ($by['observada']['n'] ?? 0),
        'aprobadas' => (int) ($by['aprobada']['n'] ?? 0),
        'rechazadas' => (int) ($by['rechazada']['n'] ?? 0),
        'liquidado' => round((float) ($by['liquidada']['cb'] ?? 0), 2),
    ];
}

/** @return array<string, mixed> */
function grooflow_cashback_me(PDO $pdo): array
{
    grooflow_cashback_ensure_schema($pdo);
    $user = api_current_user() ?? [];
    $userId = (int) ($user['id'] ?? 0);
    $settings = grooflow_cashback_get_settings($pdo);
    $stmt = $pdo->prepare('SELECT * FROM grooflow_cashback_facturas WHERE usuario_id = ? ORDER BY fecha_emision DESC, id DESC LIMIT 300');
    $stmt->execute([$userId]);

    return [
        'invoices' => array_map('grooflow_cashback_row_to_app', $stmt->fetchAll(PDO::FETCH_ASSOC)),
        'balance' => grooflow_cashback_balance_for_user($pdo, $userId, $settings),
        'colaborador' => grooflow_cashback_colaborador_for_user($pdo, $userId),
        'settings' => $settings,
        'capabilities' => [
            'submit' => grooflow_cashback_can($pdo, 'agregar'),
            'review' => grooflow_cashback_can($pdo, 'editar'),
            'export' => grooflow_cashback_can($pdo, 'exportar'),
            'configure' => grooflow_cashback_can($pdo, 'configurar'),
        ],
    ];
}

/** @return array<string, mixed> */
function grooflow_cashback_create(PDO $pdo, array $in): array
{
    grooflow_cashback_ensure_schema($pdo);
    grooflow_cashback_require_action($pdo, 'agregar');
    $settings = grooflow_cashback_get_settings($pdo);
    $user = api_current_user() ?? [];
    $userId = (int) ($user['id'] ?? 0);
    $validated = grooflow_cashback_validate_input($settings, $in);
    $photo = grooflow_cashback_decode_photo($in['photo'] ?? null, true);
    $colab = grooflow_cashback_colaborador_for_user($pdo, $userId);
    $alerts = $validated['alerts'];
    if (!$colab['linked']) {
        $alerts[] = 'Usuario sin colaborador Buk vinculado.';
    }
    $data = $validated['data'];

    $id = grooflow_atomic($pdo, static function () use ($pdo, $data, $photo, $alerts, $colab, $user, $userId): int {
        grooflow_cashback_assert_unique($pdo, $data, $photo['hash'], 0);
        $pdo->prepare('
            INSERT INTO grooflow_cashback_facturas
                (usuario_id, usuario_nombre, colaborador_buk_id, colaborador_doc, sede,
                 emisor_ruc, emisor_nombre, emisor_estado, emisor_condicion, tipo_doc, serie, numero,
                 fecha_emision, periodo, base, igv, total, comprador_ruc, categoria, motivo, centro_costo,
                 qr_raw, photo_hash, alertas, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'en_revision\')
        ')->execute([
            $userId, grooflow_cashback_user_name($user), $colab['bukId'], $colab['documento'], $colab['sede'],
            $data['emisor_ruc'], $data['emisor_nombre'], $data['emisor_estado'], $data['emisor_condicion'],
            $data['tipo_doc'], $data['serie'], $data['numero'], $data['fecha_emision'], $data['periodo'],
            $data['base'], $data['igv'], $data['total'], $data['comprador_ruc'], $data['categoria'],
            $data['motivo'], $data['centro_costo'], $data['qr_raw'], $photo['hash'], grooflow_json_encode($alerts),
        ]);
        $newId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO grooflow_cashback_fotos (factura_id, mime, size_bytes, data) VALUES (?, ?, ?, ?)')
            ->execute([$newId, $photo['mime'], strlen($photo['bytes']), $photo['bytes']]);
        return $newId;
    });

    return grooflow_cashback_row_to_app(grooflow_cashback_find($pdo, $id));
}

/** El colaborador corrige una factura observada (vuelve a revisión). */
function grooflow_cashback_update_own(PDO $pdo, int $id, array $in): array
{
    grooflow_cashback_ensure_schema($pdo);
    $settings = grooflow_cashback_get_settings($pdo);
    $user = api_current_user() ?? [];
    $userId = (int) ($user['id'] ?? 0);
    $validated = grooflow_cashback_validate_input($settings, $in);
    $photo = grooflow_cashback_decode_photo($in['photo'] ?? null, false);
    $data = $validated['data'];
    $alerts = $validated['alerts'];

    grooflow_atomic($pdo, static function () use ($pdo, $id, $userId, $data, $photo, $alerts): void {
        $row = grooflow_cashback_find($pdo, $id, true);
        if ((int) $row['usuario_id'] !== $userId) {
            throw new RuntimeException('No tienes permiso para editar esta factura');
        }
        if (!in_array($row['estado'], ['en_revision', 'observada'], true)) {
            throw new InvalidArgumentException('Solo se pueden corregir facturas en revisión u observadas.');
        }
        if ((int) ($row['colaborador_buk_id'] ?? 0) === 0) {
            $alerts[] = 'Usuario sin colaborador Buk vinculado.';
        }
        grooflow_cashback_assert_unique($pdo, $data, $photo['hash'] ?? null, $id);
        $pdo->prepare('
            UPDATE grooflow_cashback_facturas SET
                emisor_ruc = ?, emisor_nombre = ?, emisor_estado = ?, emisor_condicion = ?, serie = ?, numero = ?,
                fecha_emision = ?, periodo = ?, base = ?, igv = ?, total = ?, comprador_ruc = ?, categoria = ?,
                motivo = ?, centro_costo = ?, qr_raw = COALESCE(?, qr_raw), photo_hash = COALESCE(?, photo_hash),
                alertas = ?, estado = \'en_revision\'
            WHERE id = ?
        ')->execute([
            $data['emisor_ruc'], $data['emisor_nombre'], $data['emisor_estado'], $data['emisor_condicion'],
            $data['serie'], $data['numero'], $data['fecha_emision'], $data['periodo'], $data['base'], $data['igv'],
            $data['total'], $data['comprador_ruc'], $data['categoria'], $data['motivo'], $data['centro_costo'],
            $data['qr_raw'], $photo['hash'] ?? null, grooflow_json_encode($alerts), $id,
        ]);
        if ($photo !== null) {
            $pdo->prepare('REPLACE INTO grooflow_cashback_fotos (factura_id, mime, size_bytes, data) VALUES (?, ?, ?, ?)')
                ->execute([$id, $photo['mime'], strlen($photo['bytes']), $photo['bytes']]);
        }
    });

    return grooflow_cashback_row_to_app(grooflow_cashback_find($pdo, $id));
}

function grooflow_cashback_delete_own(PDO $pdo, int $id): void
{
    grooflow_cashback_ensure_schema($pdo);
    $userId = (int) ((api_current_user() ?? [])['id'] ?? 0);
    // El contexto de permisos puede ejecutar DDL (sync de menú): resolverlo antes de abrir la transacción.
    $canDeleteAny = grooflow_cashback_can($pdo, 'eliminar');
    grooflow_atomic($pdo, static function () use ($pdo, $id, $userId, $canDeleteAny): void {
        $row = grooflow_cashback_find($pdo, $id, true);
        $owner = (int) $row['usuario_id'] === $userId;
        if (!$owner && !$canDeleteAny) {
            throw new RuntimeException('No tienes permiso para eliminar esta factura');
        }
        if (!in_array($row['estado'], ['en_revision', 'observada', 'rechazada'], true)) {
            throw new InvalidArgumentException('No se puede eliminar una factura aprobada o liquidada.');
        }
        $pdo->prepare('DELETE FROM grooflow_cashback_fotos WHERE factura_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM grooflow_cashback_facturas WHERE id = ?')->execute([$id]);
    });
}

/** @return array{dataUrl: string, mime: string} */
function grooflow_cashback_photo(PDO $pdo, int $id): array
{
    grooflow_cashback_ensure_schema($pdo);
    $row = grooflow_cashback_find($pdo, $id);
    $userId = (int) ((api_current_user() ?? [])['id'] ?? 0);
    if ((int) $row['usuario_id'] !== $userId && !grooflow_cashback_is_reviewer($pdo)) {
        throw new RuntimeException('No tienes permiso para ver este comprobante');
    }
    $stmt = $pdo->prepare('SELECT mime, data FROM grooflow_cashback_fotos WHERE factura_id = ?');
    $stmt->execute([$id]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($photo)) {
        throw new RuntimeException('Comprobante no encontrado');
    }

    return ['mime' => (string) $photo['mime'], 'dataUrl' => 'data:' . $photo['mime'] . ';base64,' . base64_encode((string) $photo['data'])];
}

/** @return list<array<string, mixed>> */
function grooflow_cashback_list(PDO $pdo, array $q): array
{
    grooflow_cashback_ensure_schema($pdo);
    if (!grooflow_cashback_is_reviewer($pdo)) {
        throw new RuntimeException('No tienes permiso para ver facturas de otros colaboradores');
    }
    $where = [];
    $params = [];
    $estado = trim((string) ($q['estado'] ?? ''));
    if ($estado !== '' && in_array($estado, GROOFLOW_CASHBACK_STATES, true)) {
        $where[] = 'estado = ?';
        $params[] = $estado;
    } elseif ($estado === 'pendientes') {
        $where[] = "estado IN ('en_revision', 'observada')";
    }
    foreach (['desde' => '>=', 'hasta' => '<='] as $key => $op) {
        $p = trim((string) ($q[$key] ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $p)) {
            $where[] = "periodo {$op} ?";
            $params[] = $p;
        }
    }
    $usuario = (int) ($q['usuarioId'] ?? 0);
    if ($usuario > 0) {
        $where[] = 'usuario_id = ?';
        $params[] = $usuario;
    }
    $search = trim((string) ($q['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(usuario_nombre LIKE ? OR emisor_ruc LIKE ? OR emisor_nombre LIKE ? OR CONCAT(serie, \'-\', numero) LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql = 'SELECT * FROM grooflow_cashback_facturas'
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY created_at DESC, id DESC LIMIT 1000';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map('grooflow_cashback_row_to_app', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** @return array<string, mixed> */
function grooflow_cashback_review(PDO $pdo, int $id, array $in): array
{
    grooflow_cashback_ensure_schema($pdo);
    grooflow_cashback_require_action($pdo, 'editar');
    $action = (string) ($in['action'] ?? '');
    if (!in_array($action, ['approve', 'observe', 'reject', 'reopen'], true)) {
        throw new InvalidArgumentException('Acción de revisión inválida.');
    }
    $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 500);
    if (in_array($action, ['observe', 'reject'], true) && mb_strlen($note) < 4) {
        throw new GrooflowValidation(['note' => 'Indica el motivo para el colaborador.']);
    }
    $settings = grooflow_cashback_get_settings($pdo);
    $user = api_current_user() ?? [];
    $reviewerId = (int) ($user['id'] ?? 0);
    $isAdmin = !empty(grooflow_access_context($pdo)['admin']);

    grooflow_atomic($pdo, static function () use ($pdo, $id, $action, $note, $settings, $user, $reviewerId, $isAdmin, $in): void {
        $row = grooflow_cashback_find($pdo, $id, true);
        if ((int) $row['usuario_id'] === $reviewerId && !$isAdmin) {
            throw new RuntimeException('No tienes permiso para revisar tus propias facturas');
        }
        $estado = (string) $row['estado'];
        if ($action === 'reopen') {
            if (!in_array($estado, ['aprobada', 'rechazada', 'observada'], true)) {
                throw new InvalidArgumentException('Solo se reabren facturas aprobadas (sin liquidar), rechazadas u observadas.');
            }
        } elseif (!in_array($estado, ['en_revision', 'observada'], true)) {
            throw new InvalidArgumentException('La factura ya fue revisada (' . $estado . ').');
        }

        $igv = (float) $row['igv'];
        $total = (float) $row['total'];
        $base = (float) $row['base'];
        if ($action === 'approve') {
            $fixIgv = grooflow_cashback_money($in['igv'] ?? null);
            $fixTotal = grooflow_cashback_money($in['total'] ?? null);
            if ($fixIgv !== null && $fixIgv > 0) {
                $igv = $fixIgv;
            }
            if ($fixTotal !== null && $fixTotal > 0) {
                $total = $fixTotal;
            }
            if ($igv >= $total) {
                throw new GrooflowValidation(['igv' => 'El IGV no puede ser mayor o igual al total.']);
            }
            $base = round($total - $igv, 2);
        }

        $next = match ($action) {
            'approve' => 'aprobada',
            'observe' => 'observada',
            'reject' => 'rechazada',
            default => 'en_revision',
        };
        $amount = $action === 'approve' ? grooflow_cashback_compute($settings, $igv, $total) : null;
        $snapshot = $action === 'approve' ? grooflow_json_encode([
            'calcMode' => $settings['calcMode'],
            'pctIgv' => $settings['pctIgv'],
            'pctTotal' => $settings['pctTotal'],
            'flatAmount' => $settings['flatAmount'],
            'capAtIgv' => $settings['capAtIgv'],
        ]) : null;

        $pdo->prepare('
            UPDATE grooflow_cashback_facturas SET
                estado = ?, igv = ?, total = ?, base = ?, cashback_monto = ?, regla_snapshot = ?,
                revisor_id = ?, revisor_nombre = ?, revisado_at = NOW(), nota_revision = ?
            WHERE id = ?
        ')->execute([
            $next, $igv, $total, $base, $amount, $snapshot,
            $reviewerId, grooflow_cashback_user_name($user), $note !== '' ? $note : null, $id,
        ]);
        grooflow_audit_insert($pdo, $user, 'cashback.review', [
            'entity' => 'cashback_factura',
            'entity_id' => (string) $id,
            'action' => $action,
            'from' => $estado,
            'to' => $next,
            'cashback' => $amount,
            'note' => $note,
        ]);
    });

    return grooflow_cashback_row_to_app(grooflow_cashback_find($pdo, $id));
}

/** @return list<array<string, mixed>> */
function grooflow_cashback_balances(PDO $pdo): array
{
    grooflow_cashback_ensure_schema($pdo);
    if (!grooflow_cashback_is_reviewer($pdo)) {
        throw new RuntimeException('No tienes permiso para ver saldos de colaboradores');
    }
    $settings = grooflow_cashback_get_settings($pdo);
    $rows = $pdo->query("
        SELECT usuario_id,
               MAX(usuario_nombre) AS usuario_nombre,
               MAX(colaborador_doc) AS colaborador_doc,
               MAX(sede) AS sede,
               MAX(colaborador_buk_id) AS colaborador_buk_id,
               SUM(CASE WHEN estado = 'aprobada' THEN cashback_monto ELSE 0 END) AS acumulado,
               SUM(CASE WHEN estado = 'aprobada' THEN igv ELSE 0 END) AS igv_acumulado,
               SUM(CASE WHEN estado = 'aprobada' THEN 1 ELSE 0 END) AS aprobadas,
               SUM(CASE WHEN estado IN ('en_revision', 'observada') THEN 1 ELSE 0 END) AS pendientes,
               SUM(CASE WHEN estado = 'liquidada' THEN cashback_monto ELSE 0 END) AS liquidado
        FROM grooflow_cashback_facturas
        GROUP BY usuario_id
        ORDER BY acumulado DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $threshold = (float) $settings['releaseThreshold'];

    return array_map(static function (array $r) use ($threshold): array {
        $acum = round((float) $r['acumulado'], 2);
        return [
            'usuarioId' => (string) $r['usuario_id'],
            'usuarioNombre' => (string) $r['usuario_nombre'],
            'colaboradorDoc' => $r['colaborador_doc'],
            'sede' => $r['sede'],
            'vinculado' => $r['colaborador_buk_id'] !== null,
            'acumulado' => $acum,
            'igvAcumulado' => round((float) $r['igv_acumulado'], 2),
            'aprobadas' => (int) $r['aprobadas'],
            'pendientes' => (int) $r['pendientes'],
            'liquidado' => round((float) $r['liquidado'], 2),
            'faltante' => max(0, round($threshold - $acum, 2)),
            'liberable' => $acum > 0 && $acum >= $threshold,
        ];
    }, $rows);
}

/** @return array<string, mixed> */
function grooflow_cashback_liquidation_to_app(array $row, bool $withLines): array
{
    $out = [
        'id' => (string) $row['id'],
        'periodo' => (string) $row['periodo'],
        'estado' => (string) $row['estado'],
        'tratamiento' => (string) $row['tratamiento'],
        'colaboradores' => (int) $row['colaboradores'],
        'facturas' => (int) $row['facturas'],
        'igvTotal' => (float) $row['igv_total'],
        'montoTotal' => (float) $row['monto_total'],
        'essaludTotal' => (float) $row['essalud_total'],
        'creadoPorNombre' => $row['creado_por_nombre'],
        'pagadoAt' => $row['pagado_at'],
        'nota' => $row['nota'],
        'createdAt' => (string) $row['created_at'],
    ];
    if ($withLines) {
        $out['lineas'] = (array) (grooflow_json_decode($row['lineas'] ?? null) ?? []);
    }
    return $out;
}

/** @return list<array<string, mixed>> */
function grooflow_cashback_liquidations(PDO $pdo): array
{
    grooflow_cashback_ensure_schema($pdo);
    if (!grooflow_cashback_is_reviewer($pdo)) {
        throw new RuntimeException('No tienes permiso para ver liquidaciones');
    }
    $rows = $pdo->query('SELECT * FROM grooflow_cashback_liquidaciones ORDER BY id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static fn (array $r): array => grooflow_cashback_liquidation_to_app($r, true), $rows);
}

/**
 * Genera un lote con los colaboradores cuyo acumulado aprobado alcanza el umbral
 * (o los indicados en usuarioIds con ignorarUmbral, p. ej. por cese).
 *
 * @return array<string, mixed>
 */
function grooflow_cashback_create_liquidation(PDO $pdo, array $in): array
{
    grooflow_cashback_ensure_schema($pdo);
    grooflow_cashback_require_action($pdo, 'configurar');
    $settings = grooflow_cashback_get_settings($pdo);
    $user = api_current_user() ?? [];
    $periodo = trim((string) ($in['periodo'] ?? ''));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) {
        $periodo = date('Y-m');
    }
    $only = array_values(array_filter(array_map('intval', (array) ($in['usuarioIds'] ?? [])), static fn (int $v): bool => $v > 0));
    $ignoreThreshold = ($in['ignorarUmbral'] ?? false) === true && $only !== [];
    $note = mb_substr(trim((string) ($in['nota'] ?? '')), 0, 500);

    $liqId = grooflow_atomic($pdo, static function () use ($pdo, $settings, $user, $periodo, $only, $ignoreThreshold, $note): int {
        $rows = $pdo->query("
            SELECT id, usuario_id, usuario_nombre, colaborador_doc, sede, igv, total, cashback_monto
            FROM grooflow_cashback_facturas
            WHERE estado = 'aprobada' AND liquidacion_id IS NULL
            ORDER BY usuario_id, fecha_emision
            FOR UPDATE
        ")->fetchAll(PDO::FETCH_ASSOC);
        $byUser = [];
        foreach ($rows as $r) {
            $uid = (int) $r['usuario_id'];
            if ($only !== [] && !in_array($uid, $only, true)) {
                continue;
            }
            $byUser[$uid] ??= [
                'usuarioId' => (string) $uid,
                'usuarioNombre' => (string) $r['usuario_nombre'],
                'colaboradorDoc' => $r['colaborador_doc'],
                'sede' => $r['sede'],
                'facturaIds' => [],
                'facturas' => 0,
                'igv' => 0.0,
                'total' => 0.0,
                'monto' => 0.0,
            ];
            $byUser[$uid]['facturaIds'][] = (int) $r['id'];
            $byUser[$uid]['facturas']++;
            $byUser[$uid]['igv'] += (float) $r['igv'];
            $byUser[$uid]['total'] += (float) $r['total'];
            $byUser[$uid]['monto'] += (float) $r['cashback_monto'];
        }
        $threshold = (float) $settings['releaseThreshold'];
        $rate = $settings['payoutTreatment'] === 'remuneracion' ? (float) $settings['essaludRate'] / 100 : 0.0;
        $lines = [];
        $ids = [];
        foreach ($byUser as $line) {
            $line['monto'] = round($line['monto'], 2);
            if ($line['monto'] <= 0 || (!$ignoreThreshold && $line['monto'] < $threshold)) {
                continue;
            }
            $line['igv'] = round($line['igv'], 2);
            $line['total'] = round($line['total'], 2);
            $line['essalud'] = round($line['monto'] * $rate, 2);
            $ids = array_merge($ids, $line['facturaIds']);
            $lines[] = $line;
        }
        if ($lines === []) {
            throw new InvalidArgumentException('No hay colaboradores con saldo liberable para liquidar.');
        }
        $sum = static fn (string $k): float => round(array_sum(array_column($lines, $k)), 2);
        $pdo->prepare('
            INSERT INTO grooflow_cashback_liquidaciones
                (periodo, estado, tratamiento, colaboradores, facturas, igv_total, monto_total, essalud_total,
                 lineas, creado_por, creado_por_nombre, nota)
            VALUES (?, \'generada\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $periodo, $settings['payoutTreatment'], count($lines), count($ids), $sum('igv'), $sum('monto'),
            $sum('essalud'), grooflow_json_encode($lines), (int) ($user['id'] ?? 0) ?: null,
            grooflow_cashback_user_name($user), $note !== '' ? $note : null,
        ]);
        $newId = (int) $pdo->lastInsertId();
        foreach (array_chunk($ids, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $pdo->prepare("UPDATE grooflow_cashback_facturas SET estado = 'liquidada', liquidacion_id = ? WHERE id IN ({$marks})")
                ->execute(array_merge([$newId], $chunk));
        }
        grooflow_audit_insert($pdo, $user, 'cashback.liquidacion', [
            'entity' => 'cashback_liquidacion',
            'entity_id' => (string) $newId,
            'periodo' => $periodo,
            'colaboradores' => count($lines),
            'monto' => $sum('monto'),
        ]);
        return $newId;
    });

    $stmt = $pdo->prepare('SELECT * FROM grooflow_cashback_liquidaciones WHERE id = ?');
    $stmt->execute([$liqId]);

    return grooflow_cashback_liquidation_to_app((array) $stmt->fetch(PDO::FETCH_ASSOC), true);
}

/** @return array<string, mixed> */
function grooflow_cashback_mark_liquidation_paid(PDO $pdo, int $id): array
{
    grooflow_cashback_ensure_schema($pdo);
    grooflow_cashback_require_action($pdo, 'configurar');
    $user = api_current_user() ?? [];
    $stmt = $pdo->prepare("UPDATE grooflow_cashback_liquidaciones SET estado = 'pagada', pagado_at = NOW() WHERE id = ? AND estado = 'generada'");
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        throw new InvalidArgumentException('La liquidación no existe o ya estaba pagada.');
    }
    grooflow_audit_insert($pdo, $user, 'cashback.liquidacion_pagada', ['entity' => 'cashback_liquidacion', 'entity_id' => (string) $id]);
    $stmt = $pdo->prepare('SELECT * FROM grooflow_cashback_liquidaciones WHERE id = ?');
    $stmt->execute([$id]);

    return grooflow_cashback_liquidation_to_app((array) $stmt->fetch(PDO::FETCH_ASSOC), true);
}

/** @return array<string, mixed> */
function grooflow_cashback_report(PDO $pdo, array $q): array
{
    grooflow_cashback_ensure_schema($pdo);
    if (!grooflow_cashback_is_reviewer($pdo)) {
        throw new RuntimeException('No tienes permiso para ver reportes de Cashback');
    }
    $where = ['1 = 1'];
    $params = [];
    foreach (['desde' => '>=', 'hasta' => '<='] as $key => $op) {
        $p = trim((string) ($q[$key] ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $p)) {
            $where[] = "periodo {$op} ?";
            $params[] = $p;
        }
    }
    $w = implode(' AND ', $where);
    $valid = "estado IN ('aprobada', 'liquidada')";
    $run = static function (string $sql) use ($pdo, $params): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $agg = "COUNT(*) AS facturas, COALESCE(SUM(base), 0) AS base, COALESCE(SUM(igv), 0) AS igv,
            COALESCE(SUM(total), 0) AS total, COALESCE(SUM(cashback_monto), 0) AS cashback";
    $cast = static fn (array $rows): array => array_map(static function (array $r): array {
        foreach (['base', 'igv', 'total', 'cashback'] as $k) {
            if (isset($r[$k])) {
                $r[$k] = round((float) $r[$k], 2);
            }
        }
        if (isset($r['facturas'])) {
            $r['facturas'] = (int) $r['facturas'];
        }
        if (isset($r['colaboradores'])) {
            $r['colaboradores'] = (int) $r['colaboradores'];
        }
        return $r;
    }, $rows);

    return [
        'byEstado' => $cast($run("SELECT estado, {$agg} FROM grooflow_cashback_facturas WHERE {$w} GROUP BY estado")),
        'monthly' => $cast($run("
            SELECT periodo, {$agg}, COUNT(DISTINCT usuario_id) AS colaboradores
            FROM grooflow_cashback_facturas WHERE {$w} AND {$valid} GROUP BY periodo ORDER BY periodo
        ")),
        'byCategoria' => $cast($run("SELECT categoria, {$agg} FROM grooflow_cashback_facturas WHERE {$w} AND {$valid} GROUP BY categoria ORDER BY igv DESC")),
        'bySede' => $cast($run("
            SELECT COALESCE(NULLIF(sede, ''), 'Sin sede') AS sede, {$agg}, COUNT(DISTINCT usuario_id) AS colaboradores
            FROM grooflow_cashback_facturas WHERE {$w} AND {$valid} GROUP BY 1 ORDER BY igv DESC
        ")),
        'topEmisores' => $cast($run("
            SELECT emisor_ruc AS ruc, MAX(emisor_nombre) AS nombre, {$agg}
            FROM grooflow_cashback_facturas WHERE {$w} AND {$valid} GROUP BY emisor_ruc ORDER BY total DESC LIMIT 10
        ")),
        'topColaboradores' => $cast($run("
            SELECT usuario_id AS usuarioId, MAX(usuario_nombre) AS nombre, {$agg}
            FROM grooflow_cashback_facturas WHERE {$w} AND {$valid} GROUP BY usuario_id ORDER BY igv DESC LIMIT 10
        ")),
        'settings' => grooflow_cashback_get_settings($pdo),
    ];
}

/** Despacha /cashback/*. Devuelve false si la ruta no pertenece al módulo. */
function grooflow_cashback_dispatch(PDO $pdo, string $path, string $method): bool
{
    if ($path !== '/cashback' && !str_starts_with($path, '/cashback/')) {
        return false;
    }
    grooflow_assert_module($pdo, [GROOFLOW_CASHBACK_MODULE]);

    if ($path === '/cashback/me' && $method === 'GET') {
        api_json_response(['ok' => true] + grooflow_cashback_me($pdo));
        return true;
    }
    if ($path === '/cashback/settings' && $method === 'GET') {
        api_json_response(['ok' => true, 'settings' => grooflow_cashback_get_settings($pdo)]);
        return true;
    }
    if ($path === '/cashback/settings' && $method === 'PUT') {
        api_json_response(['ok' => true, 'settings' => grooflow_cashback_save_settings($pdo, api_request_json())]);
        return true;
    }
    if ($path === '/cashback/invoices' && $method === 'GET') {
        api_json_response(['ok' => true, 'items' => grooflow_cashback_list($pdo, $_GET)]);
        return true;
    }
    if ($path === '/cashback/invoices' && $method === 'POST') {
        api_json_response(['ok' => true, 'item' => grooflow_cashback_create($pdo, api_request_json())]);
        return true;
    }
    if (preg_match('#^/cashback/invoices/(\d+)$#', $path, $m)) {
        if ($method === 'PUT') {
            api_json_response(['ok' => true, 'item' => grooflow_cashback_update_own($pdo, (int) $m[1], api_request_json())]);
            return true;
        }
        if ($method === 'DELETE') {
            grooflow_cashback_delete_own($pdo, (int) $m[1]);
            api_json_response(['ok' => true]);
            return true;
        }
    }
    if (preg_match('#^/cashback/invoices/(\d+)/photo$#', $path, $m) && $method === 'GET') {
        api_json_response(['ok' => true] + grooflow_cashback_photo($pdo, (int) $m[1]));
        return true;
    }
    if (preg_match('#^/cashback/invoices/(\d+)/review$#', $path, $m) && $method === 'POST') {
        api_json_response(['ok' => true, 'item' => grooflow_cashback_review($pdo, (int) $m[1], api_request_json())]);
        return true;
    }
    if ($path === '/cashback/balances' && $method === 'GET') {
        api_json_response(['ok' => true, 'items' => grooflow_cashback_balances($pdo)]);
        return true;
    }
    if ($path === '/cashback/liquidations' && $method === 'GET') {
        api_json_response(['ok' => true, 'items' => grooflow_cashback_liquidations($pdo)]);
        return true;
    }
    if ($path === '/cashback/liquidations' && $method === 'POST') {
        api_json_response(['ok' => true, 'item' => grooflow_cashback_create_liquidation($pdo, api_request_json())]);
        return true;
    }
    if (preg_match('#^/cashback/liquidations/(\d+)/paid$#', $path, $m) && $method === 'POST') {
        api_json_response(['ok' => true, 'item' => grooflow_cashback_mark_liquidation_paid($pdo, (int) $m[1])]);
        return true;
    }
    if ($path === '/cashback/report' && $method === 'GET') {
        api_json_response(['ok' => true] + grooflow_cashback_report($pdo, $_GET));
        return true;
    }

    api_json_response(['ok' => false, 'error' => 'Ruta de Cashback no encontrada'], 404);
    return true;
}
