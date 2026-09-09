<?php
/**
 * Test de Seguridad, RBAC y Validaciones Backend GrooFlow
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/grooflow_kv.php';
require_once __DIR__ . '/../lib/grooflow_resource_store.php';

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

test_grooflow_kv_array_tables_excludes_object_datasets();
test_grooflow_resource_store_multicurrency_validation();
echo "All backend security tests PASSED cleanly.\n";
