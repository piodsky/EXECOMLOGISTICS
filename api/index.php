<?php
/**
 * /api — JSON endpoints for the POS (added in Phase 2+).
 * Every endpoint starts with:
 *
 *   require __DIR__ . '/../system/bootstrap.php';
 *   api_guard('POST', 'pos.access');   // method + login + permission + CSRF
 *   $data = request_json();
 */
declare(strict_types=1);

require __DIR__ . '/../system/bootstrap.php';

json_response(['ok' => false, 'message' => 'Endpoint not found.'], 404);
