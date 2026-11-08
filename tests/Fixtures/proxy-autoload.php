<?php

declare(strict_types=1);

// Stands in for the autoloader a Composer bin proxy hands to bin/pront through
// $_composer_autoload_path; the marker shows that this file, not the fallback, was loaded.
fwrite(STDERR, "proxy autoloader loaded\n");

return require dirname(__DIR__, 2) . '/vendor/autoload.php';
