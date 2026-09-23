<?php

declare(strict_types=1);

/**
 * Fallback entry when the Hostinger subdomain root is the repo root
 * and a request hits /index.php directly.
 */
require __DIR__ . '/public/index.php';
