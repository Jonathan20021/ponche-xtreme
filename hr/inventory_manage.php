<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';

ensurePermission('hr_employees', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Handle Add Item Type
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item') {
    $name = trim($_POST['name']);
    $categoryId = (int) $_POST['category_id'];
    $description = trim($_POST['description']);

    try {
        $stmt = $pdo->prepare("INSERT INTO inventory_item_types (name, category_id, description) VALUES (?, ?, ?)");
        $stmt->execute([$name, $categoryId, $description]);
        $successMsg = "Tipo de artículo agregado exitosamente.";
    } catch (Exception $e) {
        $errorMsg = "Error: " . $e->getMessage();
    }
}

// Handle Add Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_category') {
    $name = trim($_POST['name']);
    try {
        $stmt = $pdo->prepare("INSERT INTO inventory_categories (name) VALUES (?)");
        $stmt->execute([$name]);
        $successMsg = "Categoría agregada exitosamente.";
    } catch (Exception $e) {
        $errorMsg = "Error: " . $e->getMessage();
    }
}

// Get Data
$categories = $pdo->query("SELECT * FROM inventory_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$items = $pdo->query("
    SELECT it.*, c.name as category_name
    FROM inventory_item_types it
    JOIN inventory_categories c ON c.id = it.category_id
    ORDER BY c.name, it.name
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Items - HR</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
</head>

<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="flex items-center gap-4 mb-6">
            <a href="inventory.php" class="text-slate-400 hover:text-white transition-colors">
                <i class="fas fa-arrow-left text-xl"></i>
            </a>
            <h1 class="text-3xl font-bold text-white">Configuración de Inventario</h1>
        </div>

        <?php if (isset($successMsg)): ?>
            <div class="status-banner success mb-6">
                <?= htmlspecialchars($successMsg) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <!-- Items Column -->
            <div>
                <div class="glass-card mb-6">
                    <h3 class="text-xl font-bold text-white mb-4">Agregar Nuevo Item</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_item">
                        <div class="form-group mb-4">
                            <label>Nombre del Item</label>
                            <input type="text" name="name" required class="w-full">
                        </div>
                        <div class="form-group mb-4">
                            <label>Categoría</label>
                            <select name="category_id" required class="w-full">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>">
                                        <?= htmlspecialchars($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mb-4">
                            <label>Descripción</label>
                            <input type="text" name="description" class="w-full">
                        </div>
                        <button type="submit" class="btn-primary w-full">Agregar Item</button>
                    </form>
                </div>

                <div class="glass-card">
                    <h3 class="text-xl font-bold text-white mb-4">Items Existentes</h3>
                    <div class="max-h-[500px] overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-slate-400 border-b border-slate-700">
                                <tr>
                                    <th class="py-2">Item</th>
                                    <th class="py-2">Categoría</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700">
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td class="py-3">
                                            <div class="text-white font-medium">
                                                <?= htmlspecialchars($item['name']) ?>
                                            </div>
                                            <div class="text-xs text-slate-500">
                                                <?= htmlspecialchars($item['description']) ?>
                                            </div>
                                        </td>
                                        <td class="py-3 text-slate-400">
                                            <?= htmlspecialchars($item['category_name']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Categories Column -->
            <div>
                <div class="glass-card mb-6">
                    <h3 class="text-xl font-bold text-white mb-4">Agregar Categoría</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_category">
                        <div class="form-group mb-4">
                            <label>Nombre Categoría</label>
                            <input type="text" name="name" required class="w-full">
                        </div>
                        <button type="submit" class="btn-secondary w-full">Agregar Categoría</button>
                    </form>
                </div>

                <div class="glass-card">
                    <h3 class="text-xl font-bold text-white mb-4">Categorías</h3>
                    <ul class="space-y-2">
                        <?php foreach ($categories as $cat): ?>
                            <li class="flex items-center justify-between p-3 bg-slate-800/50 rounded-lg">
                                <span class="text-white">
                                    <?= htmlspecialchars($cat['name']) ?>
                                </span>
                                <span class="text-xs bg-slate-700 text-slate-300 px-2 py-1 rounded">
                                    <?= date('d/M Y', strtotime($cat['created_at'])) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</body>

</html>