<?php
// Derive BASE_PATH like /parentFolder/projectFolder for local dev (Laragon)
$projectFolder = basename(dirname(__DIR__));
$parentFolder = basename(dirname(dirname(__DIR__)));
if ($parentFolder && $projectFolder) {
    define('BASE_PATH', '/' . $parentFolder . '/' . $projectFolder);
} else {
    define('BASE_PATH', '/' . $projectFolder);
}
// TAX / PPN configuration
// Toggle PPN (VAT) calculation across the app. Set to true to enable PPN (default 10%).
if (!defined('TAX_ENABLED')) {
    define('TAX_ENABLED', true);
}
if (!defined('TAX_RATE')) {
    // 0.10 = 10%
    define('TAX_RATE', 0.10);
}
