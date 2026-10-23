<?php

return [
    // Opt in only after every application destination has an authenticated agent.
    // Stateless generation only; helper ownership and deployment execution stay in Coolify.
    'compose_enabled' => env('FABRIC_COMPOSE_ENABLED', false),
    'configuration_enabled' => env('FABRIC_CONFIGURATION_ENABLED', false),
    'nodes_json' => env('FABRIC_NODES_JSON', '{}'),
    'allow_insecure_http' => env('FABRIC_ALLOW_INSECURE_HTTP', false),
    'timeout' => 30,
];
