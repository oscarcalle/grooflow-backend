<?php

declare(strict_types=1);

/**
 * Postgrados: planificación de programas, módulos, temas teóricos y prácticas presenciales.
 * Enfoque: aprovechar visitas de profesores agrupando prácticas similares entre programas.
 */

const GROOFLOW_POSTGRADOS_MODULE = 'Postgrados';
const GROOFLOW_POSTGRADO_ESTADOS = ['borrador', 'activo', 'cerrado'];
const GROOFLOW_PRACTICA_ESTADOS = ['idea', 'tentativa', 'confirmada', 'ejecutada', 'cerrada'];

function grooflow_postgrados_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done || $pdo->inTransaction()) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_postgrados (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            titulo VARCHAR(190) NOT NULL,
            codigo VARCHAR(40) NULL,
            sede VARCHAR(120) NULL,
            descripcion TEXT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'activo',
            created_by INT UNSIGNED NULL,
            created_by_nombre VARCHAR(190) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_gf_pg_estado (estado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_postgrado_cohortes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            postgrado_id BIGINT UNSIGNED NOT NULL,
            nombre VARCHAR(120) NOT NULL,
            anio SMALLINT UNSIGNED NULL,
            fecha_inicio DATE NULL,
            fecha_fin DATE NULL,
            cupos INT UNSIGNED NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'activo',
            nota VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_gf_pg_coh_pg (postgrado_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_postgrado_modulos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            postgrado_id BIGINT UNSIGNED NOT NULL,
            cohorte_id BIGINT UNSIGNED NULL,
            titulo VARCHAR(190) NOT NULL,
            orden INT NOT NULL DEFAULT 10,
            prerequisito_modulo_id BIGINT UNSIGNED NULL,
            duracion_dias INT UNSIGNED NULL,
            nota VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_gf_pg_mod_pg (postgrado_id, orden)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_postgrado_temas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            modulo_id BIGINT UNSIGNED NOT NULL,
            titulo VARCHAR(255) NOT NULL,
            tipo VARCHAR(20) NOT NULL DEFAULT 'teorico',
            orden INT NOT NULL DEFAULT 10,
            enlace_externo VARCHAR(500) NULL,
            nota VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_gf_pg_tema_mod (modulo_id, orden)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_postgrado_profesores (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(190) NOT NULL,
            especialidad VARCHAR(190) NULL,
            pais_origen VARCHAR(120) NULL,
            email VARCHAR(190) NULL,
            telefono VARCHAR(40) NULL,
            nota VARCHAR(500) NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_postgrado_viajes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profesor_id BIGINT UNSIGNED NOT NULL,
            fecha_desde DATE NOT NULL,
            fecha_hasta DATE NOT NULL,
            sedes VARCHAR(255) NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'tentativa',
            nota VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_gf_pg_viaje_prof (profesor_id, fecha_desde)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grooflow_postgrado_practicas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            postgrado_id BIGINT UNSIGNED NOT NULL,
            cohorte_id BIGINT UNSIGNED NULL,
            modulo_id BIGINT UNSIGNED NULL,
            titulo VARCHAR(255) NOT NULL,
            tags VARCHAR(500) NULL,
            duracion_horas DECIMAL(6,2) NULL,
            sede VARCHAR(120) NULL,
            sala VARCHAR(120) NULL,
            equipo VARCHAR(255) NULL,
            profesor_id BIGINT UNSIGNED NULL,
            viaje_id BIGINT UNSIGNED NULL,
            fecha_tentativa DATE NULL,
            fecha_fin DATE NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'idea',
            nota VARCHAR(800) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_gf_pg_prac_pg (postgrado_id, estado),
            KEY idx_gf_pg_prac_fecha (fecha_tentativa),
            KEY idx_gf_pg_prac_viaje (viaje_id),
            KEY idx_gf_pg_prac_prof (profesor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!$pdo->query("SHOW COLUMNS FROM grooflow_postgrado_practicas LIKE 'orden'")->fetch()) {
        $pdo->exec('ALTER TABLE grooflow_postgrado_practicas ADD COLUMN orden INT NOT NULL DEFAULT 10 AFTER titulo');
        // Inicializa orden secuencial por postgrado.
        $pgs = $pdo->query('SELECT DISTINCT postgrado_id FROM grooflow_postgrado_practicas')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($pgs as $pgId) {
            $rows = $pdo->prepare('SELECT id FROM grooflow_postgrado_practicas WHERE postgrado_id=? ORDER BY id ASC');
            $rows->execute([(int) $pgId]);
            $i = 1;
            $upd = $pdo->prepare('UPDATE grooflow_postgrado_practicas SET orden=? WHERE id=?');
            foreach ($rows->fetchAll(PDO::FETCH_COLUMN) ?: [] as $pid) {
                $upd->execute([$i, (int) $pid]);
                $i++;
            }
        }
    }

    $done = true;
}

function grooflow_postgrados_can(PDO $pdo, string $action): bool
{
    $ctx = grooflow_access_context($pdo);
    if (!empty($ctx['admin'])) {
        return true;
    }

    return !empty($ctx['actions'][GROOFLOW_POSTGRADOS_MODULE][$action]);
}

function grooflow_postgrados_require(PDO $pdo, string $action): void
{
    if (!grooflow_postgrados_can($pdo, $action)) {
        throw new RuntimeException('No tienes permiso para esta acción de Postgrados');
    }
}

function grooflow_postgrados_str(mixed $v, int $max): ?string
{
    $s = trim((string) ($v ?? ''));

    return $s !== '' ? mb_substr($s, 0, $max) : null;
}

function grooflow_postgrados_date(?string $raw, string $field): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if (!$dt || $dt->format('Y-m-d') !== $raw) {
        throw new GrooflowValidation([$field => 'Fecha inválida.']);
    }

    return $raw;
}

function grooflow_postgrados_user_name(): array
{
    $user = api_current_user() ?? [];
    $userId = (int) ($user['id'] ?? 0);
    $userName = trim(((string) ($user['nombre'] ?? '')) . ' ' . ((string) ($user['apellido'] ?? '')));
    if ($userName === '') {
        $userName = (string) ($user['usuario'] ?? $user['email'] ?? ('Usuario ' . $userId));
    }

    return [$user, $userId, mb_substr($userName, 0, 190)];
}

/** @return list<string> */
function grooflow_postgrados_parse_tags(?string $raw): array
{
    $raw = mb_strtolower(trim((string) $raw));
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/[,;|]+/u', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $t = trim($p);
        if ($t !== '') {
            $out[$t] = $t;
        }
    }

    return array_values($out);
}

function grooflow_postgrados_similarity_score(array $a, array $b): float
{
    $tagsA = grooflow_postgrados_parse_tags($a['tags'] ?? null);
    $tagsB = grooflow_postgrados_parse_tags($b['tags'] ?? null);
    $tagScore = 0.0;
    if ($tagsA !== [] && $tagsB !== []) {
        $inter = array_intersect($tagsA, $tagsB);
        $union = array_unique(array_merge($tagsA, $tagsB));
        $tagScore = count($union) > 0 ? (count($inter) / count($union)) : 0.0;
    }

    $titleA = mb_strtolower((string) ($a['titulo'] ?? ''));
    $titleB = mb_strtolower((string) ($b['titulo'] ?? ''));
    similar_text($titleA, $titleB, $pct);
    $titleScore = $pct / 100.0;

    $sedeBonus = 0.0;
    $sa = trim((string) ($a['sede'] ?? ''));
    $sb = trim((string) ($b['sede'] ?? ''));
    if ($sa !== '' && $sb !== '' && mb_strtolower($sa) === mb_strtolower($sb)) {
        $sedeBonus = 0.1;
    }

    $profBonus = 0.0;
    if (!empty($a['profesor_id']) && !empty($b['profesor_id']) && (int) $a['profesor_id'] === (int) $b['profesor_id']) {
        $profBonus = 0.15;
    }

    return min(1.0, ($tagScore * 0.55) + ($titleScore * 0.35) + $sedeBonus + $profBonus);
}

/** @return array<string, mixed> */
function grooflow_postgrado_row(array $r): array
{
    return [
        'id' => (string) $r['id'],
        'titulo' => (string) $r['titulo'],
        'codigo' => $r['codigo'],
        'sede' => $r['sede'],
        'descripcion' => $r['descripcion'],
        'estado' => (string) $r['estado'],
        'createdByNombre' => $r['created_by_nombre'] ?? null,
        'createdAt' => (string) $r['created_at'],
        'updatedAt' => (string) $r['updated_at'],
    ];
}

function grooflow_postgrado_cohorte_row(array $r): array
{
    return [
        'id' => (string) $r['id'],
        'postgradoId' => (string) $r['postgrado_id'],
        'nombre' => (string) $r['nombre'],
        'anio' => $r['anio'] !== null ? (int) $r['anio'] : null,
        'fechaInicio' => $r['fecha_inicio'],
        'fechaFin' => $r['fecha_fin'],
        'cupos' => $r['cupos'] !== null ? (int) $r['cupos'] : null,
        'estado' => (string) $r['estado'],
        'nota' => $r['nota'],
        'createdAt' => (string) $r['created_at'],
        'updatedAt' => (string) $r['updated_at'],
    ];
}

function grooflow_postgrado_modulo_row(array $r): array
{
    return [
        'id' => (string) $r['id'],
        'postgradoId' => (string) $r['postgrado_id'],
        'cohorteId' => $r['cohorte_id'] !== null ? (string) $r['cohorte_id'] : null,
        'titulo' => (string) $r['titulo'],
        'orden' => (int) $r['orden'],
        'prerequisitoModuloId' => $r['prerequisito_modulo_id'] !== null ? (string) $r['prerequisito_modulo_id'] : null,
        'duracionDias' => $r['duracion_dias'] !== null ? (int) $r['duracion_dias'] : null,
        'nota' => $r['nota'],
        'createdAt' => (string) $r['created_at'],
        'updatedAt' => (string) $r['updated_at'],
    ];
}

function grooflow_postgrado_tema_row(array $r): array
{
    return [
        'id' => (string) $r['id'],
        'moduloId' => (string) $r['modulo_id'],
        'titulo' => (string) $r['titulo'],
        'tipo' => (string) $r['tipo'],
        'orden' => (int) $r['orden'],
        'enlaceExterno' => $r['enlace_externo'],
        'nota' => $r['nota'],
        'createdAt' => (string) $r['created_at'],
        'updatedAt' => (string) $r['updated_at'],
    ];
}

function grooflow_postgrado_profesor_row(array $r): array
{
    return [
        'id' => (string) $r['id'],
        'nombre' => (string) $r['nombre'],
        'especialidad' => $r['especialidad'],
        'paisOrigen' => $r['pais_origen'],
        'email' => $r['email'],
        'telefono' => $r['telefono'],
        'nota' => $r['nota'],
        'activo' => (int) ($r['activo'] ?? 1) === 1,
        'createdAt' => (string) $r['created_at'],
        'updatedAt' => (string) $r['updated_at'],
    ];
}

function grooflow_postgrado_viaje_row(array $r): array
{
    return [
        'id' => (string) $r['id'],
        'profesorId' => (string) $r['profesor_id'],
        'fechaDesde' => (string) $r['fecha_desde'],
        'fechaHasta' => (string) $r['fecha_hasta'],
        'sedes' => $r['sedes'],
        'estado' => (string) $r['estado'],
        'nota' => $r['nota'],
        'profesorNombre' => $r['profesor_nombre'] ?? null,
        'createdAt' => (string) $r['created_at'],
        'updatedAt' => (string) $r['updated_at'],
    ];
}

function grooflow_postgrado_practica_row(array $r): array
{
    return [
        'id' => (string) $r['id'],
        'postgradoId' => (string) $r['postgrado_id'],
        'cohorteId' => $r['cohorte_id'] !== null ? (string) $r['cohorte_id'] : null,
        'moduloId' => $r['modulo_id'] !== null ? (string) $r['modulo_id'] : null,
        'titulo' => (string) $r['titulo'],
        'orden' => isset($r['orden']) ? (int) $r['orden'] : 10,
        'tags' => $r['tags'],
        'duracionHoras' => $r['duracion_horas'] !== null ? (float) $r['duracion_horas'] : null,
        'sede' => $r['sede'],
        'sala' => $r['sala'],
        'equipo' => $r['equipo'],
        'profesorId' => $r['profesor_id'] !== null ? (string) $r['profesor_id'] : null,
        'viajeId' => $r['viaje_id'] !== null ? (string) $r['viaje_id'] : null,
        'fechaTentativa' => $r['fecha_tentativa'],
        'fechaFin' => $r['fecha_fin'],
        'estado' => (string) $r['estado'],
        'nota' => $r['nota'],
        'postgradoTitulo' => $r['postgrado_titulo'] ?? null,
        'moduloTitulo' => $r['modulo_titulo'] ?? null,
        'profesorNombre' => $r['profesor_nombre'] ?? null,
        'createdAt' => (string) $r['created_at'],
        'updatedAt' => (string) $r['updated_at'],
    ];
}

function grooflow_postgrados_board(PDO $pdo): array
{
    grooflow_postgrados_ensure_schema($pdo);
    grooflow_postgrados_require($pdo, 'ver');

    $programas = array_map(
        'grooflow_postgrado_row',
        $pdo->query('SELECT * FROM grooflow_postgrados ORDER BY estado ASC, titulo ASC')->fetchAll(PDO::FETCH_ASSOC) ?: []
    );
    $cohortes = array_map(
        'grooflow_postgrado_cohorte_row',
        $pdo->query('SELECT * FROM grooflow_postgrado_cohortes ORDER BY anio DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: []
    );
    $modulos = array_map(
        'grooflow_postgrado_modulo_row',
        $pdo->query('SELECT * FROM grooflow_postgrado_modulos ORDER BY postgrado_id, orden ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: []
    );
    $temas = array_map(
        'grooflow_postgrado_tema_row',
        $pdo->query('SELECT * FROM grooflow_postgrado_temas ORDER BY modulo_id, orden ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: []
    );
    $profesores = array_map(
        'grooflow_postgrado_profesor_row',
        $pdo->query('SELECT * FROM grooflow_postgrado_profesores ORDER BY activo DESC, nombre ASC')->fetchAll(PDO::FETCH_ASSOC) ?: []
    );
    $viajes = array_map(
        'grooflow_postgrado_viaje_row',
        $pdo->query('
            SELECT v.*, p.nombre AS profesor_nombre
            FROM grooflow_postgrado_viajes v
            LEFT JOIN grooflow_postgrado_profesores p ON p.id = v.profesor_id
            ORDER BY v.fecha_desde ASC
        ')->fetchAll(PDO::FETCH_ASSOC) ?: []
    );
    $practicas = array_map(
        'grooflow_postgrado_practica_row',
        $pdo->query('
            SELECT pr.*, pg.titulo AS postgrado_titulo, m.titulo AS modulo_titulo, p.nombre AS profesor_nombre
            FROM grooflow_postgrado_practicas pr
            LEFT JOIN grooflow_postgrados pg ON pg.id = pr.postgrado_id
            LEFT JOIN grooflow_postgrado_modulos m ON m.id = pr.modulo_id
            LEFT JOIN grooflow_postgrado_profesores p ON p.id = pr.profesor_id
            ORDER BY pr.postgrado_id ASC, pr.orden ASC, pr.id ASC
        ')->fetchAll(PDO::FETCH_ASSOC) ?: []
    );

    $byEstado = [];
    foreach (GROOFLOW_PRACTICA_ESTADOS as $e) {
        $byEstado[$e] = 0;
    }
    $sinFecha = 0;
    foreach ($practicas as $p) {
        $byEstado[$p['estado']] = ($byEstado[$p['estado']] ?? 0) + 1;
        if (empty($p['fechaTentativa']) && in_array($p['estado'], ['idea', 'tentativa', 'confirmada'], true)) {
            $sinFecha++;
        }
    }

    return [
        'programas' => $programas,
        'cohortes' => $cohortes,
        'modulos' => $modulos,
        'temas' => $temas,
        'profesores' => $profesores,
        'viajes' => $viajes,
        'practicas' => $practicas,
        'stats' => [
            'programas' => count($programas),
            'practicas' => count($practicas),
            'sinFecha' => $sinFecha,
            'viajes' => count($viajes),
            'profesores' => count(array_filter($profesores, static fn ($p) => $p['activo'])),
            'porEstado' => $byEstado,
        ],
        'capabilities' => [
            'ver' => true,
            'agregar' => grooflow_postgrados_can($pdo, 'agregar'),
            'editar' => grooflow_postgrados_can($pdo, 'editar'),
            'eliminar' => grooflow_postgrados_can($pdo, 'eliminar'),
            'configurar' => grooflow_postgrados_can($pdo, 'configurar'),
        ],
    ];
}

function grooflow_postgrados_upsert_programa(PDO $pdo, ?int $id, array $in): array
{
    grooflow_postgrados_ensure_schema($pdo);
    if ($id) {
        grooflow_postgrados_require($pdo, 'editar');
    } else {
        grooflow_postgrados_require($pdo, 'agregar');
    }
    $titulo = grooflow_postgrados_str($in['titulo'] ?? null, 190);
    if ($titulo === null) {
        throw new GrooflowValidation(['titulo' => 'Indica el título del postgrado.']);
    }
    $estado = trim((string) ($in['estado'] ?? 'activo'));
    if (!in_array($estado, GROOFLOW_POSTGRADO_ESTADOS, true)) {
        $estado = 'activo';
    }
    [$user, $userId, $userName] = grooflow_postgrados_user_name();
    $codigo = grooflow_postgrados_str($in['codigo'] ?? null, 40);
    $sede = grooflow_postgrados_str($in['sede'] ?? null, 120);
    $desc = grooflow_postgrados_str($in['descripcion'] ?? null, 4000);

    if ($id) {
        $pdo->prepare('UPDATE grooflow_postgrados SET titulo=?, codigo=?, sede=?, descripcion=?, estado=? WHERE id=?')
            ->execute([$titulo, $codigo, $sede, $desc, $estado, $id]);
    } else {
        $pdo->prepare('INSERT INTO grooflow_postgrados (titulo, codigo, sede, descripcion, estado, created_by, created_by_nombre) VALUES (?,?,?,?,?,?,?)')
            ->execute([$titulo, $codigo, $sede, $desc, $estado, $userId ?: null, $userName]);
        $id = (int) $pdo->lastInsertId();
    }
    grooflow_audit_insert($pdo, $user, $id ? 'postgrados.programa_upsert' : 'postgrados.programa_create', [
        'entity' => 'postgrado',
        'entity_id' => (string) $id,
    ]);
    $stmt = $pdo->prepare('SELECT * FROM grooflow_postgrados WHERE id=?');
    $stmt->execute([$id]);

    return grooflow_postgrado_row($stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $id, 'titulo' => $titulo, 'estado' => $estado, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'), 'codigo' => $codigo, 'sede' => $sede, 'descripcion' => $desc]);
}

function grooflow_postgrados_upsert_cohorte(PDO $pdo, ?int $id, array $in): array
{
    grooflow_postgrados_ensure_schema($pdo);
    grooflow_postgrados_require($pdo, $id ? 'editar' : 'agregar');
    $pgId = (int) ($in['postgradoId'] ?? 0);
    if ($pgId <= 0) {
        throw new GrooflowValidation(['postgradoId' => 'Elige el postgrado.']);
    }
    $nombre = grooflow_postgrados_str($in['nombre'] ?? null, 120);
    if ($nombre === null) {
        throw new GrooflowValidation(['nombre' => 'Indica el nombre de la cohorte.']);
    }
    $anio = isset($in['anio']) && is_numeric($in['anio']) ? (int) $in['anio'] : null;
    $cupos = isset($in['cupos']) && is_numeric($in['cupos']) ? (int) $in['cupos'] : null;
    $inicio = grooflow_postgrados_date($in['fechaInicio'] ?? null, 'fechaInicio');
    $fin = grooflow_postgrados_date($in['fechaFin'] ?? null, 'fechaFin');
    $estado = trim((string) ($in['estado'] ?? 'activo'));
    if (!in_array($estado, GROOFLOW_POSTGRADO_ESTADOS, true)) {
        $estado = 'activo';
    }
    $nota = grooflow_postgrados_str($in['nota'] ?? null, 500);
    if ($id) {
        $pdo->prepare('UPDATE grooflow_postgrado_cohortes SET postgrado_id=?, nombre=?, anio=?, fecha_inicio=?, fecha_fin=?, cupos=?, estado=?, nota=? WHERE id=?')
            ->execute([$pgId, $nombre, $anio, $inicio, $fin, $cupos, $estado, $nota, $id]);
    } else {
        $pdo->prepare('INSERT INTO grooflow_postgrado_cohortes (postgrado_id, nombre, anio, fecha_inicio, fecha_fin, cupos, estado, nota) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$pgId, $nombre, $anio, $inicio, $fin, $cupos, $estado, $nota]);
        $id = (int) $pdo->lastInsertId();
    }
    $stmt = $pdo->prepare('SELECT * FROM grooflow_postgrado_cohortes WHERE id=?');
    $stmt->execute([$id]);

    return grooflow_postgrado_cohorte_row($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
}

function grooflow_postgrados_upsert_modulo(PDO $pdo, ?int $id, array $in): array
{
    grooflow_postgrados_ensure_schema($pdo);
    grooflow_postgrados_require($pdo, $id ? 'editar' : 'agregar');
    $pgId = (int) ($in['postgradoId'] ?? 0);
    if ($pgId <= 0) {
        throw new GrooflowValidation(['postgradoId' => 'Elige el postgrado.']);
    }
    $titulo = grooflow_postgrados_str($in['titulo'] ?? null, 190);
    if ($titulo === null) {
        throw new GrooflowValidation(['titulo' => 'Indica el título del módulo.']);
    }
    if (array_key_exists('orden', $in) && is_numeric($in['orden'])) {
        $orden = max(1, (int) $in['orden']);
    } elseif ($id) {
        $cur = $pdo->prepare('SELECT orden FROM grooflow_postgrado_modulos WHERE id=?');
        $cur->execute([$id]);
        $orden = max(1, (int) ($cur->fetchColumn() ?: 1));
    } else {
        $mx = $pdo->prepare('SELECT COALESCE(MAX(orden), 0) FROM grooflow_postgrado_modulos WHERE postgrado_id=?');
        $mx->execute([$pgId]);
        $orden = ((int) $mx->fetchColumn()) + 1;
    }
    $cohorteId = isset($in['cohorteId']) && (int) $in['cohorteId'] > 0 ? (int) $in['cohorteId'] : null;
    $pre = isset($in['prerequisitoModuloId']) && (int) $in['prerequisitoModuloId'] > 0 ? (int) $in['prerequisitoModuloId'] : null;
    $dias = isset($in['duracionDias']) && is_numeric($in['duracionDias']) ? (int) $in['duracionDias'] : null;
    $nota = grooflow_postgrados_str($in['nota'] ?? null, 500);
    if ($id) {
        $pdo->prepare('UPDATE grooflow_postgrado_modulos SET postgrado_id=?, cohorte_id=?, titulo=?, orden=?, prerequisito_modulo_id=?, duracion_dias=?, nota=? WHERE id=?')
            ->execute([$pgId, $cohorteId, $titulo, $orden, $pre, $dias, $nota, $id]);
    } else {
        $pdo->prepare('INSERT INTO grooflow_postgrado_modulos (postgrado_id, cohorte_id, titulo, orden, prerequisito_modulo_id, duracion_dias, nota) VALUES (?,?,?,?,?,?,?)')
            ->execute([$pgId, $cohorteId, $titulo, $orden, $pre, $dias, $nota]);
        $id = (int) $pdo->lastInsertId();
    }
    $stmt = $pdo->prepare('SELECT * FROM grooflow_postgrado_modulos WHERE id=?');
    $stmt->execute([$id]);

    return grooflow_postgrado_modulo_row($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
}

function grooflow_postgrados_upsert_tema(PDO $pdo, ?int $id, array $in): array
{
    grooflow_postgrados_ensure_schema($pdo);
    grooflow_postgrados_require($pdo, $id ? 'editar' : 'agregar');
    $modId = (int) ($in['moduloId'] ?? 0);
    if ($modId <= 0) {
        throw new GrooflowValidation(['moduloId' => 'Elige el módulo.']);
    }
    $titulo = grooflow_postgrados_str($in['titulo'] ?? null, 255);
    if ($titulo === null) {
        throw new GrooflowValidation(['titulo' => 'Indica el tema.']);
    }
    $tipo = trim((string) ($in['tipo'] ?? 'teorico'));
    if (!in_array($tipo, ['teorico', 'practico'], true)) {
        $tipo = 'teorico';
    }
    if (array_key_exists('orden', $in) && is_numeric($in['orden'])) {
        $orden = max(1, (int) $in['orden']);
    } elseif ($id) {
        $cur = $pdo->prepare('SELECT orden FROM grooflow_postgrado_temas WHERE id=?');
        $cur->execute([$id]);
        $orden = max(1, (int) ($cur->fetchColumn() ?: 1));
    } else {
        $mx = $pdo->prepare('SELECT COALESCE(MAX(orden), 0) FROM grooflow_postgrado_temas WHERE modulo_id=?');
        $mx->execute([$modId]);
        $orden = ((int) $mx->fetchColumn()) + 1;
    }
    $enlace = grooflow_postgrados_str($in['enlaceExterno'] ?? null, 500);
    $nota = grooflow_postgrados_str($in['nota'] ?? null, 500);
    if ($id) {
        $pdo->prepare('UPDATE grooflow_postgrado_temas SET modulo_id=?, titulo=?, tipo=?, orden=?, enlace_externo=?, nota=? WHERE id=?')
            ->execute([$modId, $titulo, $tipo, $orden, $enlace, $nota, $id]);
    } else {
        $pdo->prepare('INSERT INTO grooflow_postgrado_temas (modulo_id, titulo, tipo, orden, enlace_externo, nota) VALUES (?,?,?,?,?,?)')
            ->execute([$modId, $titulo, $tipo, $orden, $enlace, $nota]);
        $id = (int) $pdo->lastInsertId();
    }
    $stmt = $pdo->prepare('SELECT * FROM grooflow_postgrado_temas WHERE id=?');
    $stmt->execute([$id]);

    return grooflow_postgrado_tema_row($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
}

function grooflow_postgrados_upsert_profesor(PDO $pdo, ?int $id, array $in): array
{
    grooflow_postgrados_ensure_schema($pdo);
    grooflow_postgrados_require($pdo, $id ? 'editar' : 'agregar');
    $nombre = grooflow_postgrados_str($in['nombre'] ?? null, 190);
    if ($nombre === null) {
        throw new GrooflowValidation(['nombre' => 'Indica el nombre del profesor.']);
    }
    $esp = grooflow_postgrados_str($in['especialidad'] ?? null, 190);
    $pais = grooflow_postgrados_str($in['paisOrigen'] ?? null, 120);
    $email = grooflow_postgrados_str($in['email'] ?? null, 190);
    $tel = grooflow_postgrados_str($in['telefono'] ?? null, 40);
    $nota = grooflow_postgrados_str($in['nota'] ?? null, 500);
    $activo = array_key_exists('activo', $in) ? (!empty($in['activo']) ? 1 : 0) : 1;
    if ($id) {
        $pdo->prepare('UPDATE grooflow_postgrado_profesores SET nombre=?, especialidad=?, pais_origen=?, email=?, telefono=?, nota=?, activo=? WHERE id=?')
            ->execute([$nombre, $esp, $pais, $email, $tel, $nota, $activo, $id]);
    } else {
        $pdo->prepare('INSERT INTO grooflow_postgrado_profesores (nombre, especialidad, pais_origen, email, telefono, nota, activo) VALUES (?,?,?,?,?,?,?)')
            ->execute([$nombre, $esp, $pais, $email, $tel, $nota, $activo]);
        $id = (int) $pdo->lastInsertId();
    }
    $stmt = $pdo->prepare('SELECT * FROM grooflow_postgrado_profesores WHERE id=?');
    $stmt->execute([$id]);

    return grooflow_postgrado_profesor_row($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
}

function grooflow_postgrados_upsert_viaje(PDO $pdo, ?int $id, array $in): array
{
    grooflow_postgrados_ensure_schema($pdo);
    grooflow_postgrados_require($pdo, $id ? 'editar' : 'agregar');
    $profId = (int) ($in['profesorId'] ?? 0);
    if ($profId <= 0) {
        throw new GrooflowValidation(['profesorId' => 'Elige el profesor.']);
    }
    $desde = grooflow_postgrados_date($in['fechaDesde'] ?? null, 'fechaDesde');
    $hasta = grooflow_postgrados_date($in['fechaHasta'] ?? null, 'fechaHasta');
    if ($desde === null || $hasta === null) {
        throw new GrooflowValidation(['fechaDesde' => 'Indica el rango del viaje.']);
    }
    if ($hasta < $desde) {
        throw new GrooflowValidation(['fechaHasta' => 'La fecha fin debe ser >= inicio.']);
    }
    $sedes = grooflow_postgrados_str($in['sedes'] ?? null, 255);
    $estado = trim((string) ($in['estado'] ?? 'tentativa'));
    if (!in_array($estado, ['tentativa', 'confirmada', 'cancelada', 'cerrada'], true)) {
        $estado = 'tentativa';
    }
    $nota = grooflow_postgrados_str($in['nota'] ?? null, 500);
    if ($id) {
        $pdo->prepare('UPDATE grooflow_postgrado_viajes SET profesor_id=?, fecha_desde=?, fecha_hasta=?, sedes=?, estado=?, nota=? WHERE id=?')
            ->execute([$profId, $desde, $hasta, $sedes, $estado, $nota, $id]);
    } else {
        $pdo->prepare('INSERT INTO grooflow_postgrado_viajes (profesor_id, fecha_desde, fecha_hasta, sedes, estado, nota) VALUES (?,?,?,?,?,?)')
            ->execute([$profId, $desde, $hasta, $sedes, $estado, $nota]);
        $id = (int) $pdo->lastInsertId();
    }
    $stmt = $pdo->prepare('
        SELECT v.*, p.nombre AS profesor_nombre
        FROM grooflow_postgrado_viajes v
        LEFT JOIN grooflow_postgrado_profesores p ON p.id = v.profesor_id
        WHERE v.id=?
    ');
    $stmt->execute([$id]);

    return grooflow_postgrado_viaje_row($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
}

function grooflow_postgrados_upsert_practica(PDO $pdo, ?int $id, array $in): array
{
    grooflow_postgrados_ensure_schema($pdo);
    grooflow_postgrados_require($pdo, $id ? 'editar' : 'agregar');
    $pgId = (int) ($in['postgradoId'] ?? 0);
    if ($pgId <= 0) {
        throw new GrooflowValidation(['postgradoId' => 'Elige el postgrado.']);
    }
    $titulo = grooflow_postgrados_str($in['titulo'] ?? null, 255);
    if ($titulo === null) {
        throw new GrooflowValidation(['titulo' => 'Indica el título de la práctica.']);
    }
    $estado = trim((string) ($in['estado'] ?? 'idea'));
    if (!in_array($estado, GROOFLOW_PRACTICA_ESTADOS, true)) {
        $estado = 'idea';
    }
    $tags = grooflow_postgrados_str($in['tags'] ?? null, 500);
    $horas = isset($in['duracionHoras']) && is_numeric($in['duracionHoras']) ? round((float) $in['duracionHoras'], 2) : null;
    $sede = grooflow_postgrados_str($in['sede'] ?? null, 120);
    $sala = grooflow_postgrados_str($in['sala'] ?? null, 120);
    $equipo = grooflow_postgrados_str($in['equipo'] ?? null, 255);
    $nota = grooflow_postgrados_str($in['nota'] ?? null, 800);
    $cohorteId = isset($in['cohorteId']) && (int) $in['cohorteId'] > 0 ? (int) $in['cohorteId'] : null;
    $moduloId = isset($in['moduloId']) && (int) $in['moduloId'] > 0 ? (int) $in['moduloId'] : null;
    $profesorId = isset($in['profesorId']) && (int) $in['profesorId'] > 0 ? (int) $in['profesorId'] : null;
    $viajeId = isset($in['viajeId']) && (int) $in['viajeId'] > 0 ? (int) $in['viajeId'] : null;
    $fecha = grooflow_postgrados_date($in['fechaTentativa'] ?? null, 'fechaTentativa');
    $fechaFin = grooflow_postgrados_date($in['fechaFin'] ?? null, 'fechaFin');
    if (array_key_exists('orden', $in) && is_numeric($in['orden'])) {
        $orden = max(1, (int) $in['orden']);
    } elseif ($id) {
        $cur = $pdo->prepare('SELECT orden FROM grooflow_postgrado_practicas WHERE id=?');
        $cur->execute([$id]);
        $orden = max(1, (int) ($cur->fetchColumn() ?: 1));
    } else {
        $mx = $pdo->prepare('SELECT COALESCE(MAX(orden), 0) FROM grooflow_postgrado_practicas WHERE postgrado_id=?');
        $mx->execute([$pgId]);
        $orden = ((int) $mx->fetchColumn()) + 1;
    }

    if ($id) {
        $pdo->prepare('
            UPDATE grooflow_postgrado_practicas SET
                postgrado_id=?, cohorte_id=?, modulo_id=?, titulo=?, orden=?, tags=?, duracion_horas=?,
                sede=?, sala=?, equipo=?, profesor_id=?, viaje_id=?, fecha_tentativa=?, fecha_fin=?, estado=?, nota=?
            WHERE id=?
        ')->execute([
            $pgId, $cohorteId, $moduloId, $titulo, $orden, $tags, $horas, $sede, $sala, $equipo,
            $profesorId, $viajeId, $fecha, $fechaFin, $estado, $nota, $id,
        ]);
    } else {
        $pdo->prepare('
            INSERT INTO grooflow_postgrado_practicas
                (postgrado_id, cohorte_id, modulo_id, titulo, orden, tags, duracion_horas, sede, sala, equipo,
                 profesor_id, viaje_id, fecha_tentativa, fecha_fin, estado, nota)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ')->execute([
            $pgId, $cohorteId, $moduloId, $titulo, $orden, $tags, $horas, $sede, $sala, $equipo,
            $profesorId, $viajeId, $fecha, $fechaFin, $estado, $nota,
        ]);
        $id = (int) $pdo->lastInsertId();
    }
    $stmt = $pdo->prepare('
        SELECT pr.*, pg.titulo AS postgrado_titulo, m.titulo AS modulo_titulo, p.nombre AS profesor_nombre
        FROM grooflow_postgrado_practicas pr
        LEFT JOIN grooflow_postgrados pg ON pg.id = pr.postgrado_id
        LEFT JOIN grooflow_postgrado_modulos m ON m.id = pr.modulo_id
        LEFT JOIN grooflow_postgrado_profesores p ON p.id = pr.profesor_id
        WHERE pr.id=?
    ');
    $stmt->execute([$id]);

    return grooflow_postgrado_practica_row($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
}

/** Reescribe orden 1..N en el scope indicado. */
function grooflow_postgrados_resequence(PDO $pdo, string $entity, ?int $scopeId): void
{
    if ($entity === 'modulo' && $scopeId) {
        $stmt = $pdo->prepare('SELECT id FROM grooflow_postgrado_modulos WHERE postgrado_id=? ORDER BY orden ASC, id ASC');
        $stmt->execute([$scopeId]);
        $upd = $pdo->prepare('UPDATE grooflow_postgrado_modulos SET orden=? WHERE id=?');
        $i = 1;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rowId) {
            $upd->execute([$i, (int) $rowId]);
            $i++;
        }

        return;
    }
    if ($entity === 'tema' && $scopeId) {
        $stmt = $pdo->prepare('SELECT id FROM grooflow_postgrado_temas WHERE modulo_id=? ORDER BY orden ASC, id ASC');
        $stmt->execute([$scopeId]);
        $upd = $pdo->prepare('UPDATE grooflow_postgrado_temas SET orden=? WHERE id=?');
        $i = 1;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rowId) {
            $upd->execute([$i, (int) $rowId]);
            $i++;
        }

        return;
    }
    if ($entity === 'practica' && $scopeId) {
        $stmt = $pdo->prepare('SELECT id FROM grooflow_postgrado_practicas WHERE postgrado_id=? ORDER BY orden ASC, id ASC');
        $stmt->execute([$scopeId]);
        $upd = $pdo->prepare('UPDATE grooflow_postgrado_practicas SET orden=? WHERE id=?');
        $i = 1;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rowId) {
            $upd->execute([$i, (int) $rowId]);
            $i++;
        }
    }
}

/**
 * @param list<int|string> $orderedIds
 * @return array{updated:int}
 */
function grooflow_postgrados_reorder(PDO $pdo, array $in): array
{
    grooflow_postgrados_ensure_schema($pdo);
    grooflow_postgrados_require($pdo, 'editar');
    $entity = trim((string) ($in['entity'] ?? ''));
    $ids = $in['orderedIds'] ?? [];
    if (!is_array($ids) || $ids === []) {
        throw new GrooflowValidation(['orderedIds' => 'Envía la lista ordenada.']);
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, static fn ($x) => $x > 0));
    if ($ids === []) {
        throw new GrooflowValidation(['orderedIds' => 'IDs inválidos.']);
    }

    if ($entity === 'modulo') {
        $upd = $pdo->prepare('UPDATE grooflow_postgrado_modulos SET orden=? WHERE id=?');
        foreach ($ids as $i => $id) {
            $upd->execute([$i + 1, $id]);
        }
        $pg = $pdo->prepare('SELECT postgrado_id FROM grooflow_postgrado_modulos WHERE id=?');
        $pg->execute([$ids[0]]);
        $pgId = (int) ($pg->fetchColumn() ?: 0);
        if ($pgId > 0) {
            grooflow_postgrados_resequence($pdo, 'modulo', $pgId);
        }
    } elseif ($entity === 'tema') {
        $upd = $pdo->prepare('UPDATE grooflow_postgrado_temas SET orden=? WHERE id=?');
        foreach ($ids as $i => $id) {
            $upd->execute([$i + 1, $id]);
        }
        $mod = $pdo->prepare('SELECT modulo_id FROM grooflow_postgrado_temas WHERE id=?');
        $mod->execute([$ids[0]]);
        $modId = (int) ($mod->fetchColumn() ?: 0);
        if ($modId > 0) {
            grooflow_postgrados_resequence($pdo, 'tema', $modId);
        }
    } elseif ($entity === 'practica') {
        $upd = $pdo->prepare('UPDATE grooflow_postgrado_practicas SET orden=? WHERE id=?');
        foreach ($ids as $i => $id) {
            $upd->execute([$i + 1, $id]);
        }
        $pg = $pdo->prepare('SELECT postgrado_id FROM grooflow_postgrado_practicas WHERE id=?');
        $pg->execute([$ids[0]]);
        $pgId = (int) ($pg->fetchColumn() ?: 0);
        if ($pgId > 0) {
            grooflow_postgrados_resequence($pdo, 'practica', $pgId);
        }
    } else {
        throw new GrooflowValidation(['entity' => 'Entidad no ordenable.']);
    }

    [$user] = grooflow_postgrados_user_name();
    grooflow_audit_insert($pdo, $user, 'postgrados.reorder', [
        'entity' => $entity,
        'count' => count($ids),
    ]);

    return ['updated' => count($ids)];
}

function grooflow_postgrados_delete(PDO $pdo, string $entity, int $id): void
{
    grooflow_postgrados_ensure_schema($pdo);
    grooflow_postgrados_require($pdo, 'eliminar');
    $map = [
        'programa' => 'grooflow_postgrados',
        'cohorte' => 'grooflow_postgrado_cohortes',
        'modulo' => 'grooflow_postgrado_modulos',
        'tema' => 'grooflow_postgrado_temas',
        'profesor' => 'grooflow_postgrado_profesores',
        'viaje' => 'grooflow_postgrado_viajes',
        'practica' => 'grooflow_postgrado_practicas',
    ];
    if (!isset($map[$entity])) {
        throw new InvalidArgumentException('Entidad inválida');
    }

    $reseqEntity = null;
    $reseqScope = null;

    if ($entity === 'programa') {
        $pdo->prepare('DELETE FROM grooflow_postgrado_practicas WHERE postgrado_id=?')->execute([$id]);
        $mods = $pdo->prepare('SELECT id FROM grooflow_postgrado_modulos WHERE postgrado_id=?');
        $mods->execute([$id]);
        foreach ($mods->fetchAll(PDO::FETCH_COLUMN) ?: [] as $mid) {
            $pdo->prepare('DELETE FROM grooflow_postgrado_temas WHERE modulo_id=?')->execute([(int) $mid]);
        }
        $pdo->prepare('DELETE FROM grooflow_postgrado_modulos WHERE postgrado_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM grooflow_postgrado_cohortes WHERE postgrado_id=?')->execute([$id]);
    } elseif ($entity === 'modulo') {
        $row = $pdo->prepare('SELECT postgrado_id FROM grooflow_postgrado_modulos WHERE id=?');
        $row->execute([$id]);
        $reseqScope = (int) ($row->fetchColumn() ?: 0);
        $reseqEntity = 'modulo';
        $pdo->prepare('DELETE FROM grooflow_postgrado_temas WHERE modulo_id=?')->execute([$id]);
        $pdo->prepare('UPDATE grooflow_postgrado_practicas SET modulo_id=NULL WHERE modulo_id=?')->execute([$id]);
    } elseif ($entity === 'tema') {
        $row = $pdo->prepare('SELECT modulo_id FROM grooflow_postgrado_temas WHERE id=?');
        $row->execute([$id]);
        $reseqScope = (int) ($row->fetchColumn() ?: 0);
        $reseqEntity = 'tema';
    } elseif ($entity === 'practica') {
        $row = $pdo->prepare('SELECT postgrado_id FROM grooflow_postgrado_practicas WHERE id=?');
        $row->execute([$id]);
        $reseqScope = (int) ($row->fetchColumn() ?: 0);
        $reseqEntity = 'practica';
    }

    $pdo->prepare('DELETE FROM ' . $map[$entity] . ' WHERE id=?')->execute([$id]);

    if ($reseqEntity && $reseqScope > 0) {
        grooflow_postgrados_resequence($pdo, $reseqEntity, $reseqScope);
    }

    [$user] = grooflow_postgrados_user_name();
    grooflow_audit_insert($pdo, $user, 'postgrados.delete', [
        'entity' => $entity,
        'entity_id' => (string) $id,
    ]);
}

/** @return array{pairs: list<array<string,mixed>>, recommendations: list<array<string,mixed>>} */
function grooflow_postgrados_similarity(PDO $pdo): array
{
    grooflow_postgrados_ensure_schema($pdo);
    grooflow_postgrados_require($pdo, 'ver');
    $rows = $pdo->query('
        SELECT pr.*, pg.titulo AS postgrado_titulo, p.nombre AS profesor_nombre
        FROM grooflow_postgrado_practicas pr
        LEFT JOIN grooflow_postgrados pg ON pg.id = pr.postgrado_id
        LEFT JOIN grooflow_postgrado_profesores p ON p.id = pr.profesor_id
        WHERE pr.estado IN (\'idea\', \'tentativa\', \'confirmada\')
        ORDER BY pr.id ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $pairs = [];
    $n = count($rows);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            if ((int) $rows[$i]['postgrado_id'] === (int) $rows[$j]['postgrado_id']) {
                continue;
            }
            $score = grooflow_postgrados_similarity_score($rows[$i], $rows[$j]);
            if ($score < 0.35) {
                continue;
            }
            $pairs[] = [
                'score' => round($score * 100),
                'a' => grooflow_postgrado_practica_row($rows[$i]),
                'b' => grooflow_postgrado_practica_row($rows[$j]),
            ];
        }
    }
    usort($pairs, static fn ($x, $y) => $y['score'] <=> $x['score']);
    $pairs = array_slice($pairs, 0, 40);

    $viajes = $pdo->query('
        SELECT v.*, p.nombre AS profesor_nombre
        FROM grooflow_postgrado_viajes v
        LEFT JOIN grooflow_postgrado_profesores p ON p.id = v.profesor_id
        WHERE v.estado IN (\'tentativa\', \'confirmada\') AND v.fecha_hasta >= CURDATE()
        ORDER BY v.fecha_desde ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $recommendations = [];
    foreach ($viajes as $viaje) {
        $candidates = [];
        foreach ($rows as $pr) {
            if (!empty($pr['viaje_id']) && (int) $pr['viaje_id'] === (int) $viaje['id']) {
                continue;
            }
            $matchProf = empty($pr['profesor_id']) || (int) $pr['profesor_id'] === (int) $viaje['profesor_id'];
            if (!$matchProf) {
                continue;
            }
            $tagBoost = 0;
            if (!empty($pr['profesor_id']) && (int) $pr['profesor_id'] === (int) $viaje['profesor_id']) {
                $tagBoost = 20;
            }
            $candidates[] = [
                'practica' => grooflow_postgrado_practica_row($pr),
                'fit' => 60 + $tagBoost,
            ];
        }
        usort($candidates, static fn ($a, $b) => $b['fit'] <=> $a['fit']);
        $recommendations[] = [
            'viaje' => grooflow_postgrado_viaje_row($viaje),
            'sugeridas' => array_slice($candidates, 0, 8),
            'mensaje' => sprintf(
                'Ventana %s–%s (%s): agrupa prácticas afines para maximizar la visita.',
                $viaje['fecha_desde'],
                $viaje['fecha_hasta'],
                $viaje['profesor_nombre'] ?? 'profesor'
            ),
        ];
    }

    return ['pairs' => $pairs, 'recommendations' => $recommendations];
}

/** @return array{inicio:string, fin:string} */
function grooflow_postgrados_practica_span(array $pr): array
{
    $inicio = (string) ($pr['fecha_tentativa'] ?? $pr['fechaTentativa'] ?? '');
    $fin = (string) ($pr['fecha_fin'] ?? $pr['fechaFin'] ?? '');
    if ($inicio === '') {
        return ['inicio' => '', 'fin' => ''];
    }
    if ($fin === '') {
        $horas = (float) ($pr['duracion_horas'] ?? $pr['duracionHoras'] ?? 8);
        $dias = max(0, (int) ceil($horas / 8) - 1);
        $fin = date('Y-m-d', strtotime($inicio . ' +' . $dias . ' day'));
    }

    return ['inicio' => $inicio, 'fin' => $fin];
}

function grooflow_postgrados_ranges_overlap(string $a1, string $a2, string $b1, string $b2): bool
{
    if ($a1 === '' || $b1 === '') {
        return false;
    }

    return $a1 <= $b2 && $b1 <= $a2;
}

/** @return list<array<string,mixed>> */
function grooflow_postgrados_conflicts(PDO $pdo): array
{
    grooflow_postgrados_ensure_schema($pdo);
    grooflow_postgrados_require($pdo, 'ver');
    $rows = $pdo->query('
        SELECT pr.*, pg.titulo AS postgrado_titulo, p.nombre AS profesor_nombre
        FROM grooflow_postgrado_practicas pr
        LEFT JOIN grooflow_postgrados pg ON pg.id = pr.postgrado_id
        LEFT JOIN grooflow_postgrado_profesores p ON p.id = pr.profesor_id
        WHERE pr.fecha_tentativa IS NOT NULL
          AND pr.estado IN (\'idea\', \'tentativa\', \'confirmada\')
        ORDER BY pr.fecha_tentativa ASC, pr.id ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $conflicts = [];
    $n = count($rows);
    for ($i = 0; $i < $n; $i++) {
        $sa = grooflow_postgrados_practica_span($rows[$i]);
        for ($j = $i + 1; $j < $n; $j++) {
            $sb = grooflow_postgrados_practica_span($rows[$j]);
            if (!grooflow_postgrados_ranges_overlap($sa['inicio'], $sa['fin'], $sb['inicio'], $sb['fin'])) {
                continue;
            }
            $reasons = [];
            if (!empty($rows[$i]['profesor_id']) && (int) $rows[$i]['profesor_id'] === (int) $rows[$j]['profesor_id']) {
                $reasons[] = 'profesor';
            }
            $salaA = trim(mb_strtolower((string) ($rows[$i]['sala'] ?? '')));
            $salaB = trim(mb_strtolower((string) ($rows[$j]['sala'] ?? '')));
            if ($salaA !== '' && $salaA === $salaB) {
                $reasons[] = 'sala';
            }
            $eqA = trim(mb_strtolower((string) ($rows[$i]['equipo'] ?? '')));
            $eqB = trim(mb_strtolower((string) ($rows[$j]['equipo'] ?? '')));
            if ($eqA !== '' && $eqA === $eqB) {
                $reasons[] = 'equipo';
            }
            if ($reasons === []) {
                continue;
            }
            $conflicts[] = [
                'tipo' => implode('+', $reasons),
                'severidad' => in_array('profesor', $reasons, true) ? 'alta' : 'media',
                'mensaje' => sprintf(
                    'Solape de %s: «%s» y «%s» (%s–%s).',
                    implode('/', $reasons),
                    $rows[$i]['titulo'],
                    $rows[$j]['titulo'],
                    $sa['inicio'],
                    $sa['fin']
                ),
                'a' => grooflow_postgrado_practica_row($rows[$i]),
                'b' => grooflow_postgrado_practica_row($rows[$j]),
            ];
        }
    }

    // Prerrequisito de módulo: práctica de módulo B antes que la de módulo A (prerequisito).
    $mods = $pdo->query('SELECT id, titulo, orden, prerequisito_modulo_id FROM grooflow_postgrado_modulos')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $modById = [];
    foreach ($mods as $m) {
        $modById[(int) $m['id']] = $m;
    }
    $withMod = array_values(array_filter($rows, static fn ($r) => !empty($r['modulo_id']) && !empty($r['fecha_tentativa'])));
    $wm = count($withMod);
    for ($i = 0; $i < $wm; $i++) {
        for ($j = 0; $j < $wm; $j++) {
            if ($i === $j) {
                continue;
            }
            $mi = (int) $withMod[$i]['modulo_id'];
            $mj = (int) $withMod[$j]['modulo_id'];
            if ($mi === $mj || !isset($modById[$mj])) {
                continue;
            }
            $pre = $modById[$mj]['prerequisito_modulo_id'] !== null ? (int) $modById[$mj]['prerequisito_modulo_id'] : 0;
            if ($pre !== $mi) {
                continue;
            }
            // j requiere i; si j empieza antes que i → conflicto
            if ((string) $withMod[$j]['fecha_tentativa'] < (string) $withMod[$i]['fecha_tentativa']) {
                $conflicts[] = [
                    'tipo' => 'prerequisito',
                    'severidad' => 'alta',
                    'mensaje' => sprintf(
                        'Prerrequisito roto: «%s» (%s) está antes que «%s» (%s).',
                        $withMod[$j]['titulo'],
                        $withMod[$j]['fecha_tentativa'],
                        $withMod[$i]['titulo'],
                        $withMod[$i]['fecha_tentativa']
                    ),
                    'a' => grooflow_postgrado_practica_row($withMod[$i]),
                    'b' => grooflow_postgrado_practica_row($withMod[$j]),
                ];
            }
        }
    }

    usort($conflicts, static function ($x, $y) {
        $rank = ['alta' => 0, 'media' => 1, 'baja' => 2];

        return ($rank[$x['severidad']] ?? 9) <=> ($rank[$y['severidad']] ?? 9);
    });

    return array_slice($conflicts, 0, 60);
}

/**
 * Genera 3 escenarios de asignación de prácticas sin fecha a ventanas de viaje.
 *
 * @return list<array<string,mixed>>
 */
function grooflow_postgrados_scenarios(PDO $pdo): array
{
    grooflow_postgrados_ensure_schema($pdo);
    grooflow_postgrados_require($pdo, 'ver');

    $practicas = $pdo->query('
        SELECT pr.*, pg.titulo AS postgrado_titulo, p.nombre AS profesor_nombre
        FROM grooflow_postgrado_practicas pr
        LEFT JOIN grooflow_postgrados pg ON pg.id = pr.postgrado_id
        LEFT JOIN grooflow_postgrado_profesores p ON p.id = pr.profesor_id
        WHERE pr.estado IN (\'idea\', \'tentativa\')
          AND (pr.fecha_tentativa IS NULL OR pr.viaje_id IS NULL)
        ORDER BY pr.id ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $viajes = $pdo->query('
        SELECT v.*, p.nombre AS profesor_nombre
        FROM grooflow_postgrado_viajes v
        LEFT JOIN grooflow_postgrado_profesores p ON p.id = v.profesor_id
        WHERE v.estado IN (\'tentativa\', \'confirmada\') AND v.fecha_hasta >= CURDATE()
        ORDER BY v.fecha_desde ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($viajes === [] || $practicas === []) {
        return [];
    }

    $modes = [
        'optimo' => 'Óptimo: compacta días del visitante y agrupa afines.',
        'conservador' => 'Conservador: un día de holgura entre prácticas.',
        'urgente' => 'Urgente: prioriza el final de la ventana (cerrar pronto).',
    ];

    $out = [];
    foreach ($modes as $mode => $label) {
        $assignments = [];
        $used = [];
        foreach ($viajes as $viaje) {
            $cursor = $mode === 'urgente'
                ? (string) $viaje['fecha_hasta']
                : (string) $viaje['fecha_desde'];
            $hasta = (string) $viaje['fecha_hasta'];
            $desde = (string) $viaje['fecha_desde'];
            $dayStep = $mode === 'conservador' ? 2 : 1;
            $bucket = [];
            foreach ($practicas as $pr) {
                if (isset($used[(int) $pr['id']])) {
                    continue;
                }
                if (!empty($pr['profesor_id']) && (int) $pr['profesor_id'] !== (int) $viaje['profesor_id']) {
                    continue;
                }
                $bucket[] = $pr;
            }
            // Agrupar por tag principal para optimo
            if ($mode === 'optimo') {
                usort($bucket, static function ($a, $b) {
                    $ta = grooflow_postgrados_parse_tags($a['tags'] ?? null);
                    $tb = grooflow_postgrados_parse_tags($b['tags'] ?? null);

                    return ($ta[0] ?? '') <=> ($tb[0] ?? '');
                });
            }
            foreach ($bucket as $pr) {
                if ($mode === 'urgente') {
                    if ($cursor < $desde) {
                        break;
                    }
                    $fecha = $cursor;
                    $cursor = date('Y-m-d', strtotime($cursor . ' -' . $dayStep . ' day'));
                } else {
                    if ($cursor > $hasta) {
                        break;
                    }
                    $fecha = $cursor;
                    $cursor = date('Y-m-d', strtotime($cursor . ' +' . $dayStep . ' day'));
                }
                $used[(int) $pr['id']] = true;
                $assignments[] = [
                    'practicaId' => (string) $pr['id'],
                    'practicaTitulo' => (string) $pr['titulo'],
                    'postgradoTitulo' => $pr['postgrado_titulo'] ?? null,
                    'viajeId' => (string) $viaje['id'],
                    'profesorId' => (string) $viaje['profesor_id'],
                    'profesorNombre' => $viaje['profesor_nombre'] ?? null,
                    'fechaTentativa' => $fecha,
                    'estadoSugerido' => 'tentativa',
                ];
            }
        }
        $out[] = [
            'id' => $mode,
            'label' => $label,
            'asignaciones' => $assignments,
            'cubiertas' => count($assignments),
            'pendientes' => max(0, count($practicas) - count($assignments)),
        ];
    }

    return $out;
}

/** @return array{updated:int, items:list<array<string,mixed>>} */
function grooflow_postgrados_apply_viaje(PDO $pdo, array $in): array
{
    grooflow_postgrados_ensure_schema($pdo);
    grooflow_postgrados_require($pdo, 'editar');
    $viajeId = (int) ($in['viajeId'] ?? 0);
    if ($viajeId <= 0) {
        throw new GrooflowValidation(['viajeId' => 'Indica el viaje.']);
    }
    $stmt = $pdo->prepare('SELECT * FROM grooflow_postgrado_viajes WHERE id=?');
    $stmt->execute([$viajeId]);
    $viaje = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$viaje) {
        throw new GrooflowValidation(['viajeId' => 'Viaje no encontrado.']);
    }

    $fechasMap = [];
    if (isset($in['fechas']) && is_array($in['fechas'])) {
        foreach ($in['fechas'] as $k => $v) {
            $fid = (int) $k;
            $fd = grooflow_postgrados_date(is_string($v) ? $v : null, 'fecha');
            if ($fid > 0 && $fd !== null) {
                $fechasMap[$fid] = $fd;
            }
        }
    }

    $ids = $in['practicaIds'] ?? [];
    if ((!is_array($ids) || $ids === []) && $fechasMap !== []) {
        $ids = array_keys($fechasMap);
    }
    if (!is_array($ids) || $ids === []) {
        throw new GrooflowValidation(['practicaIds' => 'Elige al menos una práctica.']);
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $mode = trim((string) ($in['mode'] ?? 'optimo'));
    if (!in_array($mode, ['optimo', 'conservador', 'urgente'], true)) {
        $mode = 'optimo';
    }

    $dayStep = $mode === 'conservador' ? 2 : 1;
    $cursor = $mode === 'urgente' ? (string) $viaje['fecha_hasta'] : (string) $viaje['fecha_desde'];
    $desde = (string) $viaje['fecha_desde'];
    $hasta = (string) $viaje['fecha_hasta'];
    $updated = [];
    foreach ($ids as $pid) {
        if ($pid <= 0) {
            continue;
        }
        if (isset($fechasMap[$pid])) {
            $fecha = $fechasMap[$pid];
        } elseif ($mode === 'urgente') {
            if ($cursor < $desde) {
                $cursor = $desde;
            }
            $fecha = $cursor;
            $cursor = date('Y-m-d', strtotime($cursor . ' -' . $dayStep . ' day'));
        } else {
            if ($cursor > $hasta) {
                $cursor = $hasta;
            }
            $fecha = $cursor;
            $cursor = date('Y-m-d', strtotime($cursor . ' +' . $dayStep . ' day'));
        }
        $pdo->prepare('
            UPDATE grooflow_postgrado_practicas
            SET viaje_id=?, profesor_id=COALESCE(profesor_id, ?), fecha_tentativa=?,
                estado=CASE WHEN estado=\'idea\' THEN \'tentativa\' ELSE estado END
            WHERE id=?
        ')->execute([$viajeId, (int) $viaje['profesor_id'], $fecha, $pid]);
        $q = $pdo->prepare('
            SELECT pr.*, pg.titulo AS postgrado_titulo, m.titulo AS modulo_titulo, p.nombre AS profesor_nombre
            FROM grooflow_postgrado_practicas pr
            LEFT JOIN grooflow_postgrados pg ON pg.id = pr.postgrado_id
            LEFT JOIN grooflow_postgrado_modulos m ON m.id = pr.modulo_id
            LEFT JOIN grooflow_postgrado_profesores p ON p.id = pr.profesor_id
            WHERE pr.id=?
        ');
        $q->execute([$pid]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $updated[] = grooflow_postgrado_practica_row($row);
        }
    }

    [$user] = grooflow_postgrados_user_name();
    grooflow_audit_insert($pdo, $user, 'postgrados.apply_viaje', [
        'viaje_id' => (string) $viajeId,
        'mode' => $mode,
        'count' => count($updated),
    ]);

    return ['updated' => count($updated), 'items' => $updated];
}

/** @return array{conflicts:list, scenarios:list, alerts:list, kpis:array} */
function grooflow_postgrados_planning(PDO $pdo): array
{
    grooflow_postgrados_ensure_schema($pdo);
    grooflow_postgrados_require($pdo, 'ver');
    $conflicts = grooflow_postgrados_conflicts($pdo);
    $scenarios = grooflow_postgrados_scenarios($pdo);
    $board = grooflow_postgrados_board($pdo);

    $hoy = date('Y-m-d');
    $en30 = date('Y-m-d', strtotime('+30 days'));
    $viajesProximos = 0;
    $viajesHuecos = 0;
    foreach ($board['viajes'] as $v) {
        if (!in_array($v['estado'], ['tentativa', 'confirmada'], true)) {
            continue;
        }
        if ($v['fechaHasta'] < $hoy) {
            continue;
        }
        if ($v['fechaDesde'] <= $en30) {
            $viajesProximos++;
        }
        $linked = 0;
        foreach ($board['practicas'] as $p) {
            if ($p['viajeId'] === $v['id']) {
                $linked++;
            }
        }
        if ($linked === 0) {
            $viajesHuecos++;
        }
    }
    $sinViaje = 0;
    foreach ($board['practicas'] as $p) {
        if (in_array($p['estado'], ['idea', 'tentativa', 'confirmada'], true) && empty($p['viajeId']) && !empty($p['fechaTentativa'])) {
            $sinViaje++;
        }
    }

    $alerts = [];
    if ($board['stats']['sinFecha'] > 0) {
        $alerts[] = [
            'tipo' => 'sin_fecha',
            'severidad' => 'media',
            'mensaje' => $board['stats']['sinFecha'] . ' práctica(s) activa(s) sin fecha tentativa.',
        ];
    }
    if ($viajesHuecos > 0) {
        $alerts[] = [
            'tipo' => 'viaje_vacio',
            'severidad' => 'media',
            'mensaje' => $viajesHuecos . ' viaje(s) próximo(s) sin prácticas colgadas.',
        ];
    }
    if ($sinViaje > 0) {
        $alerts[] = [
            'tipo' => 'sin_viaje',
            'severidad' => 'baja',
            'mensaje' => $sinViaje . ' práctica(s) con fecha pero sin viaje asignado.',
        ];
    }
    $altas = count(array_filter($conflicts, static fn ($c) => ($c['severidad'] ?? '') === 'alta'));
    if ($altas > 0) {
        $alerts[] = [
            'tipo' => 'conflictos',
            'severidad' => 'alta',
            'mensaje' => $altas . ' conflicto(s) de alta severidad (profesor/prerrequisito).',
        ];
    }

    $totalVentanaDias = 0;
    $diasUsados = 0;
    foreach ($board['viajes'] as $v) {
        if (!in_array($v['estado'], ['tentativa', 'confirmada'], true) || $v['fechaHasta'] < $hoy) {
            continue;
        }
        $dias = max(1, (int) ((strtotime($v['fechaHasta']) - strtotime($v['fechaDesde'])) / 86400) + 1);
        $totalVentanaDias += $dias;
        $fechas = [];
        foreach ($board['practicas'] as $p) {
            if ($p['viajeId'] === $v['id'] && !empty($p['fechaTentativa'])) {
                $fechas[$p['fechaTentativa']] = true;
            }
        }
        $diasUsados += count($fechas);
    }
    $aprovechamiento = $totalVentanaDias > 0 ? (int) round(min(100, ($diasUsados / $totalVentanaDias) * 100)) : 0;

    return [
        'conflicts' => $conflicts,
        'scenarios' => $scenarios,
        'alerts' => $alerts,
        'kpis' => [
            'viajesProximos' => $viajesProximos,
            'viajesHuecos' => $viajesHuecos,
            'sinFecha' => $board['stats']['sinFecha'],
            'sinViaje' => $sinViaje,
            'conflictos' => count($conflicts),
            'aprovechamiento' => $aprovechamiento,
        ],
    ];
}

function grooflow_postgrados_dispatch(PDO $pdo, string $path, string $method): bool
{
    if ($path !== '/postgrados' && !str_starts_with($path, '/postgrados/')) {
        return false;
    }

    if ($path === '/postgrados/board' && $method === 'GET') {
        api_json_response(['ok' => true] + grooflow_postgrados_board($pdo));

        return true;
    }
    if ($path === '/postgrados/similarity' && $method === 'GET') {
        api_json_response(['ok' => true] + grooflow_postgrados_similarity($pdo));

        return true;
    }
    if ($path === '/postgrados/planning' && $method === 'GET') {
        api_json_response(['ok' => true] + grooflow_postgrados_planning($pdo));

        return true;
    }
    if ($path === '/postgrados/apply-viaje' && $method === 'POST') {
        $in = api_request_json();
        api_json_response(['ok' => true] + grooflow_postgrados_apply_viaje($pdo, $in));

        return true;
    }
    if ($path === '/postgrados/reorder' && $method === 'POST') {
        $in = api_request_json();
        api_json_response(['ok' => true] + grooflow_postgrados_reorder($pdo, $in));

        return true;
    }

    $in = $method === 'GET' ? $_GET : api_request_json();

    if ($path === '/postgrados/programas' && $method === 'POST') {
        api_json_response(['ok' => true, 'item' => grooflow_postgrados_upsert_programa($pdo, null, $in)]);

        return true;
    }
    if (preg_match('#^/postgrados/programas/(\d+)$#', $path, $m)) {
        $id = (int) $m[1];
        if ($method === 'PUT') {
            api_json_response(['ok' => true, 'item' => grooflow_postgrados_upsert_programa($pdo, $id, $in)]);

            return true;
        }
        if ($method === 'DELETE') {
            grooflow_postgrados_delete($pdo, 'programa', $id);
            api_json_response(['ok' => true]);

            return true;
        }
    }

    $entities = [
        'cohortes' => ['upsert' => 'grooflow_postgrados_upsert_cohorte', 'key' => 'cohorte'],
        'modulos' => ['upsert' => 'grooflow_postgrados_upsert_modulo', 'key' => 'modulo'],
        'temas' => ['upsert' => 'grooflow_postgrados_upsert_tema', 'key' => 'tema'],
        'profesores' => ['upsert' => 'grooflow_postgrados_upsert_profesor', 'key' => 'profesor'],
        'viajes' => ['upsert' => 'grooflow_postgrados_upsert_viaje', 'key' => 'viaje'],
        'practicas' => ['upsert' => 'grooflow_postgrados_upsert_practica', 'key' => 'practica'],
    ];
    foreach ($entities as $seg => $meta) {
        if ($path === '/postgrados/' . $seg && $method === 'POST') {
            $fn = $meta['upsert'];
            api_json_response(['ok' => true, 'item' => $fn($pdo, null, $in)]);

            return true;
        }
        if (preg_match('#^/postgrados/' . $seg . '/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            if ($method === 'PUT') {
                $fn = $meta['upsert'];
                api_json_response(['ok' => true, 'item' => $fn($pdo, $id, $in)]);

                return true;
            }
            if ($method === 'DELETE') {
                grooflow_postgrados_delete($pdo, $meta['key'], $id);
                api_json_response(['ok' => true]);

                return true;
            }
        }
    }

    api_json_response(['ok' => false, 'error' => 'Ruta de Postgrados no encontrada'], 404);

    return true;
}
