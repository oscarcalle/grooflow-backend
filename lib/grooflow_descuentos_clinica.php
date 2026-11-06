<?php

declare(strict_types=1);

/**
 * Descuentos Clínica: amigos/familiares, colaboradores/excolaboradores e influencers.
 * Flujo: recepción registra → Gerencia/Auditoría aprueba → counter consulta.
 * Influencers: el admin carga fichas individuales (beneficios únicos por persona).
 */

const GROOFLOW_DESC_CLINICA_MODULE = 'Descuentos Clínica';
const GROOFLOW_DESC_CLINICA_TIPOS = ['amigo_familiar', 'colaborador', 'excolaborador', 'influencer'];
const GROOFLOW_DESC_CLINICA_ESTADOS = ['pendiente', 'aprobado', 'rechazado', 'anulado'];
const GROOFLOW_DESC_CLINICA_PHOTO_MAX = 2_500_000;

function grooflow_descuentos_clinica_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done || $pdo->inTransaction()) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_descuentos_clinica (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tipo VARCHAR(30) NOT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            invitante_nombre VARCHAR(190) NULL,
            propietario_nombre VARCHAR(190) NOT NULL,
            documento VARCHAR(20) NOT NULL,
            telefono VARCHAR(30) NOT NULL,
            mascota_nombre VARCHAR(120) NOT NULL,
            mascota_fecha_nac DATE NULL,
            raza VARCHAR(120) NULL,
            especie VARCHAR(80) NULL,
            sede VARCHAR(120) NULL,
            colaborador_buk_id INT UNSIGNED NULL,
            colaborador_nombre VARCHAR(190) NULL,
            relacion_nota VARCHAR(255) NULL,
            descuento_pct DECIMAL(5,2) NULL,
            vigencia_hasta DATE NULL,
            nota_solicitud VARCHAR(500) NULL,
            nota_revision VARCHAR(500) NULL,
            created_by INT UNSIGNED NULL,
            created_by_nombre VARCHAR(190) NULL,
            revisado_by INT UNSIGNED NULL,
            revisado_by_nombre VARCHAR(190) NULL,
            revisado_at DATETIME NULL,
            photo_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_gf_dc_estado (estado),
            KEY idx_gf_dc_tipo (tipo),
            KEY idx_gf_dc_doc (documento),
            KEY idx_gf_dc_tel (telefono),
            KEY idx_gf_dc_sede (sede)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_descuentos_clinica_fotos (
            solicitud_id BIGINT UNSIGNED NOT NULL,
            mime VARCHAR(80) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            data LONGBLOB NOT NULL,
            PRIMARY KEY (solicitud_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_descuentos_clinica_beneficios (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            programa VARCHAR(30) NOT NULL,
            texto VARCHAR(500) NOT NULL,
            nota VARCHAR(255) NULL,
            orden INT NOT NULL DEFAULT 0,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_gf_dc_ben_prog (programa, activo, orden)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if (!$pdo->query("SHOW COLUMNS FROM grooflow_descuentos_clinica LIKE 'beneficios_texto'")->fetch()) {
        $pdo->exec('ALTER TABLE grooflow_descuentos_clinica ADD COLUMN beneficios_texto TEXT NULL AFTER nota_solicitud');
    }
    if (!$pdo->query("SHOW COLUMNS FROM grooflow_descuentos_clinica LIKE 'plan_nombre'")->fetch()) {
        $pdo->exec('ALTER TABLE grooflow_descuentos_clinica ADD COLUMN plan_nombre VARCHAR(190) NULL AFTER beneficios_texto');
    }
    if (!$pdo->query("SHOW COLUMNS FROM grooflow_descuentos_clinica LIKE 'exclusiones_nota'")->fetch()) {
        $pdo->exec('ALTER TABLE grooflow_descuentos_clinica ADD COLUMN exclusiones_nota VARCHAR(500) NULL AFTER plan_nombre');
    }
    // Varias mascotas en una ficha de influencer (ej. "Eva y Luna").
    $pdo->exec('ALTER TABLE grooflow_descuentos_clinica MODIFY COLUMN mascota_nombre VARCHAR(255) NOT NULL');
    grooflow_descuentos_clinica_seed_beneficios($pdo);
    $done = true;
}

/** Carga inicial del catálogo (solo si está vacío). */
function grooflow_descuentos_clinica_seed_beneficios(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM grooflow_descuentos_clinica_beneficios')->fetchColumn();
    if ($count > 0) {
        return;
    }
    $seed = [
        'colaborador' => [
            ['Baño o grooming en la sede: 12 soles. Desmotado o deslanado: 50%. Petmovil: 35%.', null],
            ['Consulta general, gatos, dermatología y emergencia: 12 soles.', 'No aplica para especialidades externas.'],
            ['Rayos X: 50%.', null],
            ['Desparasitación: 80%.', null],
            ['Vacunas: 60%.', null],
            ['Hemograma: 30 soles.', null],
            ['Perfil hepático renal: 140 soles.', null],
            ['Examen de orina: 25 soles.', null],
            ['Pet shop: 10%.', null],
        ],
        'amigo_familiar' => [
            ['Baño y grooming canino: 50%.', null],
            ['Petmovil: 30%.', null],
            ['Consulta general y emergencia: 50%.', null],
            ['Consulta dermatológica: 30%.', null],
            ['Vacunas y desparasitación: 30%.', null],
            ['Internamiento: 20%.', null],
            ['Hemograma, perfil hepático y renal: 10%.', null],
            ['Antipulgas y farmacia: 10%.', null],
        ],
        'excolaborador' => [
            ['Antipulgas, farmacia y productos: 10%.', 'No hay descuento en groomers.pe.'],
        ],
    ];
    $stmt = $pdo->prepare('
        INSERT INTO grooflow_descuentos_clinica_beneficios (programa, texto, nota, orden, activo)
        VALUES (?, ?, ?, ?, 1)
    ');
    foreach ($seed as $programa => $rows) {
        $orden = 10;
        foreach ($rows as [$texto, $nota]) {
            $stmt->execute([$programa, $texto, $nota, $orden]);
            $orden += 10;
        }
    }
}

function grooflow_descuentos_clinica_can(PDO $pdo, string $action): bool
{
    $ctx = grooflow_access_context($pdo);
    if (!empty($ctx['admin'])) {
        return true;
    }

    return !empty($ctx['actions'][GROOFLOW_DESC_CLINICA_MODULE][$action]);
}

function grooflow_descuentos_clinica_require(PDO $pdo, string $action): void
{
    if (!grooflow_descuentos_clinica_can($pdo, $action)) {
        throw new RuntimeException('No tienes permiso para esta acción de Descuentos Clínica');
    }
}

function grooflow_descuentos_clinica_can_review(PDO $pdo): bool
{
    return grooflow_descuentos_clinica_can($pdo, 'editar')
        || grooflow_descuentos_clinica_can($pdo, 'configurar');
}

function grooflow_descuentos_clinica_str(mixed $v, int $max): ?string
{
    $s = trim((string) ($v ?? ''));

    return $s !== '' ? mb_substr($s, 0, $max) : null;
}

function grooflow_descuentos_clinica_doc(string $raw): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
}

function grooflow_descuentos_clinica_phone(string $raw): string
{
    $d = preg_replace('/\D/', '', $raw) ?? '';
    if (strlen($d) === 11 && str_starts_with($d, '51')) {
        $d = substr($d, 2);
    }

    return $d;
}

/** @return array{mime: string, bytes: string, hash: string}|null */
function grooflow_descuentos_clinica_decode_photo(mixed $dataUrl, bool $required): ?array
{
    $dataUrl = is_string($dataUrl) ? trim($dataUrl) : '';
    if ($dataUrl === '') {
        if ($required) {
            throw new GrooflowValidation(['photo' => 'Adjunta una foto de la mascota.']);
        }

        return null;
    }
    if (!preg_match('#^data:(image/jpeg|image/png|image/webp);base64,(.+)$#s', $dataUrl, $m)) {
        throw new GrooflowValidation(['photo' => 'Formato de foto no admitido (JPG, PNG o WEBP).']);
    }
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || $bytes === '') {
        throw new GrooflowValidation(['photo' => 'No se pudo leer la foto.']);
    }
    if (strlen($bytes) > GROOFLOW_DESC_CLINICA_PHOTO_MAX) {
        throw new GrooflowValidation(['photo' => 'La foto supera 2.5 MB.']);
    }

    return ['mime' => $m[1], 'bytes' => $bytes, 'hash' => hash('sha256', $bytes)];
}

/** @return array<string, mixed> */
function grooflow_descuentos_clinica_row_to_app(array $row): array
{
    return [
        'id' => (string) $row['id'],
        'tipo' => (string) $row['tipo'],
        'estado' => (string) $row['estado'],
        'invitanteNombre' => $row['invitante_nombre'],
        'propietarioNombre' => (string) $row['propietario_nombre'],
        'documento' => (string) $row['documento'],
        'telefono' => (string) $row['telefono'],
        'mascotaNombre' => (string) $row['mascota_nombre'],
        'mascotaFechaNac' => $row['mascota_fecha_nac'],
        'raza' => $row['raza'],
        'especie' => $row['especie'],
        'sede' => $row['sede'],
        'colaboradorBukId' => $row['colaborador_buk_id'] !== null ? (int) $row['colaborador_buk_id'] : null,
        'colaboradorNombre' => $row['colaborador_nombre'],
        'relacionNota' => $row['relacion_nota'],
        'descuentoPct' => $row['descuento_pct'] !== null ? (float) $row['descuento_pct'] : null,
        'vigenciaHasta' => $row['vigencia_hasta'],
        'notaSolicitud' => $row['nota_solicitud'],
        'beneficiosTexto' => $row['beneficios_texto'] ?? null,
        'planNombre' => $row['plan_nombre'] ?? null,
        'exclusionesNota' => $row['exclusiones_nota'] ?? null,
        'notaRevision' => $row['nota_revision'],
        'createdByNombre' => $row['created_by_nombre'],
        'revisadoByNombre' => $row['revisado_by_nombre'],
        'revisadoAt' => $row['revisado_at'],
        'hasPhoto' => $row['photo_hash'] !== null,
        'createdAt' => (string) $row['created_at'],
        'updatedAt' => (string) $row['updated_at'],
    ];
}

function grooflow_descuentos_clinica_find(PDO $pdo, int $id, bool $forUpdate = false): array
{
    $sql = 'SELECT * FROM grooflow_descuentos_clinica WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Solicitud no encontrada');
    }

    return $row;
}

