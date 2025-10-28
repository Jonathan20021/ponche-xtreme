<?php
session_start();
include 'db.php';
date_default_timezone_set('America/Santo_Domingo');

ensurePermission('register_attendance');



$message = '';
$last_type = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $type = $_POST['type'] ?? null;
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $timestamp = date('Y-m-d h:i:s A'); // Formato 12 horas con AM/PM

    if ($type) {
        $query_user = "SELECT id FROM users WHERE username = ?";
        $stmt_user = $pdo->prepare($query_user);
        $stmt_user->execute([$username]);
        $user = $stmt_user->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $query = "INSERT INTO administrative_hours (user_id, type, timestamp, ip_address) VALUES (?, ?, ?, ?)";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$user['id'], $type, $timestamp, $ip_address]);

            $message = "Successfully registered <strong>'$type'</strong> for user <strong>'$username'</strong> at <strong>$timestamp</strong>.";
            $last_type = $type;
        } else {
            $message = "User <strong>'$username'</strong> not found.";
        }
    } else {
        $message = "Please select an attendance type.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <title>Register Administrative Hours</title>
    <script>
        // Actualiza el reloj con la hora actual
        function updateClock() {
            const now = new Date();
            const options = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
            document.getElementById('current-time').textContent = now.toLocaleTimeString('en-US', options);
        }
        setInterval(updateClock, 1000);
    </script>
</head>
<body class="bg-gradient-to-r from-blue-500 to-purple-600 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-md bg-white shadow-xl rounded-lg overflow-hidden">
        <div class="bg-gradient-to-r from-purple-600 to-blue-500 text-white text-center py-6">
            <h1 class="text-2xl font-bold">Administrative Hours</h1>
            <p id="current-time" class="text-xl font-semibold mt-2"></p>
        </div>
        <div class="p-6">
            <?php if ($last_type): ?>
                <div class="mb-4 p-4 rounded-lg bg-gray-100 text-gray-700">
                    Last registered type: <strong><?= htmlspecialchars($last_type) ?></strong>
                </div>
            <?php endif; ?>
            
            <?php if ($message): ?>
                <div class="mb-4 p-4 rounded-lg <?= strpos($message, 'Successfully') !== false ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-4">
                    <label for="username" class="block text-gray-700 text-sm font-bold mb-2">Username</label>
                    <input type="text" name="username" id="username" placeholder="Enter username" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-600 focus:border-transparent" 
                           required>
                </div>

                <p class="text-gray-700 text-sm font-bold mb-2">Select Attendance Type</p>
                <div class="grid grid-cols-2 gap-4">
                    <button type="submit" name="type" value="Entry" 
                            class="w-full bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded-lg shadow-lg">
                        Entry
                    </button>
                    <button type="submit" name="type" value="Lunch" 
                            class="w-full bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-2 px-4 rounded-lg shadow-lg">
                        Lunch
                    </button>
                    <button type="submit" name="type" value="Break" 
                            class="w-full bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-lg shadow-lg">
                        Break
                    </button>
                    <button type="submit" name="type" value="Exit" 
                            class="w-full bg-red-500 hover:bg-red-600 text-white font-bold py-2 px-4 rounded-lg shadow-lg">
                        Exit
                    </button>
                </div>
            </form>
        </div>
        <div class="bg-gray-100 p-4 text-center">
            <p class="text-gray-600 text-sm">Track your hours with precision and style ✨</p>
        </div>
    </div>
</body>
</html>
