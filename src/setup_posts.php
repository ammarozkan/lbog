<?php

require __DIR__ . '/db.php';

try {
    run_migration();
    echo __('Migration complete.') . "\n";
} catch (PDOException $e) {
    echo __('Error: ') . $e->getMessage() . "\n";
    exit(1);
}