/**
 * @param array<string, mixed> $in
 * @return array{data: array<string, mixed>}
 */
function grooflow_descuentos_clinica_validate(array $in): array
{
    $errors = [];
    $tipo = trim((string) ($in['tipo'] ?? ''));
    if (!in_array($tipo, GROOFLOW_DESC_CLINICA_TIPOS, true)) {
        $errors['tipo'] = 'Elige el tipo de programa.';
    }
    $isInfluencer = $tipo === 'influencer';
    $propietario = grooflow_descuentos_clinica_str($in['propietarioNombre'] ?? null, 190);
    if ($propietario === null || mb_strlen($propietario) < 3) {
        $errors['propietarioNombre'] = $isInfluencer
            ? 'Indica el nombre del influencer.'
            : 'Indica el nombre del propietario.';
    }
    $documento = grooflow_descuentos_clinica_doc((string) ($in['documento'] ?? ''));
    if ($isInfluencer) {
        if ($documento !== '' && (strlen($documento) < 8 || strlen($documento) > 12)) {
            $errors['documento'] = 'DNI/CE inválido.';
        }
    } elseif (strlen($documento) < 8 || strlen($documento) > 12) {
        $errors['documento'] = 'DNI/CE inválido.';
    }
    $telefono = grooflow_descuentos_clinica_phone((string) ($in['telefono'] ?? ''));
    if ($isInfluencer) {
        if ($telefono !== '' && (strlen($telefono) < 7 || strlen($telefono) > 15)) {
            $errors['telefono'] = 'Teléfono inválido.';
        }
    } elseif (strlen($telefono) < 7 || strlen($telefono) > 15) {
        $errors['telefono'] = 'Teléfono inválido.';
    }
    $mascota = grooflow_descuentos_clinica_str($in['mascotaNombre'] ?? null, 255);
    if ($mascota === null) {
        $errors['mascotaNombre'] = $isInfluencer
            ? 'Indica la(s) mascota(s) afiliada(s).'
            : 'Indica el nombre de la mascota.';
    }
    $beneficiosTexto = grooflow_descuentos_clinica_str($in['beneficiosTexto'] ?? null, 8000);
    $planNombre = grooflow_descuentos_clinica_str($in['planNombre'] ?? null, 190);
    $exclusionesNota = grooflow_descuentos_clinica_str($in['exclusionesNota'] ?? null, 500);
    if ($isInfluencer && ($beneficiosTexto === null || mb_strlen($beneficiosTexto) < 10)) {
        $errors['beneficiosTexto'] = 'Describe los beneficios específicos de este influencer.';
    }
    $fechaNac = trim((string) ($in['mascotaFechaNac'] ?? ''));
    $fechaOk = null;
    if ($fechaNac !== '') {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaNac);
        if (!$dt || $dt->format('Y-m-d') !== $fechaNac) {
            $errors['mascotaFechaNac'] = 'Fecha de nacimiento inválida.';
        } elseif ($dt > new DateTimeImmutable('today')) {
            $errors['mascotaFechaNac'] = 'La fecha no puede ser futura.';
        } else {
            $fechaOk = $fechaNac;
        }
    }
    $invitante = grooflow_descuentos_clinica_str($in['invitanteNombre'] ?? null, 190);
    if ($tipo === 'amigo_familiar' && ($invitante === null || mb_strlen($invitante) < 3)) {
        $errors['invitanteNombre'] = 'Indica quién invitó al programa.';
    }
    $colabNombre = grooflow_descuentos_clinica_str($in['colaboradorNombre'] ?? null, 190);
    $colabBuk = isset($in['colaboradorBukId']) ? (int) $in['colaboradorBukId'] : 0;
    if (in_array($tipo, ['colaborador', 'excolaborador'], true) && $colabNombre === null && $colabBuk <= 0) {
        $errors['colaboradorNombre'] = 'Indica el colaborador o excolaborador relacionado.';
    }
    $vigencia = trim((string) ($in['vigenciaHasta'] ?? ''));
    $vigenciaOk = null;
    if ($vigencia !== '') {
        $vdt = DateTimeImmutable::createFromFormat('!Y-m-d', $vigencia);
        if (!$vdt || $vdt->format('Y-m-d') !== $vigencia) {
            $errors['vigenciaHasta'] = 'Vigencia inválida.';
        } else {
            $vigenciaOk = $vigencia;
        }
    }
    $pct = null;
    if (array_key_exists('descuentoPct', $in) && $in['descuentoPct'] !== null && $in['descuentoPct'] !== '') {
        if (!is_numeric($in['descuentoPct'])) {
            $errors['descuentoPct'] = 'Porcentaje inválido.';
        } else {
            $pct = round((float) $in['descuentoPct'], 2);
            if ($pct < 0 || $pct > 100) {
                $errors['descuentoPct'] = 'El descuento debe estar entre 0 y 100.';
            }
        }
    }
    if ($errors !== []) {
        throw new GrooflowValidation($errors);
    }

    return [
        'data' => [
            'tipo' => $tipo,
            'invitante_nombre' => $invitante,
            'propietario_nombre' => $propietario,
            'documento' => $documento,
            'telefono' => $telefono,
            'mascota_nombre' => $mascota,
            'mascota_fecha_nac' => $fechaOk,
            'raza' => grooflow_descuentos_clinica_str($in['raza'] ?? null, 120),
            'especie' => grooflow_descuentos_clinica_str($in['especie'] ?? null, 80),
            'sede' => grooflow_descuentos_clinica_str($in['sede'] ?? null, 120),
            'colaborador_buk_id' => $colabBuk > 0 ? $colabBuk : null,
            'colaborador_nombre' => $colabNombre,
            'relacion_nota' => grooflow_descuentos_clinica_str($in['relacionNota'] ?? null, 255),
            'descuento_pct' => $pct,
            'vigencia_hasta' => $vigenciaOk,
            'nota_solicitud' => grooflow_descuentos_clinica_str($in['notaSolicitud'] ?? null, 500),
            'beneficios_texto' => $beneficiosTexto,
            'plan_nombre' => $planNombre,
            'exclusiones_nota' => $exclusionesNota,
        ],
    ];
}

