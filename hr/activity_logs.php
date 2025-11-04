<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';

// Check if user is logged in and has permission to view activity logs
if (!isset($_SESSION['user_id'])) {
    header('Location: ../unauthorized.php');
    exit();
}

// Check permission
ensurePermission('activity_logs');

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Filters
$filter_module = $_GET['module'] ?? '';
$filter_action = $_GET['action'] ?? '';
$filter_user = $_GET['user'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$where_conditions = [];
$params = [];

if ($filter_module) {
    $where_conditions[] = "module = :module";
    $params[':module'] = $filter_module;
}

if ($filter_action) {
    $where_conditions[] = "action = :action";
    $params[':action'] = $filter_action;
}

if ($filter_user) {
    $where_conditions[] = "user_id = :user_id";
    $params[':user_id'] = $filter_user;
}

if ($filter_date_from) {
    $where_conditions[] = "DATE(created_at) >= :date_from";
    $params[':date_from'] = $filter_date_from;
}

if ($filter_date_to) {
    $where_conditions[] = "DATE(created_at) <= :date_to";
    $params[':date_to'] = $filter_date_to;
}

if ($search) {
    $where_conditions[] = "(description LIKE :search OR user_name LIKE :search)";
    $params[':search'] = "%{$search}%";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get total count
$count_sql = "SELECT COUNT(*) FROM activity_logs {$where_clause}";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Get logs
$sql = "SELECT * FROM activity_logs {$where_clause} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique modules for filter
$modules_sql = "SELECT DISTINCT module FROM activity_logs ORDER BY module";
$modules = $pdo->query($modules_sql)->fetchAll(PDO::FETCH_COLUMN);

// Get unique actions for filter
$actions_sql = "SELECT DISTINCT action FROM activity_logs ORDER BY action";
$actions = $pdo->query($actions_sql)->fetchAll(PDO::FETCH_COLUMN);

// Get users for filter
$users_sql = "SELECT DISTINCT user_id, user_name FROM activity_logs ORDER BY user_name";
$users = $pdo->query($users_sql)->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Logs de Actividad del Sistema";
include '../header.php';
?>

<style>
.logs-container {
    padding: 20px;
}

.filters-section {
    background: var(--card-bg);
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 15px;
}

.filter-group {
    display: flex;
    flex-direction: column;
}

.filter-group label {
    font-weight: 600;
    margin-bottom: 5px;
    color: var(--text-color);
}

.filter-group select,
.filter-group input {
    padding: 8px;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    background: var(--input-bg);
    color: var(--text-color);
}

.search-box {
    grid-column: 1 / -1;
}

.search-box input {
    width: 100%;
}

.filter-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.3s;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #545b62;
}

.logs-table-container {
    background: var(--card-bg);
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    overflow: hidden;
}

.logs-table {
    width: 100%;
    border-collapse: collapse;
}

.logs-table thead {
    background: var(--header-bg);
}

.logs-table th {
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: var(--text-color);
    border-bottom: 2px solid var(--border-color);
}

.logs-table td {
    padding: 12px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-color);
}

.logs-table tbody tr:hover {
    background: var(--hover-bg);
}

