<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <title>Access Denied</title>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded-lg shadow-md text-center max-w-lg">
        <h1 class="text-3xl font-bold text-red-500 mb-4">Access Denied</h1>
        <p class="text-gray-700 mb-4">You do not have the necessary permissions to access this page.</p>
        <a href="index.php" class="bg-blue-500 text-white py-2 px-4 rounded hover:bg-blue-700">
            Go to Login
        </a>
    </div>
</body>
</html>