function grooflow_descuentos_clinica_create(PDO $pdo, array $in): array
{
    grooflow_descuentos_clinica_ensure_schema($pdo);
    $validated = grooflow_descuentos_clinica_validate($in);
    $data = $validated['data'];
    $isInfluencer = $data['tipo'] === 'influencer';
    if ($isInfluencer) {
        // Fichas de influencer: solo admin/configurar; quedan vigentes al instante.
        grooflow_descuentos_clinica_require($pdo, 'configurar');
    } else {
        grooflow_descuentos_clinica_require($pdo, 'agregar');
    }
    $photo = grooflow_descuentos_clinica_decode_photo($in['photo'] ?? null, false);
    $user = api_current_user() ?? [];
    $userId = (int) ($user['id'] ?? 0);
    $userName = trim(((string) ($user['nombre'] ?? '')) . ' ' . ((string) ($user['apellido'] ?? '')));
    if ($userName === '') {
        $userName = (string) ($user['usuario'] ?? $user['email'] ?? ('Usuario ' . $userId));
    }
    $estado = $isInfluencer ? 'aprobado' : 'pendiente';

    $id = grooflow_atomic($pdo, static function () use ($pdo, $data, $photo, $userId, $userName, $user, $estado): int {
        $pdo->prepare('
            INSERT INTO grooflow_descuentos_clinica
                (tipo, estado, invitante_nombre, propietario_nombre, documento, telefono,
                 mascota_nombre, mascota_fecha_nac, raza, especie, sede,
                 colaborador_buk_id, colaborador_nombre, relacion_nota, descuento_pct, vigencia_hasta,
                 nota_solicitud, beneficios_texto, plan_nombre, exclusiones_nota,
                 created_by, created_by_nombre, photo_hash,
                 revisado_by, revisado_by_nombre, revisado_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, CASE WHEN ? = \'aprobado\' THEN NOW() ELSE NULL END)
        ')->execute([
            $data['tipo'], $estado, $data['invitante_nombre'], $data['propietario_nombre'], $data['documento'], $data['telefono'],
            $data['mascota_nombre'], $data['mascota_fecha_nac'], $data['raza'], $data['especie'], $data['sede'],
            $data['colaborador_buk_id'], $data['colaborador_nombre'], $data['relacion_nota'], $data['descuento_pct'],
            $data['vigencia_hasta'], $data['nota_solicitud'], $data['beneficios_texto'], $data['plan_nombre'],
            $data['exclusiones_nota'], $userId ?: null, mb_substr($userName, 0, 190),
            $photo['hash'] ?? null,
            $estado === 'aprobado' ? ($userId ?: null) : null,
            $estado === 'aprobado' ? mb_substr($userName, 0, 190) : null,
            $estado,
        ]);
        $newId = (int) $pdo->lastInsertId();
        if ($photo !== null) {
            $pdo->prepare('INSERT INTO grooflow_descuentos_clinica_fotos (solicitud_id, mime, size_bytes, data) VALUES (?, ?, ?, ?)')
                ->execute([$newId, $photo['mime'], strlen($photo['bytes']), $photo['bytes']]);
        }
        grooflow_audit_insert($pdo, $user, 'descuentos_clinica.create', [
            'entity' => 'descuentos_clinica',
            'entity_id' => (string) $newId,
            'tipo' => $data['tipo'],
            'documento' => $data['documento'],
        ]);

        return $newId;
    });

    return grooflow_descuentos_clinica_row_to_app(grooflow_descuentos_clinica_find($pdo, $id));
}

