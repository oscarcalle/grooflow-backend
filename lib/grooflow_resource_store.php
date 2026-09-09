<?php

declare(strict_types=1);

class GrooflowConflict extends RuntimeException {}
class GrooflowValidation extends InvalidArgumentException
{
    public function __construct(public array $errors) { parent::__construct('Revisa los campos indicados'); }
}

function grooflow_revision(mixed $value): string
{
    return hash('sha256', grooflow_json_encode($value));
}

function grooflow_atomic(PDO $pdo, callable $work): mixed
{
    if ($pdo->inTransaction()) return $work();
    // One lock serializes multi-resource operations with ordinary edits too.
    $pdo->beginTransaction();
    try {
        $pdo->query("SELECT id FROM grooflow_write_lock WHERE id = 1 FOR UPDATE")->fetchColumn();
        $result = $work();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function grooflow_validate_resource(string $key, mixed $value): void
{
    $errors = [];
    if (in_array($key, ['data:treasuryBankBalance', 'data:treasuryUsdBalance'], true)) {
        // Accept numeric (legacy) or object {PEN: number, USD: number}
        $isNumeric = is_numeric($value) && is_finite((float) $value);
        $isMultiCurrency = is_array($value) && !array_is_list($value)
            && array_key_exists('PEN', $value) && array_key_exists('USD', $value)
            && is_numeric($value['PEN']) && is_numeric($value['USD']);
        if (!$isNumeric && !$isMultiCurrency) $errors['value'] = 'Saldo inválido (número o {PEN, USD})';
    } elseif (str_starts_with($key, 'data:') && !in_array($key, ['data:monthlyClosures'], true) && !is_array($value)) {
        $errors['value'] = 'Se esperaba una lista o dataset';
    }
    if (in_array($key, ['data:fleet', 'data:inventory'], true)) {
        $main = $key === 'data:fleet' ? 'vehicles' : 'equipment';
        if (!is_array($value) || !isset($value[$main]) || !is_array($value[$main]) || !array_is_list($value[$main])) $errors[$main] = 'Lista obligatoria';
    }
    $lists = is_array($value) && array_is_list($value) ? ['value' => $value] : [];
    if (in_array($key, ['data:fleet', 'data:inventory'], true) && is_array($value)) {
        foreach ($value as $f => $rows) if (is_array($rows) && array_is_list($rows)) $lists[$f] = $rows;
    }
    foreach ($lists as $field => $rows) {
        $ids = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) { $errors["$field.$i"] = 'Registro inválido'; continue; }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '' || isset($ids[$id])) $errors["$field.$i.id"] = 'Identificador obligatorio y único';
            $ids[$id] = true;
            foreach (['amount', 'laborCost', 'partsCost', 'quantity'] as $n) {
                if (isset($row[$n]) && (!is_numeric($row[$n]) || !is_finite((float) $row[$n]) || (float) $row[$n] < 0)) $errors["$field.$i.$n"] = 'Debe ser un número no negativo';
            }
            if ($key === 'data:transactions') {
                if (!isset($row['amount']) || (float) $row['amount'] <= 0) $errors["$field.$i.amount"] = 'Importe mayor que cero';
                if (!in_array($row['type'] ?? '', ['income', 'expense'], true)) $errors["$field.$i.type"] = 'Tipo inválido';
                if (trim((string) ($row['category'] ?? '')) === '') $errors["$field.$i.category"] = 'Categoría obligatoria';
                if (empty($row['date']) || !preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $row['date']) || strtotime((string) $row['date']) === false) $errors["$field.$i.date"] = 'Fecha inválida';
            }
            if (isset($row['currency']) && !in_array($row['currency'], ['PEN', 'USD'], true)) $errors["$field.$i.currency"] = 'Moneda no admitida';
        }
    }
    if ($errors) throw new GrooflowValidation($errors);
}

function grooflow_read_resource(PDO $pdo, string $key): array
{
    grooflow_assert_resource($pdo, $key);
    $value = grooflow_project_resource(grooflow_access_context($pdo), $key, grooflow_kv_get($pdo, $key));
    return ['ok' => true, 'key' => $key, 'value' => $value, 'revision' => grooflow_revision($value)];
}

function grooflow_write_resource(PDO $pdo, string $key, mixed $incoming, ?string $revision): array
{
    grooflow_assert_resource($pdo, $key, true);
    return grooflow_atomic($pdo, function () use ($pdo, $key, $incoming, $revision) {
        $ctx = grooflow_access_context($pdo);
        $full = grooflow_kv_get($pdo, $key);
        $visible = grooflow_project_resource($ctx, $key, $full);
        if (grooflow_revision($incoming) === grooflow_revision($visible)) return ['ok' => true, 'revision' => grooflow_revision($visible)];
        if ($revision === null || !hash_equals(grooflow_revision($visible), $revision)) throw new GrooflowConflict('Los datos cambiaron. Recarga antes de guardar; tu borrador se conserva.');
        foreach (grooflow_required_actions($visible, $incoming) as $action) grooflow_assert_resource_action($pdo, $key, $action);
        grooflow_validate_resource($key, $incoming);
        $global = in_array($key, ['data:treasuryBankBalance', 'data:treasuryUsdBalance', 'data:providers', 'data:products', 'data:chartOfAccounts', 'settings:config', 'settings:theme', 'settings:alertThresholds', 'settings:alertReadState'], true);
        $value = $incoming;
        if (!$ctx['admin'] && !$ctx['allSedes'] && !$global) {
            $projected = grooflow_project_resource($ctx, $key, $incoming);
            if (grooflow_revision($projected) !== grooflow_revision($incoming)) throw new RuntimeException('Sin permiso para escribir datos fuera de tus sedes');
            $value = grooflow_merge_scoped(is_array($full) ? $full : [], is_array($visible) ? $visible : [], $incoming);
        }
        if ($key === 'data:transactions') grooflow_assert_open_months($pdo, is_array($full) ? $full : [], $value);
        grooflow_kv_set($pdo, $key, $value);
        $saved = grooflow_project_resource($ctx, $key, grooflow_kv_get($pdo, $key));
        return ['ok' => true, 'revision' => grooflow_revision($saved)];
    });
}

function grooflow_assert_open_months(PDO $pdo, array $before, array $after): void
{
    $closures = grooflow_kv_get($pdo, 'data:monthlyClosures') ?? [];
    foreach ($closures as $month => $closure) {
        if (($closure['status'] ?? '') !== 'closed') continue;
        $inMonth = fn ($r) => substr((string) ($r['date'] ?? ''), 0, 7) === $month;
        $old = array_values(array_filter($before, $inMonth));
        $new = array_values(array_filter($after, $inMonth));
        usort($old, fn ($a, $b) => strcmp($a['id'], $b['id']));
        usort($new, fn ($a, $b) => strcmp($a['id'], $b['id']));
        if (grooflow_revision($old) !== grooflow_revision($new)) throw new GrooflowConflict('El mes ' . $month . ' está cerrado. Reábrelo antes de modificar movimientos.');
    }
}
