<?php

declare(strict_types=1);

define('APP_BASE_URL', rtrim(getenv('CAMPURA_BASE_URL') ?: 'http://129.211.189.35', '/'));
define('UPLOAD_ROOT', getenv('CAMPURA_UPLOAD_ROOT') ?: dirname(__DIR__) . '/uploads');

const MAX_IMAGE_UPLOAD_SIZE = 5 * 1024 * 1024;
const MAX_ICS_UPLOAD_SIZE = 2 * 1024 * 1024;
