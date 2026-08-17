<?php

declare(strict_types=1);

$app = require __DIR__ . '/../bootstrap.php';

$router = new \NameSweep\Api\Router($app);
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
