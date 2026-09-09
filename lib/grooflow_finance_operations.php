<?php

declare(strict_types=1);

function grooflow_pay_batch(PDO $pdo, array $data): array
{
    grooflow_assert_resource_action($pdo, 'data:treasuryInvoices', 'editar');
    $key = trim((string) ($data['idempotencyKey'] ?? ''));
    if (!preg_match('/^[a-zA-Z0-9-]{16,80}$/', $key)) throw new InvalidArgumentException('Identificador de operación obligatorio');
    $ids = $data['ids'] ?? [];
    if (!is_array($ids) || !$ids || count($ids) !== count(array_unique($ids))) throw new InvalidArgumentException('Selecciona pagos únicos');
    $currency = (string) ($data['currency'] ?? 'PEN');
    if (!in_array($currency, ['PEN', 'USD'], true)) throw new InvalidArgumentException('Moneda inválida');
    $requestHash = grooflow_revision([(string) api_current_user()['id'], $ids, $currency]);
    return grooflow_atomic($pdo, function () use ($pdo, $data, $key, $ids, $currency, $requestHash) {
        $receiptKey = 'internal:payment:' . $key;
        $previous = grooflow_kv_get($pdo, $receiptKey);
        if ($previous) {
            if (($previous['requestHash'] ?? '') !== $requestHash) throw new GrooflowConflict('Identificador reutilizado con otro pago');
            return $previous['result'];
        }
        $keys = ['data:treasuryInvoices', 'data:treasuryPaidHistory', 'data:treasuryBankBalance', 'data:treasuryUsdBalance', 'data:feeReceipts'];
        $values = [];
        foreach ($keys as $k) {
            $values[$k] = grooflow_kv_get($pdo, $k);
            $visible = grooflow_project_resource(grooflow_access_context($pdo), $k, $values[$k]);
            if (!hash_equals(grooflow_revision($visible), (string) ($data['revisions'][$k] ?? ''))) throw new GrooflowConflict('Los saldos o pagos cambiaron. Actualiza antes de aprobar.');
        }
        $invoices = $values['data:treasuryInvoices'] ?? [];
        $fees = $values['data:feeReceipts'] ?? [];
        $history = $values['data:treasuryPaidHistory'] ?? [];
        $paidIds = array_column($history, 'id');
        $approved = []; $totalCents = 0; $now = date('c');
        foreach ($ids as $id) {
            if (in_array($id, $paidIds, true)) throw new GrooflowConflict('El pago ya fue registrado');
            $invoice = null;
            if (str_starts_with((string) $id, 'rxh-')) {
                foreach ($fees as $i => $fee) {
                    if ('rxh-' . $fee['id'] !== $id) continue;
                    if (($fee['status'] ?? '') !== 'requested_payment') throw new GrooflowConflict('El honorario no está pendiente de pago');
                    $invoice = ['id' => $id, 'amount' => $fee['amount'], 'currency' => 'PEN', 'branchId' => $fee['location'] ?? '', 'providerName' => $fee['professionalName'], 'providerRuc' => '', 'documentNumber' => $fee['receiptNumber'], 'documentType' => 'RxH', 'issueDate' => $fee['issueDate'], 'dueDate' => $fee['dueDate'], 'tentativePaymentDate' => $now, 'category' => 'Honorarios', 'description' => $fee['description'] ?? ''];
                    $fees[$i]['status'] = 'paid'; $fees[$i]['paymentDate'] = $now;
                }
            } else {
                foreach ($invoices as $inv) if ($inv['id'] === $id) $invoice = $inv;
                if (!$invoice || !in_array($invoice['status'] ?? '', ['pending', 'scheduled', 'in_transit'], true)) throw new GrooflowConflict('Factura no disponible para pago');
            }
            if (!$invoice) throw new InvalidArgumentException('Pago no encontrado');
            if (!grooflow_row_in_scope(grooflow_access_context($pdo), $invoice)) throw new RuntimeException('Sin permiso para pagar en esa sede');
            if (($invoice['currency'] ?? 'PEN') !== $currency) throw new InvalidArgumentException('El lote debe contener una sola moneda');
            $amount = $invoice['amount'] ?? null;
            if (!is_numeric($amount) || !is_finite((float) $amount) || (float) $amount <= 0) throw new InvalidArgumentException('Importe inválido');
            $totalCents += (int) round((float) $amount * 100);
            $approved[] = [...$invoice, 'status' => 'paid', 'paymentDate' => $now, 'paymentOperationId' => $key];
        }
        $balanceKey = $currency === 'USD' ? 'data:treasuryUsdBalance' : 'data:treasuryBankBalance';
        $balanceCents = (int) round((float) ($values[$balanceKey] ?? 0) * 100);
        if ($totalCents > $balanceCents) throw new InvalidArgumentException('Saldo insuficiente en ' . $currency);
        $values[$balanceKey] = ($balanceCents - $totalCents) / 100;
        $values['data:treasuryInvoices'] = array_values(array_filter($invoices, fn ($inv) => !in_array($inv['id'], $ids, true)));
        $values['data:treasuryPaidHistory'] = [...$approved, ...$history];
        $values['data:feeReceipts'] = $fees;
        foreach ($values as $k => $v) if ($v !== null) grooflow_kv_set($pdo, $k, $v);
        $out = []; $revisions = [];
        foreach ($values as $k => $v) {
            $out[$k] = grooflow_project_resource(grooflow_access_context($pdo), $k, $v);
            $revisions[$k] = grooflow_revision($out[$k]);
        }
        $result = ['ok' => true, 'operationId' => $key, 'amount' => $totalCents / 100, 'currency' => $currency, 'values' => $out, 'revisions' => $revisions];
        grooflow_kv_set($pdo, $receiptKey, ['requestHash' => $requestHash, 'result' => $result]);
        grooflow_audit_insert($pdo, api_current_user(), 'payment_batch', ['entity' => 'Tesorería', 'entity_id' => $key, 'amount' => $totalCents / 100, 'currency' => $currency, 'count' => count($ids)]);
        return $result;
    });
}

