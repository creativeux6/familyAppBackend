<?php

return [
    'driver' => env('GRAPH_DRIVER', 'mysql'),
    'neo4j_sync_enabled' => env('NEO4J_SYNC_ENABLED', false),
    'max_tree_depth' => 6,
];
