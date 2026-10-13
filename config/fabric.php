<?php

return [
    // Opt in only after every application destination has an authenticated agent.
    'configuration_enabled' => env('FABRIC_CONFIGURATION_ENABLED', false),
    'nodes_json' => env('FABRIC_NODES_JSON', '{}'),
    'allow_insecure_http' => env('FABRIC_ALLOW_INSECURE_HTTP', false),
    'timeout' => 30,
];
