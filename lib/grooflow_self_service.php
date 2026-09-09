<?php

declare(strict_types=1);

function grooflow_own_sessions(PDO $pdo): array
{
    $id = (int) (api_current_user()['id'] ?? 0);
    if (!auth_sessions_table_exists($pdo)) return [];
    $stmt = $pdo->prepare('SELECT id, device_label, browser_name, os_name, created_at, last_seen_at, ended_at, (token = ?) AS current FROM app_usuario_sesiones WHERE usuario_id = ? ORDER BY created_at DESC LIMIT 100');
    $stmt->execute([api_bearer_token(), $id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function grooflow_revoke_other_sessions(PDO $pdo): int
{
    $id = (int) (api_current_user()['id'] ?? 0);
    $token = api_bearer_token();
    $count = 0;
    if (auth_sessions_table_exists($pdo)) {
        $stmt = $pdo->prepare('UPDATE app_usuario_sesiones SET ended_at = NOW() WHERE usuario_id = ? AND token <> ? AND ended_at IS NULL');
        $stmt->execute([$id, $token]);
        $count = $stmt->rowCount();
    }
    // Legacy fallback must not authenticate a token just revoked above.
    $pdo->prepare('UPDATE app_usuarios SET session_token = NULL WHERE id = ? AND session_token <> ?')->execute([$id, $token]);
    return $count;
}

function grooflow_own_password(PDO $pdo, array $data): void
{
    $row = api_current_user();
    $current = (string) ($data['currentPassword'] ?? '');
    $password = (string) ($data['newPassword'] ?? '');
    grooflow_validate_password($password);
    $stmt = $pdo->prepare('SELECT password_hash FROM app_usuarios WHERE id = ? FOR UPDATE');
    $stmt->execute([(int) $row['id']]);
    if (!password_verify($current, (string) $stmt->fetchColumn())) throw new GrooflowValidation(['currentPassword' => 'La contraseña actual no es correcta']);
    if ($password !== ($data['confirmPassword'] ?? '')) throw new GrooflowValidation(['confirmPassword' => 'Las contraseñas no coinciden']);
    $pdo->prepare('UPDATE app_usuarios SET password_hash = ? WHERE id = ?')->execute([api_password_hash($password), (int) $row['id']]);
    grooflow_revoke_other_sessions($pdo);
}

function grooflow_save_own_profile(PDO $pdo, array $data): array
{
    $row = api_current_user();
    $id = (int) $row['id'];
    $allowed = ['firstName', 'lastName', 'phone', 'documentNumber', 'gender', 'birthDate', 'userStatus', 'coverGradient', 'customCoverUrl', 'customPhotoUrl', 'email'];
    foreach ($data as $field => $_) if (!in_array($field, $allowed, true)) throw new GrooflowValidation([$field => 'Campo no editable en el perfil propio']);
    foreach ($data as $field => $value) if (!is_string($value) && $value !== null) throw new GrooflowValidation([$field => 'Valor inválido']);
    $email = strtolower(trim((string) ($data['email'] ?? $row['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new GrooflowValidation(['email' => 'Correo inválido']);
    if (trim((string) ($data['firstName'] ?? $row['nombre'])) === '') throw new GrooflowValidation(['firstName' => 'Nombre obligatorio']);
    foreach (['customPhotoUrl', 'customCoverUrl'] as $field) {
        $value = $data[$field] ?? '';
        if ($value !== '' && $value !== null && (!preg_match('~^(https://|data:image/(png|jpeg|webp|gif);base64,)~', $value) || strlen($value) > 4500000)) throw new GrooflowValidation([$field => 'Imagen no válida']);
    }
    $stmt = $pdo->prepare('SELECT extra_json FROM grooflow_perfiles WHERE usuario_id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $extra = grooflow_json_decode($stmt->fetchColumn() ?: null) ?? [];
    $extra['personalProfile'] = array_merge($extra['personalProfile'] ?? [], $data);
    $pdo->prepare('UPDATE grooflow_perfiles SET extra_json = ? WHERE usuario_id = ?')->execute([grooflow_json_encode($extra), $id]);
    $pdo->prepare('UPDATE app_usuarios SET nombre = ?, apellido = ?, email = ?, celular = ?, identificacion = ?, imagen = ? WHERE id = ?')->execute([
        trim((string) ($data['firstName'] ?? $row['nombre'])), trim((string) ($data['lastName'] ?? $row['apellido'])), $email,
        $data['phone'] ?? $row['celular'] ?? null, $data['documentNumber'] ?? $row['identificacion'] ?? null,
        array_key_exists('customPhotoUrl', $data) ? $data['customPhotoUrl'] : ($row['imagen'] ?? null), $id,
    ]);
    $stmt = $pdo->prepare('SELECT * FROM app_usuarios WHERE id = ?'); $stmt->execute([$id]);
    return grooflow_user_to_app($pdo, $stmt->fetch(PDO::FETCH_ASSOC));
}
