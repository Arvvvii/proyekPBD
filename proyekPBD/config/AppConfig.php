<?php
// Derive BASE_PATH like /parentFolder/projectFolder for local dev (Laragon)
$projectFolder = basename(dirname(__DIR__));
$parentFolder = basename(dirname(dirname(__DIR__)));
if ($parentFolder && $projectFolder) {
    define('BASE_PATH', '/' . $parentFolder . '/' . $projectFolder);
} else {
    define('BASE_PATH', '/' . $projectFolder);
}