function grooflow_descuentos_clinica_update(PDO $pdo, int $id, array $in): array
{
    grooflow_descuentos_clinica_ensure_schema($pdo);
    $validated = grooflow_descuentos_clinica_validate($in);
    $photo = grooflow_descuentos_clinica_decode_photo($in['photo'] ?? null, false);
    $data = $validated['data'];
    $user = api_current_user() ?? [];
    $canConfigure = grooflow_descuentos_clinica_can($pdo, 'configurar');
    if ($data['tipo'] === 'influencer') {
        grooflow_descuentos_clinica_require($pdo, 'configurar');
    } else {
        grooflow_descuentos_clinica_require($pdo, 'editar');
    }

    grooflow_atomic($pdo, static function () use ($pdo, $id, $data, $photo, $user, $canConfigure): void {
        $row = grooflow_descuentos_clinica_find($pdo, $id, true);
        $estado = (string) $row['estado'];
        $isInfluencer = $data['tipo'] === 'influencer' || (string) $row['tipo'] === 'influencer';
        if ($isInfluencer) {
            if (!$canConfigure) {
                throw new RuntimeException('Solo el administrador puede editar fichas de influencer.');
            }
            if (!in_array($estado, ['pendiente', 'aprobado'], true)) {
                throw new InvalidArgumentException('Esta ficha de influencer no se puede editar en su estado actual.');
            }
        } elseif ($estado !== 'pendiente') {
            throw new InvalidArgumentException('Solo se pueden editar solicitudes pendientes.');
        }
        $pdo->prepare('
            UPDATE grooflow_descuentos_clinica SET
                tipo = ?, invitante_nombre = ?, propietario_nombre = ?, documento = ?, telefono = ?,
                mascota_nombre = ?, mascota_fecha_nac = ?, raza = ?, especie = ?, sede = ?,
                colaborador_buk_id = ?, colaborador_nombre = ?, relacion_nota = ?, descuento_pct = ?, vigencia_hasta = ?,
                nota_solicitud = ?, beneficios_texto = ?, plan_nombre = ?, exclusiones_nota = ?,
                photo_hash = COALESCE(?, photo_hash)
            WHERE id = ?
        ')->execute([
            $data['tipo'], $data['invitante_nombre'], $data['propietario_nombre'], $data['documento'], $data['telefono'],
            $data['mascota_nombre'], $data['mascota_fecha_nac'], $data['raza'], $data['especie'], $data['sede'],
            $data['colaborador_buk_id'], $data['colaborador_nombre'], $data['relacion_nota'], $data['descuento_pct'],
            $data['vigencia_hasta'], $data['nota_solicitud'], $data['beneficios_texto'], $data['plan_nombre'],
            $data['exclusiones_nota'], $photo['hash'] ?? null, $id,
        ]);
        if ($photo !== null) {
            $pdo->prepare('REPLACE INTO grooflow_descuentos_clinica_fotos (solicitud_id, mime, size_bytes, data) VALUES (?, ?, ?, ?)')
                ->execute([$id, $photo['mime'], strlen($photo['bytes']), $photo['bytes']]);
        }
        grooflow_audit_insert($pdo, $user, 'descuentos_clinica.update', [
            'entity' => 'descuentos_clinica',
            'entity_id' => (string) $id,
        ]);
    });

    return grooflow_descuentos_clinica_row_to_app(grooflow_descuentos_clinica_find($pdo, $id));
}

