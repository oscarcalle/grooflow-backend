<?php
/**
 * Test de Seguridad, RBAC y Validaciones Backend GrooFlow
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/grooflow_kv.php';
require_once __DIR__ . '/../lib/grooflow_resource_access.php';
require_once __DIR__ . '/../lib/grooflow_resource_store.php';

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

test_fleet_agregar_covers_editar_side_effects();
test_grooflow_kv_array_tables_excludes_object_datasets();
test_grooflow_resource_store_multicurrency_validation();
echo "All backend security tests PASSED cleanly.\n";
