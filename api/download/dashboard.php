<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/response.php';

respondJson(200, 'success', [
    'title' => 'Campura One API 已启动',
    'status' => 'ok',
]);