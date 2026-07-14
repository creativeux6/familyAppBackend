<?php

$source = __DIR__.'/../storage/api-docs/api-docs.yaml';
$destDir = __DIR__.'/../../docs/api';
$destFile = $destDir.'/openapi.yaml';

if (! file_exists($source)) {
    fwrite(STDERR, "Run php artisan l5-swagger:generate first.\n");
    exit(1);
}

if (! is_dir($destDir)) {
    mkdir($destDir, 0755, true);
}

copy($source, $destFile);
echo "Updated docs/api/openapi.yaml\n";
