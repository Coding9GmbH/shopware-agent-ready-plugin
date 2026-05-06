<?php declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php is missing. Run `composer install` first.\n");
    exit(1);
}
require $autoload;
