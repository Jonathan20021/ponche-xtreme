<?php
session_start();

$current = $_SESSION['theme'] ?? 'dark';
$_SESSION['theme'] = $current === 'light' ? 'dark' : 'light';

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
