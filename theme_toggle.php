<?php
session_start();

$current = $_SESSION['theme'] ?? 'dark';
$_SESSION['theme'] = $current === 'light' ? 'dark' : 'light';

$referer = $_SERVER['HTTP_REFERER'] ?? '';
if ($referer !== '') {
    header('Location: ' . $referer);
} else {
    header('Location: index.php');
}
exit;
