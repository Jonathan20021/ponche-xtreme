<?php
session_start();

$current = $_SESSION['theme'] ?? 'dark';
$_SESSION['theme'] = $current === 'light' ? 'dark' : 'light';

// Check if it's an AJAX request
$isAjax = isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if ($isAjax) {
    // Return JSON response for AJAX requests
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'theme' => $_SESSION['theme']
    ]);
    exit;
}

// Check for return_url from POST, otherwise use referer
$returnUrl = $_POST['return_url'] ?? $_SERVER['HTTP_REFERER'] ?? '';

if ($returnUrl !== '') {
    // If it's a relative URL, use it directly
    if (strpos($returnUrl, '/') === 0) {
        header('Location: ' . $returnUrl);
    } else {
        header('Location: ' . $returnUrl);
    }
} else {
    header('Location: index.php');
}
exit;
