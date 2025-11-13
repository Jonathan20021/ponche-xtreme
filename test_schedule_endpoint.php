<?php
session_start();
require_once 'db.php';

// Debug information
$debug = [
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
    'post_data' => $_POST,
    'session_user_id' => $_SESSION['user_id'] ?? 'not set',
    'raw_input' => file_get_contents('php://input')
];

header('Content-Type: application/json');
echo json_encode(['debug' => $debug, 'success' => true]);
