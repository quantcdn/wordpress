<?php
// Standalone check (no WordPress needed): php tests/endpoint-check.php
namespace Quant {
    class Settings {}
    // Stubs for the WordPress functions used by Client at load time.
    function get_option($k) { return []; }
    function register_activation_hook() {}
    function register_deactivation_hook() {}
    function add_action() {}
    function add_filter() {}
}
namespace {
    define('QUANT_SETTINGS_KEY', 'quant_settings');
    define('QUANT_SEED_KEY', 'quant_seed');
    require __DIR__ . '/../plugins/quant/src/Client.php';
    $cases = [
        [['api_endpoint' => 'https://api.quantcdn.io'], 'https://api.quantcdn.io/v1'],
        [['api_endpoint' => 'https://api.quantgov.cloud/'], 'https://api.quantgov.cloud/v1'],
        [[], '/v1'],
        [false, '/v1'],
    ];
    $fail = 0;
    foreach ($cases as [$in, $want]) {
        $got = Quant\Client::buildEndpoint($in);
        if ($got !== $want) { echo "FAIL: got $got want $want\n"; $fail++; }
    }
    echo $fail ? "FAILED\n" : "OK\n";
    exit($fail ? 1 : 0);
}