function grooflow_month_operation(PDO $pdo, array $data): array
{
    grooflow_assert_resource_action($pdo, 'data:monthlyClosures', 'editar');
    $ctx = grooflow_access_context($pdo);
    if (!$ctx['admin'] && !$ctx['allSedes']) throw new RuntimeException('Sin permiso para cerrar un mes de todas las sedes');
    $month = (string) ($data['month'] ?? '');
    $action = (string) ($data['action'] ?? '');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || !in_array($action, ['close', 'reopen'], true)) throw new InvalidArgumentException('Mes o acción inválidos');
    if ($month >= date('Y-m') && $action === 'close') throw new InvalidArgumentException('Solo se pueden cerrar meses terminados');
    $reason = trim((string) ($data['reason'] ?? ''));
    if ($action === 'reopen' && $reason === '') throw new InvalidArgumentException('Indica el motivo de reapertura');
    return grooflow_atomic($pdo, function () use ($pdo, $data, $month, $action, $reason) {
        $all = grooflow_kv_get($pdo, 'data:monthlyClosures') ?? [];
        $status = $action === 'close' ? 'closed' : 'open';
        if (($all[$month]['status'] ?? 'open') === $status) return ['ok' => true, 'value' => $all, 'revision' => grooflow_revision($all)];
        if (!hash_equals(grooflow_revision($all ?: null), (string) ($data['revision'] ?? ''))) throw new GrooflowConflict('El estado del cierre cambió. Actualiza el reporte.');
        $transactions = grooflow_kv_get($pdo, 'data:transactions') ?? [];
        $snapshot = array_values(array_filter($transactions, fn ($r) => substr((string) ($r['date'] ?? ''), 0, 7) === $month));
        $history = $all[$month]['history'] ?? [];
        $event = ['action' => $action, 'actor' => (string) api_current_user()['id'], 'at' => date('c'), 'reason' => $reason];
        $all[$month] = ['status' => $status, 'actor' => $event['actor'], 'at' => $event['at'], 'snapshot' => $action === 'close' ? $snapshot : ($all[$month]['snapshot'] ?? []), 'history' => [...$history, $event]];
        grooflow_kv_set($pdo, 'data:monthlyClosures', $all);
        grooflow_audit_insert($pdo, api_current_user(), 'month_' . $action, ['entity' => 'Reportes', 'month' => $month, 'reason' => $reason]);
        return ['ok' => true, 'value' => $all, 'revision' => grooflow_revision($all)];
    });
}