function grooflow_descuentos_clinica_review(PDO $pdo, int $id, array $in): array
{
    grooflow_descuentos_clinica_ensure_schema($pdo);
    if (!grooflow_descuentos_clinica_can_review($pdo)) {
        throw new RuntimeException('No tienes permiso para aprobar/rechazar descuentos');
    }
    $action = (string) ($in['action'] ?? '');
    if (!in_array($action, ['approve', 'reject', 'annul', 'reopen'], true)) {
        throw new InvalidArgumentException('Acción inválida.');
    }
    $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 500);
    if ($action === 'reject' && mb_strlen($note) < 4) {
        throw new GrooflowValidation(['note' => 'Indica el motivo del rechazo.']);
    }
    $user = api_current_user() ?? [];
    $userId = (int) ($user['id'] ?? 0);
    $userName = trim(((string) ($user['nombre'] ?? '')) . ' ' . ((string) ($user['apellido'] ?? '')));
    if ($userName === '') {
        $userName = (string) ($user['usuario'] ?? $user['email'] ?? ('Usuario ' . $userId));
    }

    grooflow_atomic($pdo, static function () use ($pdo, $id, $action, $note, $in, $user, $userId, $userName): void {
        $row = grooflow_descuentos_clinica_find($pdo, $id, true);
        $estado = (string) $row['estado'];
        $pct = $row['descuento_pct'] !== null ? (float) $row['descuento_pct'] : null;
        $vigencia = $row['vigencia_hasta'];
        if ($action === 'approve') {
            if ($estado !== 'pendiente') {
                throw new InvalidArgumentException('Solo se aprueban solicitudes pendientes.');
            }
            if (array_key_exists('descuentoPct', $in) && $in['descuentoPct'] !== null && $in['descuentoPct'] !== '') {
                $pct = round((float) $in['descuentoPct'], 2);
                if ($pct < 0 || $pct > 100) {
                    throw new GrooflowValidation(['descuentoPct' => 'El descuento debe estar entre 0 y 100.']);
                }
            }
            $vig = trim((string) ($in['vigenciaHasta'] ?? ''));
            if ($vig !== '') {
                $vdt = DateTimeImmutable::createFromFormat('!Y-m-d', $vig);
                if (!$vdt || $vdt->format('Y-m-d') !== $vig) {
                    throw new GrooflowValidation(['vigenciaHasta' => 'Vigencia inválida.']);
                }
                $vigencia = $vig;
            }
            $next = 'aprobado';
        } elseif ($action === 'reject') {
            if ($estado !== 'pendiente') {
                throw new InvalidArgumentException('Solo se rechazan solicitudes pendientes.');
            }
            $next = 'rechazado';
        } elseif ($action === 'annul') {
            if ($estado !== 'aprobado') {
                throw new InvalidArgumentException('Solo se anulan descuentos aprobados.');
            }
            $next = 'anulado';
        } else { // reopen
            if (!in_array($estado, ['rechazado', 'anulado'], true)) {
                throw new InvalidArgumentException('Solo se reabren rechazados o anulados.');
            }
            $next = 'pendiente';
            $pct = null;
            $vigencia = null;
        }
        $pdo->prepare('
            UPDATE grooflow_descuentos_clinica SET
                estado = ?, descuento_pct = ?, vigencia_hasta = ?, nota_revision = ?,
                revisado_by = ?, revisado_by_nombre = ?, revisado_at = NOW()
            WHERE id = ?
        ')->execute([
            $next, $pct, $vigencia, $note !== '' ? $note : ($row['nota_revision'] ?? null),
            $userId ?: null, mb_substr($userName, 0, 190), $id,
        ]);
        grooflow_audit_insert($pdo, $user, 'descuentos_clinica.review', [
            'entity' => 'descuentos_clinica',
            'entity_id' => (string) $id,
            'action' => $action,
            'from' => $estado,
            'to' => $next,
        ]);
    });

    return grooflow_descuentos_clinica_row_to_app(grooflow_descuentos_clinica_find($pdo, $id));
}