.module-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.module-employees { background: #e3f2fd; color: #1976d2; }
.module-schedules { background: #f3e5f5; color: #7b1fa2; }
.module-payroll { background: #e8f5e9; color: #388e3c; }
.module-recruitment { background: #fff3e0; color: #f57c00; }
.module-medical_leaves { background: #fce4ec; color: #c2185b; }
.module-overtime { background: #e0f2f1; color: #00796b; }
.module-attendance { background: #fff9c4; color: #f57f17; }
.module-calendar { background: #e1bee7; color: #6a1b9a; }
.module-banking { background: #c8e6c9; color: #2e7d32; }
.module-rates { background: #ffccbc; color: #d84315; }
.module-users { background: #b3e5fc; color: #0277bd; }
.module-permissions { background: #ffecb3; color: #f57f17; }
.module-system { background: #cfd8dc; color: #455a64; }

.action-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
}

.action-create { background: #4caf50; color: white; }
.action-update { background: #2196f3; color: white; }
.action-delete { background: #f44336; color: white; }
.action-generate { background: #9c27b0; color: white; }
.action-approve { background: #00bcd4; color: white; }
.action-reject { background: #ff5722; color: white; }
.action-activate { background: #8bc34a; color: white; }
.action-deactivate { background: #ff9800; color: white; }

.details-toggle {
    color: #007bff;
    cursor: pointer;
    text-decoration: underline;
    font-size: 12px;
}

.details-content {
    display: none;
    margin-top: 10px;
    padding: 10px;
    background: var(--hover-bg);
    border-radius: 4px;
    font-size: 12px;
}

.details-content.show {
    display: block;
}

.json-display {
    background: #f5f5f5;
    padding: 8px;
    border-radius: 4px;
    overflow-x: auto;
    font-family: monospace;
    font-size: 11px;
    color: #333;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    padding: 20px;
}

.pagination a,
.pagination span {
    padding: 8px 12px;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    color: var(--text-color);
    text-decoration: none;
}

.pagination a:hover {
    background: var(--hover-bg);
}

.pagination .current {
    background: #007bff;
    color: white;
    border-color: #007bff;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.stat-card {
    background: var(--card-bg);
    padding: 15px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    text-align: center;
}

.stat-value {
    font-size: 32px;
    font-weight: bold;
    color: #007bff;
}

.stat-label {
    font-size: 14px;
    color: var(--text-color);
    margin-top: 5px;
}
</style>

<div class="logs-container">
    <h1>📋 Logs de Actividad del Sistema</h1>
    <p>Registro completo de todas las acciones realizadas en el sistema</p>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($total_records); ?></div>
            <div class="stat-label">Total de Registros</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo count($modules); ?></div>
            <div class="stat-label">Módulos Activos</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo count($users); ?></div>
            <div class="stat-label">Usuarios con Actividad</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-section">
        <h3>🔍 Filtros</h3>
        <form method="GET" action="">
            <div class="filters-grid">
                <div class="filter-group">
                    <label>Módulo</label>
                    <select name="module">
                        <option value="">Todos los módulos</option>
                        <?php foreach ($modules as $module): ?>
                            <option value="<?php echo htmlspecialchars($module); ?>" 
                                <?php echo $filter_module === $module ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($module); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Acción</label>
                    <select name="action">
                        <option value="">Todas las acciones</option>
                        <?php foreach ($actions as $action): ?>
                            <option value="<?php echo htmlspecialchars($action); ?>" 
                                <?php echo $filter_action === $action ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($action); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Usuario</label>
                    <select name="user">
                        <option value="">Todos los usuarios</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['user_id']; ?>" 
                                <?php echo $filter_user == $user['user_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['user_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Fecha Desde</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                </div>

                <div class="filter-group">
                    <label>Fecha Hasta</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                </div>

                <div class="filter-group search-box">
                    <label>Búsqueda</label>
                    <input type="text" name="search" placeholder="Buscar en descripción o usuario..." 
                        value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">Aplicar Filtros</button>
                <a href="activity_logs.php" class="btn btn-secondary">Limpiar</a>
            </div>
        </form>
    </div>

    <!-- Logs Table -->
    <div class="logs-table-container">
        <table class="logs-table">
            <thead>
                <tr>
                    <th>Fecha/Hora</th>
                    <th>Usuario</th>
                    <th>Módulo</th>
                    <th>Acción</th>
                    <th>Descripción</th>
                    <th>IP</th>
                    <th>Detalles</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px;">
                            No se encontraron registros con los filtros aplicados.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td>
                                <div><?php echo date('d/m/Y', strtotime($log['created_at'])); ?></div>
                                <div style="font-size: 12px; color: #666;">
                                    <?php echo date('H:i:s', strtotime($log['created_at'])); ?>
                                </div>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($log['user_name']); ?></div>
                                <div style="font-size: 11px; color: #666;">
                                    <?php echo htmlspecialchars($log['user_role']); ?>
                                </div>
                            </td>
                            <td>
                                <span class="module-badge module-<?php echo htmlspecialchars($log['module']); ?>">
                                    <?php echo htmlspecialchars($log['module']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="action-badge action-<?php echo htmlspecialchars($log['action']); ?>">
                                    <?php echo htmlspecialchars($log['action']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($log['description']); ?></td>
                            <td style="font-size: 11px;"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                            <td>
                                <?php if ($log['old_values'] || $log['new_values']): ?>
                                    <span class="details-toggle" onclick="toggleDetails(<?php echo $log['id']; ?>)">
                                        Ver detalles
                                    </span>
                                    <div id="details-<?php echo $log['id']; ?>" class="details-content">
                                        <?php if ($log['old_values']): ?>
                                            <strong>Valores Anteriores:</strong>
                                            <div class="json-display">
                                                <?php echo htmlspecialchars(json_encode(json_decode($log['old_values']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($log['new_values']): ?>
                                            <strong>Valores Nuevos:</strong>
                                            <div class="json-display">
                                                <?php echo htmlspecialchars(json_encode(json_decode($log['new_values']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($log['user_agent']): ?>
                                            <strong>User Agent:</strong>
                                            <div style="font-size: 10px; margin-top: 5px;">
                                                <?php echo htmlspecialchars($log['user_agent']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=1<?php echo $filter_module ? "&module={$filter_module}" : ''; ?><?php echo $filter_action ? "&action={$filter_action}" : ''; ?><?php echo $filter_user ? "&user={$filter_user}" : ''; ?><?php echo $filter_date_from ? "&date_from={$filter_date_from}" : ''; ?><?php echo $filter_date_to ? "&date_to={$filter_date_to}" : ''; ?><?php echo $search ? "&search={$search}" : ''; ?>">Primera</a>
                <a href="?page=<?php echo $page - 1; ?><?php echo $filter_module ? "&module={$filter_module}" : ''; ?><?php echo $filter_action ? "&action={$filter_action}" : ''; ?><?php echo $filter_user ? "&user={$filter_user}" : ''; ?><?php echo $filter_date_from ? "&date_from={$filter_date_from}" : ''; ?><?php echo $filter_date_to ? "&date_to={$filter_date_to}" : ''; ?><?php echo $search ? "&search={$search}" : ''; ?>">Anterior</a>
            <?php endif; ?>

            <span class="current">Página <?php echo $page; ?> de <?php echo $total_pages; ?></span>

            <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?><?php echo $filter_module ? "&module={$filter_module}" : ''; ?><?php echo $filter_action ? "&action={$filter_action}" : ''; ?><?php echo $filter_user ? "&user={$filter_user}" : ''; ?><?php echo $filter_date_from ? "&date_from={$filter_date_from}" : ''; ?><?php echo $filter_date_to ? "&date_to={$filter_date_to}" : ''; ?><?php echo $search ? "&search={$search}" : ''; ?>">Siguiente</a>
                <a href="?page=<?php echo $total_pages; ?><?php echo $filter_module ? "&module={$filter_module}" : ''; ?><?php echo $filter_action ? "&action={$filter_action}" : ''; ?><?php echo $filter_user ? "&user={$filter_user}" : ''; ?><?php echo $filter_date_from ? "&date_from={$filter_date_from}" : ''; ?><?php echo $filter_date_to ? "&date_to={$filter_date_to}" : ''; ?><?php echo $search ? "&search={$search}" : ''; ?>">Última</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleDetails(logId) {
    const detailsDiv = document.getElementById('details-' + logId);
    detailsDiv.classList.toggle('show');
}
</script>

<?php include '../footer.php'; ?>
