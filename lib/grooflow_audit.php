<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $metadata
 */
function grooflow_audit_insert(
    PDO $pdo,
    array $caller,
    string $action,
    array $metadata,
    ?string $targetUserId = null
): void {
    $action = trim($action);
    if ($action === '' || strlen($action) > 80) {
        throw new InvalidArgumentException('Acción de auditoría inválida');
    }
    $userId = (int) ($caller['id'] ?? 0);
    if ($userId <= 0) {
        throw new RuntimeException('Sesión inválida');
    }
    $entity = isset($metadata['entity']) && is_string($metadata['entity'])
        ? substr($metadata['entity'], 0, 80)
        : null;
    $entityId = $targetUserId;
    if ($entityId === null && isset($metadata['entity_id']) && is_string($metadata['entity_id'])) {
        $entityId = $metadata['entity_id'];
    }
    if ($entityId !== null) {
        $entityId = substr($entityId, 0, 80);
    }
    $payload = $metadata;
    if ($targetUserId !== null && $targetUserId !== '') {
        $payload['target_user_id'] = $targetUserId;
    }
    grooflow_ensure_schema($pdo);
    $pdo->prepare('
        INSERT INTO grooflow_auditoria (usuario_id, action, entity, entity_id, payload)
        VALUES (?, ?, ?, ?, ?)
    ')->execute([
        $userId,
        $action,
        $entity !== '' ? $entity : null,
        $entityId !== '' ? $entityId : null,
        grooflow_json_encode($payload),
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function grooflow_audit_list(PDO $pdo, int $limit = 80): array
{
    $limit = max(1, min(200, $limit));
    grooflow_ensure_schema($pdo);
    $sql = '
        SELECT
            a.id,
            a.usuario_id,
            a.action,
            a.entity,
            a.entity_id,
            a.payload,
            a.created_at,
            u.username,
            u.email,
            u.nombre,
            u.apellido
        FROM grooflow_auditoria a
        LEFT JOIN app_usuarios u ON u.id = a.usuario_id
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT ' . $limit;
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $payload = grooflow_json_decode(is_string($row['payload'] ?? null) ? $row['payload'] : null);
        $meta = is_array($payload) ? $payload : [];
        if (! isset($meta['entity']) && ! empty($row['entity'])) {
            $meta['entity'] = $row['entity'];
        }
        $actorName = grooflow_display_name($row);
        $email = strtolower(trim((string) ($row['email'] ?? $row['username'] ?? '')));
        $target = $row['entity_id'] ?? $meta['target_user_id'] ?? null;
        $out[] = [
            'id' => (int) $row['id'],
            'actor_user_id' => $row['usuario_id'] !== null ? (string) $row['usuario_id'] : null,
            'actor_email' => $email !== '' ? $email : null,
            'actor_name' => $actorName !== '' ? $actorName : null,
            'action' => (string) $row['action'],
            'target_user_id' => $target !== null && $target !== '' ? (string) $target : null,
            'metadata' => $meta,
            'created_at' => (string) $row['created_at'],
        ];
    }

    return $out;
}
