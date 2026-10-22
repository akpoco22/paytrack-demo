<?php
/**
 * Customer search API (admin) — powers searchable dropdowns.
 * GET customer-search.php?q=bola → JSON [{id,name,phone_number,card_id}, ...] max 20.
 * Never dumps the whole table: always capped, admin-only.
 */
session_start();

require_once '../../config/environment.php';
require_once '../../config/database.php';
require_once '../../src/auth/Auth.php';
require_once '../../src/models/Customer.php';
require_once '../../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isAuthenticated() || !Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$q = trim($_GET['q'] ?? '');
$customer = new Customer($conn);
try {
    $rows = $customer->getCustomerOptions($q, 20) ?: [];
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Search failed']);
    exit;
}

echo json_encode(array_map(function ($c) {
    return [
        'id'           => (int)($c['id'] ?? 0),
        'name'         => $c['name'] ?? '',
        'phone_number' => $c['phone_number'] ?? '',
        'card_id'      => $c['card_id'] ?? '',
    ];
}, $rows));
