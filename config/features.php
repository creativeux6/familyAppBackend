<?php

return [
    'payments_enabled' => env('PAYMENTS_ENABLED', false),
    'neo4j_sync_enabled' => env('NEO4J_SYNC_ENABLED', false),
    // v2: expenses, bookings, tasks, collaborators on media_events.
    // v1 keeps events as private media folders only.
    'event_management_enabled' => env('EVENT_MANAGEMENT_ENABLED', false),
];