function grooflow_descuentos_clinica_delete(PDO $pdo, int $id): void
{
    grooflow_descuentos_clinica_ensure_schema($pdo);
    $user = api_current_user() ?? [];
    $canConfigure = grooflow_descuentos_clinica_can($pdo, 'configurar');
    if ($canConfigure) {
        // ok
    } else {
        grooflow_descuentos_clinica_require($pdo, 'eliminar');
    }
    grooflow_atomic($pdo, static function () use ($pdo, $id, $user, $canConfigure): void {
        $row = grooflow_descuentos_clinica_find($pdo, $id, true);
        $isInfluencer = (string) $row['tipo'] === 'influencer';
        if ((string) $row['estado'] === 'aprobado' && !($isInfluencer && $canConfigure)) {
            throw new InvalidArgumentException('Anula el descuento aprobado antes de eliminarlo.');
        }
        $pdo->prepare('DELETE FROM grooflow_descuentos_clinica_fotos WHERE solicitud_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM grooflow_descuentos_clinica WHERE id = ?')->execute([$id]);
        grooflow_audit_insert($pdo, $user, 'descuentos_clinica.delete', [
            'entity' => 'descuentos_clinica',
            'entity_id' => (string) $id,
        ]);
    });
}

/** @return list<array<string, mixed>> */
function grooflow_descuentos_clinica_list(PDO $pdo, array $q): array
{
    grooflow_descuentos_clinica_ensure_schema($pdo);
    grooflow_descuentos_clinica_require($pdo, 'ver');
    $where = [];
    $params = [];
    $tipo = trim((string) ($q['tipo'] ?? ''));
    if ($tipo !== '' && in_array($tipo, GROOFLOW_DESC_CLINICA_TIPOS, true)) {
        $where[] = 'tipo = ?';
        $params[] = $tipo;
    }
    $estado = trim((string) ($q['estado'] ?? ''));
    if ($estado !== '' && in_array($estado, GROOFLOW_DESC_CLINICA_ESTADOS, true)) {
        $where[] = 'estado = ?';
        $params[] = $estado;
    }
    $grupo = trim((string) ($q['grupo'] ?? ''));
    if ($grupo === 'amigos') {
        $where[] = "tipo = 'amigo_familiar'";
    } elseif ($grupo === 'colaboradores') {
        $where[] = "tipo IN ('colaborador', 'excolaborador')";
    } elseif ($grupo === 'influencers') {
        $where[] = "tipo = 'influencer'";
    }
    $search = trim((string) ($q['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(propietario_nombre LIKE ? OR documento LIKE ? OR telefono LIKE ? OR mascota_nombre LIKE ? OR invitante_nombre LIKE ? OR colaborador_nombre LIKE ? OR plan_nombre LIKE ? OR beneficios_texto LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
    }
    $sql = 'SELECT * FROM grooflow_descuentos_clinica'
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY FIELD(estado, \'pendiente\', \'aprobado\', \'rechazado\', \'anulado\'), updated_at DESC, id DESC LIMIT 1000';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map('grooflow_descuentos_clinica_row_to_app', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/** @return array{dataUrl: string, mime: string} */
function grooflow_descuentos_clinica_photo(PDO $pdo, int $id): array
{
    grooflow_descuentos_clinica_ensure_schema($pdo);
    grooflow_descuentos_clinica_require($pdo, 'ver');
    grooflow_descuentos_clinica_find($pdo, $id);
    $stmt = $pdo->prepare('SELECT mime, data FROM grooflow_descuentos_clinica_fotos WHERE solicitud_id = ?');
    $stmt->execute([$id]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($photo)) {
        throw new RuntimeException('Foto no encontrada');
    }

    return [
        'mime' => (string) $photo['mime'],
        'dataUrl' => 'data:' . $photo['mime'] . ';base64,' . base64_encode((string) $photo['data']),
    ];
}

/** @return array{pendientes: int, aprobados: int, amigos: int, colaboradores: int, influencers: int} */
function grooflow_descuentos_clinica_stats(PDO $pdo): array
{
    grooflow_descuentos_clinica_ensure_schema($pdo);
    grooflow_descuentos_clinica_require($pdo, 'ver');
    $row = $pdo->query("
        SELECT
            COALESCE(SUM(estado = 'pendiente'), 0) AS pendientes,
            COALESCE(SUM(estado = 'aprobado'), 0) AS aprobados,
            COALESCE(SUM(tipo = 'amigo_familiar'), 0) AS amigos,
            COALESCE(SUM(tipo IN ('colaborador', 'excolaborador')), 0) AS colaboradores,
            COALESCE(SUM(tipo = 'influencer'), 0) AS influencers
        FROM grooflow_descuentos_clinica
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'pendientes' => (int) ($row['pendientes'] ?? 0),
        'aprobados' => (int) ($row['aprobados'] ?? 0),
        'amigos' => (int) ($row['amigos'] ?? 0),
        'colaboradores' => (int) ($row['colaboradores'] ?? 0),
        'influencers' => (int) ($row['influencers'] ?? 0),
    ];
}

/** @return array<string, mixed> */
function grooflow_descuentos_clinica_beneficio_to_app(array $row): array
{
    return [
        'id' => (string) $row['id'],
        'programa' => (string) $row['programa'],
        'texto' => (string) $row['texto'],
        'nota' => $row['nota'],
        'orden' => (int) $row['orden'],
        'activo' => (int) ($row['activo'] ?? 1) === 1,
        'createdAt' => (string) $row['created_at'],
        'updatedAt' => (string) $row['updated_at'],
    ];
}

/** @return list<array<string, mixed>> */
function grooflow_descuentos_clinica_beneficios_list(PDO $pdo, array $q): array
{
    grooflow_descuentos_clinica_ensure_schema($pdo);
    grooflow_descuentos_clinica_require($pdo, 'ver');
    $where = ['activo = 1'];
    $params = [];
    $programa = trim((string) ($q['programa'] ?? ''));
    if ($programa !== '' && in_array($programa, GROOFLOW_DESC_CLINICA_TIPOS, true)) {
        $where[] = 'programa = ?';
        $params[] = $programa;
    }
    if (!empty($q['incluirInactivos']) && grooflow_descuentos_clinica_can($pdo, 'configurar')) {
        $where = array_values(array_filter($where, static fn ($w) => $w !== 'activo = 1'));
    }
    $sql = 'SELECT * FROM grooflow_descuentos_clinica_beneficios'
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY FIELD(programa, \'colaborador\', \'amigo_familiar\', \'excolaborador\'), orden ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map('grooflow_descuentos_clinica_beneficio_to_app', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function grooflow_descuentos_clinica_beneficio_create(PDO $pdo, array $in): array
{
    grooflow_descuentos_clinica_ensure_schema($pdo);
    grooflow_descuentos_clinica_require($pdo, 'configurar');
    $programa = trim((string) ($in['programa'] ?? ''));
    if (!in_array($programa, GROOFLOW_DESC_CLINICA_TIPOS, true)) {
        throw new GrooflowValidation(['programa' => 'Elige el programa.']);
    }
    $texto = grooflow_descuentos_clinica_str($in['texto'] ?? null, 500);
    if ($texto === null || mb_strlen($texto) < 3) {
        throw new GrooflowValidation(['texto' => 'Escribe el beneficio.']);
    }
    $nota = grooflow_descuentos_clinica_str($in['nota'] ?? null, 255);
    $orden = isset($in['orden']) && is_numeric($in['orden']) ? (int) $in['orden'] : 0;
    if ($orden <= 0) {
        $max = $pdo->prepare('SELECT COALESCE(MAX(orden), 0) FROM grooflow_descuentos_clinica_beneficios WHERE programa = ?');
        $max->execute([$programa]);
        $orden = ((int) $max->fetchColumn()) + 10;
    }
    $user = api_current_user() ?? [];
    $pdo->prepare('
        INSERT INTO grooflow_descuentos_clinica_beneficios (programa, texto, nota, orden, activo)
        VALUES (?, ?, ?, ?, 1)
    ')->execute([$programa, $texto, $nota, $orden]);
    $id = (int) $pdo->lastInsertId();
    grooflow_audit_insert($pdo, $user, 'descuentos_clinica.beneficio_create', [
        'entity' => 'descuentos_clinica_beneficio',
        'entity_id' => (string) $id,
        'programa' => $programa,
    ]);
    $stmt = $pdo->prepare('SELECT * FROM grooflow_descuentos_clinica_beneficios WHERE id = ?');
    $stmt->execute([$id]);

    return grooflow_descuentos_clinica_beneficio_to_app($stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $id]);
}

function grooflow_descuentos_clinica_beneficio_update(PDO $pdo, int $id, array $in): array
{
    grooflow_descuentos_clinica_ensure_schema($pdo);
    grooflow_descuentos_clinica_require($pdo, 'configurar');
    $stmt = $pdo->prepare('SELECT * FROM grooflow_descuentos_clinica_beneficios WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Beneficio no encontrado');
    }
    $programa = trim((string) ($in['programa'] ?? $row['programa']));
    if (!in_array($programa, GROOFLOW_DESC_CLINICA_TIPOS, true)) {
        throw new GrooflowValidation(['programa' => 'Elige el programa.']);
    }
    $texto = grooflow_descuentos_clinica_str($in['texto'] ?? $row['texto'], 500);
    if ($texto === null || mb_strlen($texto) < 3) {
        throw new GrooflowValidation(['texto' => 'Escribe el beneficio.']);
    }
    $nota = array_key_exists('nota', $in)
        ? grooflow_descuentos_clinica_str($in['nota'], 255)
        : $row['nota'];
    $orden = isset($in['orden']) && is_numeric($in['orden']) ? (int) $in['orden'] : (int) $row['orden'];
    $activo = array_key_exists('activo', $in) ? (!empty($in['activo']) ? 1 : 0) : (int) $row['activo'];
    $user = api_current_user() ?? [];
    $pdo->prepare('
        UPDATE grooflow_descuentos_clinica_beneficios
        SET programa = ?, texto = ?, nota = ?, orden = ?, activo = ?
        WHERE id = ?
    ')->execute([$programa, $texto, $nota, $orden, $activo, $id]);
    grooflow_audit_insert($pdo, $user, 'descuentos_clinica.beneficio_update', [
        'entity' => 'descuentos_clinica_beneficio',
        'entity_id' => (string) $id,
    ]);
    $stmt->execute([$id]);

    return grooflow_descuentos_clinica_beneficio_to_app($stmt->fetch(PDO::FETCH_ASSOC) ?: $row);
}

function grooflow_descuentos_clinica_beneficio_delete(PDO $pdo, int $id): void
{
    grooflow_descuentos_clinica_ensure_schema($pdo);
    grooflow_descuentos_clinica_require($pdo, 'configurar');
    $user = api_current_user() ?? [];
    $stmt = $pdo->prepare('SELECT id FROM grooflow_descuentos_clinica_beneficios WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Beneficio no encontrado');
    }
    $pdo->prepare('DELETE FROM grooflow_descuentos_clinica_beneficios WHERE id = ?')->execute([$id]);
    grooflow_audit_insert($pdo, $user, 'descuentos_clinica.beneficio_delete', [
        'entity' => 'descuentos_clinica_beneficio',
        'entity_id' => (string) $id,
    ]);
}

function grooflow_descuentos_clinica_dispatch(PDO $pdo, string $path, string $method): bool
{
    if ($path !== '/descuentos-clinica' && !str_starts_with($path, '/descuentos-clinica/')) {
        return false;
    }
    if ($path === '/descuentos-clinica/stats' && $method === 'GET') {
        api_json_response(['ok' => true, 'stats' => grooflow_descuentos_clinica_stats($pdo)]);

        return true;
    }
    if ($path === '/descuentos-clinica/beneficios' && $method === 'GET') {
        api_json_response(['ok' => true, 'items' => grooflow_descuentos_clinica_beneficios_list($pdo, $_GET)]);

        return true;
    }
    if ($path === '/descuentos-clinica/beneficios' && $method === 'POST') {
        api_json_response(['ok' => true, 'item' => grooflow_descuentos_clinica_beneficio_create($pdo, api_request_json())]);

        return true;
    }
    if (preg_match('#^/descuentos-clinica/beneficios/(\d+)$#', $path, $m)) {
        $id = (int) $m[1];
        if ($method === 'PUT') {
            api_json_response(['ok' => true, 'item' => grooflow_descuentos_clinica_beneficio_update($pdo, $id, api_request_json())]);

            return true;
        }
        if ($method === 'DELETE') {
            grooflow_descuentos_clinica_beneficio_delete($pdo, $id);
            api_json_response(['ok' => true]);

            return true;
        }
    }
    if ($path === '/descuentos-clinica/solicitudes' && $method === 'GET') {
        api_json_response(['ok' => true, 'items' => grooflow_descuentos_clinica_list($pdo, $_GET)]);

        return true;
    }
    if ($path === '/descuentos-clinica/solicitudes' && $method === 'POST') {
        api_json_response(['ok' => true, 'item' => grooflow_descuentos_clinica_create($pdo, api_request_json())]);

        return true;
    }
    if (preg_match('#^/descuentos-clinica/solicitudes/(\d+)$#', $path, $m)) {
        $id = (int) $m[1];
        if ($method === 'PUT') {
            api_json_response(['ok' => true, 'item' => grooflow_descuentos_clinica_update($pdo, $id, api_request_json())]);

            return true;
        }
        if ($method === 'DELETE') {
            grooflow_descuentos_clinica_delete($pdo, $id);
            api_json_response(['ok' => true]);

            return true;
        }
    }
    if (preg_match('#^/descuentos-clinica/solicitudes/(\d+)/review$#', $path, $m) && $method === 'POST') {
        api_json_response(['ok' => true, 'item' => grooflow_descuentos_clinica_review($pdo, (int) $m[1], api_request_json())]);

        return true;
    }
    if (preg_match('#^/descuentos-clinica/solicitudes/(\d+)/photo$#', $path, $m) && $method === 'GET') {
        api_json_response(['ok' => true] + grooflow_descuentos_clinica_photo($pdo, (int) $m[1]));

        return true;
    }
    api_json_response(['ok' => false, 'error' => 'Ruta de Descuentos Clínica no encontrada'], 404);

    return true;
}
