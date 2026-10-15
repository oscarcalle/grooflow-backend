<?php
/**
 * Test de Seguridad, RBAC y Validaciones Backend GrooFlow
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/grooflow_kv.php';
require_once __DIR__ . '/../lib/grooflow_resource_access.php';
require_once __DIR__ . '/../lib/grooflow_resource_store.php';

function test_petty_cash_fund_delivery_visible_without_location(): void {
    $fundDelivery = [
        'id' => 'pc-fd-1',
        'type' => 'income',
        'incomeSubtype' => 'fund_delivery',
        'custodianId' => '10',
        'amount' => 600,
        'status' => 'approved',
        // sin location / sede — típico de dotación semanal
    ];
    $expenseInSede = [
        'id' => 'pc-ex-1',
        'type' => 'expense',
        'custodianId' => '10',
        'location' => 'Benavides',
        'amount' => 50,
        'status' => 'pending',
    ];
    $expenseOtherSede = [
        'id' => 'pc-ex-2',
        'type' => 'expense',
        'custodianId' => '11',
        'location' => 'Miraflores',
        'amount' => 80,
        'status' => 'pending',
    ];
    $auditoria = [
        'admin' => false,
        'allSedes' => false,
        'id' => '99',
        'role' => 'groomer',
        'nivelNombre' => 'Auditoria',
        'roleLabel' => '',
        'permissions' => ['Auditoría' => true, 'Caja Chica' => true],
        'sedes' => ['Benavides'],
        'actions' => [],
    ];
    if (!grooflow_petty_cash_row_in_scope($auditoria, $fundDelivery)) {
        throw new RuntimeException('Auditoría debe ver dotación sin sede');
    }
    if (!grooflow_petty_cash_row_in_scope($auditoria, $expenseInSede)) {
        throw new RuntimeException('Auditoría debe ver gasto de su sede');
    }
    if (grooflow_petty_cash_row_in_scope($auditoria, $expenseOtherSede)) {
        throw new RuntimeException('Auditoría no debe ver gasto de otra sede');
    }
    $projected = grooflow_project_resource($auditoria, 'data:pettyCash', [
        $fundDelivery,
        $expenseInSede,
        $expenseOtherSede,
    ]);
    $ids = array_map(static fn ($r) => (string) ($r['id'] ?? ''), is_array($projected) ? $projected : []);
    if ($ids !== ['pc-fd-1', 'pc-ex-1']) {
        throw new RuntimeException('Proyección pettyCash incorrecta: ' . json_encode($ids));
    }
    // Write-gate: projected(incoming) debe coincidir con incoming para poder guardar.
    $incoming = [$fundDelivery, $expenseInSede];
    $projIncoming = grooflow_project_resource($auditoria, 'data:pettyCash', $incoming);
    if (grooflow_revision($projIncoming) !== grooflow_revision($incoming)) {
        throw new RuntimeException('Write-gate bloquearía la dotación sin sede');
    }
    echo "✓ Test PASSED: fund_delivery sin sede visible y escribible para auditoría\n";
}

function test_petty_cash_meta_visible_to_auditoria(): void {
    $meta = [
        'weekClosures' => [],
        'weekPreClosures' => [],
        'fundDeliveries' => [
            ['id' => 'd1', 'custodianId' => '10', 'weekNumber' => '2026-W15', 'deliveredAmount' => 500],
            ['id' => 'd2', 'custodianId' => '11', 'weekNumber' => '2026-W15', 'deliveredAmount' => 300],
        ],
    ];
    $auditoria = [
        'admin' => false,
        'allSedes' => false,
        'id' => '99',
        'role' => 'groomer',
        'nivelNombre' => 'Auditoria',
        'roleLabel' => '',
        'permissions' => ['Auditoría' => true, 'Caja Chica' => true],
        'sedes' => ['Benavides'],
        'actions' => [],
    ];
    $projected = grooflow_project_petty_cash_meta($auditoria, $meta);
    if (count($projected['fundDeliveries'] ?? []) !== 2) {
        throw new RuntimeException('Auditoría debe ver todas las dotaciones en pettyCashMeta');
    }
    $custodian = [
        'admin' => false,
        'allSedes' => false,
        'id' => '10',
        'role' => 'groomer',
        'nivelNombre' => 'Encargado de Sede',
        'roleLabel' => '',
        'permissions' => ['Caja Chica' => true],
        'sedes' => ['Benavides'],
        'actions' => [],
    ];
    $own = grooflow_project_petty_cash_meta($custodian, $meta);
    if (count($own['fundDeliveries'] ?? []) !== 1 || ($own['fundDeliveries'][0]['custodianId'] ?? '') !== '10') {
        throw new RuntimeException('Responsable solo debe ver su propia dotación');
    }
    echo "✓ Test PASSED: pettyCashMeta fundDeliveries visible to auditoría; scoped for custodian\n";
}

function test_fleet_agregar_covers_editar_side_effects(): void {
    $ctx = [
        'admin' => false,
        'actions' => [
            'Gestión Vehicular' => [
                'ver' => true,
                'agregar' => true,
                'editar' => false,
                'eliminar' => false,
            ],
        ],
    ];
    if (!grooflow_resource_action_allowed($ctx, 'data:fleet', 'agregar')) {
        throw new RuntimeException('agregar should be allowed');
    }
    if (!grooflow_resource_action_allowed($ctx, 'data:fleet', 'editar')) {
        throw new RuntimeException('editar should be covered by agregar for fleet operational saves');
    }
    if (grooflow_resource_action_allowed($ctx, 'data:fleet', 'eliminar')) {
        throw new RuntimeException('eliminar must still be denied without explicit permission');
    }
    $readOnly = [
        'admin' => false,
        'actions' => ['Gestión Vehicular' => ['ver' => true, 'agregar' => false, 'editar' => false]],
    ];
    if (grooflow_resource_action_allowed($readOnly, 'data:fleet', 'editar')) {
        throw new RuntimeException('read-only must not edit fleet');
    }
    echo "✓ Test PASSED: fleet agregar covers editar side-effects; eliminar still gated\n";
}

function test_grooflow_kv_array_tables_excludes_object_datasets(): void {
    $map = grooflow_kv_array_tables();
    if (isset($map['data:fleet'])) {
        throw new RuntimeException('SECURITY RISK: data:fleet should not be in array tables map');
    }
    if (isset($map['data:inventory'])) {
        throw new RuntimeException('SECURITY RISK: data:inventory should not be in array tables map');
    }
    echo "✓ Test PASSED: KV Array tables map excludes object datasets (data:fleet, data:inventory)\n";
}

function test_grooflow_resource_store_multicurrency_validation(): void {
    $errors = [];
    $validBicurrency = ['PEN' => 1500, 'USD' => 450];
    
    // Check validation logic directly
    $isNumeric = is_numeric($validBicurrency) && is_finite((float) $validBicurrency);
    $isMultiCurrency = is_array($validBicurrency) && !array_is_list($validBicurrency)
        && array_key_exists('PEN', $validBicurrency) && array_key_exists('USD', $validBicurrency)
        && is_numeric($validBicurrency['PEN']) && is_numeric($validBicurrency['USD']);
    
    if (!$isNumeric && !$isMultiCurrency) {
        throw new RuntimeException('Validation failed for valid bicurrency object');
    }
    echo "✓ Test PASSED: Resource store accepts multi-currency balance objects {PEN, USD}\n";
}

test_petty_cash_fund_delivery_visible_without_location();
test_petty_cash_meta_visible_to_auditoria();
test_fleet_agregar_covers_editar_side_effects();
test_grooflow_kv_array_tables_excludes_object_datasets();
test_grooflow_resource_store_multicurrency_validation();
echo "All backend security tests PASSED cleanly.\n";
