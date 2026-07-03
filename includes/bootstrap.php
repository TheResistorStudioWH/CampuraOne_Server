<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/validation.php';
