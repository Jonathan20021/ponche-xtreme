<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['IT'])) {
    header('Location: index.php');
    exit;
}

// Obtener el ID del registro a editar
if (!isset($_GET['id'])) {
    header('Location: records.php'); // Redirigir a la página de registros
    exit;
}

$record_id = $_GET['id'];
$message = '';

// Obtener los datos del registro para prellenar el formulario, incluyendo Full Name y Username
$query = "
    SELECT 
        attendance.id, 
        attendance.type, 
        attendance.timestamp, 
        attendance.ip_address, 
        users.full_name, 
        users.username 
    FROM attendance 
    JOIN users ON attendance.user_id = users.id 
    WHERE attendance.id = ?
";
$stmt = $pdo->prepare($query);
$stmt->execute([$record_id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    header('Location: records.php');
    exit;
}

// Procesar la edición del registro
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'];
    $timestamp = $_POST['timestamp'];
    $ip_address = $_POST['ip_address'];

    if ($type && $timestamp) {
        $update_query = "UPDATE attendance SET type = ?, timestamp = ?, ip_address = ? WHERE id = ?";
        $update_stmt = $pdo->prepare($update_query);
        $update_stmt->execute([$type, $timestamp, $ip_address, $record_id]);

        $message = "Record updated successfully.";
        header('Location: records.php'); // Redirigir a la página de lista
        exit;
    } else {
        $message = "Please fill in all the required fields.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <title>Edit Attendance Record</title>
</head>
<body class="bg-gray-100 text-gray-800">
    <div class="container mx-auto mt-10">
        <h2 class="text-2xl font-bold mb-4">Edit Attendance Record</h2>
        <?php if ($message): ?>
            <div class="bg-green-100 text-green-800 p-2 mb-4 rounded">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Mostrar Full Name y Username -->
        <div class="bg-white p-6 rounded shadow-md mb-4">
            <h3 class="text-lg font-semibold">Employee Details</h3>
            <p><strong>Full Name:</strong> <?= htmlspecialchars($record['full_name']) ?></p>
            <p><strong>Username:</strong> <?= htmlspecialchars($record['username']) ?></p>
        </div>

        <!-- Formulario de edición -->
        <form method="POST" class="bg-white p-6 rounded shadow-md">
            <div class="mb-4">
                <label for="type" class="block text-sm font-bold mb-2">Type</label>
                <select name="type" id="type" class="p-2 border rounded w-full">
                    <option value="Entry" <?= $record['type'] === 'Entry' ? 'selected' : '' ?>>Entry</option>
                    <option value="Lunch" <?= $record['type'] === 'Lunch' ? 'selected' : '' ?>>Lunch</option>
                    <option value="Break" <?= $record['type'] === 'Break' ? 'selected' : '' ?>>Break</option>
                    <option value="Exit" <?= $record['type'] === 'Exit' ? 'selected' : '' ?>>Exit</option>
                </select>
            </div>
            <div class="mb-4">
                <label for="timestamp" class="block text-sm font-bold mb-2">Timestamp</label>
                <input type="datetime-local" name="timestamp" id="timestamp" value="<?= date('Y-m-d\TH:i', strtotime($record['timestamp'])) ?>" class="p-2 border rounded w-full" required>
            </div>
            <div class="mb-4">
                <label for="ip_address" class="block text-sm font-bold mb-2">IP Address</label>
                <input type="text" name="ip_address" id="ip_address" value="<?= htmlspecialchars($record['ip_address']) ?>" class="p-2 border rounded w-full" required>
            </div>
            <button type="submit" class="bg-blue-500 text-white py-2 px-4 rounded hover:bg-blue-700">Save Changes</button>
            <a href="records.php" class="ml-4 text-blue-500 hover:underline">Cancel</a>
        </form>
    </div>
</body>
</html>
