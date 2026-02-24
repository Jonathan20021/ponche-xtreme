<?php
session_start();
require_once 'db.php';
require_once 'lib/authorization_functions.php';

ensurePermission('vicidial_reports');

// Get filter parameters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$campaign = $_GET['campaign'] ?? '';
$dailyDate = $_GET['daily_date'] ?? '';

// Daily date override
if ($dailyDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dailyDate)) {
    $startDate = $dailyDate;
    $endDate = $dailyDate;
}

// Fetch data for the report
$campaignFilter = $campaign ? "AND current_user_group = :campaign" : "";
$stmt = $pdo->prepare("
    SELECT 
        user_name,
        user_id,
        current_user_group,
        SUM(calls) as total_calls,
        SUM(time_total) as time_total,
        SUM(pause_time) as pause_time,
        SUM(wait_time) as wait_time,
        SUM(talk_time) as talk_time,
        SUM(dispo_time) as dispo_time,
        SUM(dead_time) as dead_time,
        SUM(customer_time) as customer_time,
        SUM(sale) as sale,
        SUM(pedido) as pedido,
        SUM(orden) as orden,
        SUM(a + b + callbk + colgo + dair + dc + `dec` + dnc + n + ni + nocal + np + pregun + ptrans + quejas + reserv + seguim + silenc + xfer) as other_dispositions
    FROM vicidial_login_stats
    WHERE upload_date BETWEEN :start_date AND :end_date
    $campaignFilter
    GROUP BY user_name, user_id, current_user_group
    ORDER BY total_calls DESC
");

$params = ['start_date' => $startDate, 'end_date' => $endDate];
if ($campaign) {
    $params['campaign'] = $campaign;
}

$stmt->execute($params);
$stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function to format seconds to HH:MM:SS
function formatTime($seconds)
{
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

// Get upload history
$historyStmt = $pdo->prepare("
    SELECT u.*, us.username
    FROM vicidial_uploads u
    LEFT JOIN users us ON u.uploaded_by = us.id
    ORDER BY u.created_at DESC
    LIMIT 10
");
$historyStmt->execute();
$uploadHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>

<script src="assets/js/vicidial_charts.js"></script>

<div class="container mx-auto px-4 py-6" x-data="vicidialReports()">
    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-slate-100 mb-2">
            <i class="fas fa-phone-volume text-cyan-400 mr-3"></i>
            Reportes Vicidial
        </h1>
        <p class="text-slate-400">Analytics avanzados y métricas de rendimiento</p>
    </div>

    <!-- Upload Section -->
    <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6 mb-6">
        <h2 class="text-xl font-semibold text-slate-100 mb-4">
            <i class="fas fa-upload text-cyan-400 mr-2"></i>
            Subir Reporte CSV
        </h2>

        <form @submit.prevent="uploadCSV" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Archivo CSV</label>
                    <input type="file" x-ref="csvFile" accept=".csv" required
                        class="w-full px-4 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-slate-200 focus:ring-2 focus:ring-cyan-500 focus:border-transparent">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Fecha del Reporte</label>
                    <input type="date" x-model="uploadDate" required
                        class="w-full px-4 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-slate-200 focus:ring-2 focus:ring-cyan-500 focus:border-transparent">
                </div>

                <div class="flex items-end">
                    <button type="submit" :disabled="uploading"
                        class="w-full px-6 py-2 bg-gradient-to-r from-cyan-500 to-blue-500 text-white font-semibold rounded-lg hover:from-cyan-600 hover:to-blue-600 transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fas fa-cloud-upload-alt mr-2"></i>
                        <span x-text="uploading ? 'Subiendo...' : 'Subir Reporte'"></span>
                    </button>
                </div>
            </div>

            <div x-show="uploadMessage"
                :class="uploadSuccess ? 'bg-green-500/20 border-green-500/50 text-green-200' : 'bg-red-500/20 border-red-500/50 text-red-200'"
                class="px-4 py-3 rounded-lg border" x-transition>
                <span x-text="uploadMessage"></span>
            </div>
        </form>
    </div>

    <!-- Filters -->
    <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6 mb-6">
        <h2 class="text-xl font-semibold text-slate-100 mb-4">
            <i class="fas fa-filter text-cyan-400 mr-2"></i>
            Filtros
        </h2>

        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Fecha Inicio</label>
                <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>"
                    class="w-full px-4 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-slate-200 focus:ring-2 focus:ring-cyan-500 focus:border-transparent">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Fecha Fin</label>
                <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>"
                    class="w-full px-4 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-slate-200 focus:ring-2 focus:ring-cyan-500 focus:border-transparent">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Campaña</label>
                <select name="campaign" x-model="selectedCampaign"
                    class="w-full px-4 py-2 bg-slate-900/50 border border-slate-600 rounded-lg text-slate-200 focus:ring-2 focus:ring-cyan-500 focus:border-transparent">
                    <option value="">Todas las campañas</option>
                    <template x-for="camp in campaigns" :key="camp">
                        <option :value="camp" :selected="camp === '<?= htmlspecialchars($campaign) ?>'" x-text="camp">
                        </option>
                    </template>
                </select>
            </div>

            <div class="flex items-end">
                <button type="submit"
                    class="w-full px-6 py-2 bg-gradient-to-r from-purple-500 to-pink-500 text-white font-semibold rounded-lg hover:from-purple-600 hover:to-pink-600 transition-all">
                    <i class="fas fa-search mr-2"></i>
                    Filtrar
                </button>
            </div>
        </form>
    </div>

    <!-- Daily Date Navigation Bar -->
    <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-4 mb-6">
        <div class="flex items-center gap-3 mb-3">
            <i class="fas fa-calendar-day text-cyan-400"></i>
            <h3 class="text-sm font-semibold text-slate-300">Vista Diaria</h3>
            <span x-show="dailyDate" class="text-xs bg-cyan-500/20 text-cyan-300 px-2 py-1 rounded-full">
                Mostrando: <span x-text="dailyDate"></span>
            </span>
        </div>
        <div class="flex flex-wrap gap-2 items-center">
            <button @click="selectDay('')"
                :class="!dailyDate ? 'bg-gradient-to-r from-cyan-500 to-blue-500 text-white shadow-lg shadow-cyan-500/25' : 'bg-slate-700/50 text-slate-300 hover:bg-slate-600/50'"
                class="px-4 py-2 rounded-lg text-sm font-semibold transition-all">
                <i class="fas fa-layer-group mr-1"></i>
                Ver Todo
            </button>
            <div class="w-px h-6 bg-slate-600"></div>
            <template x-for="date in availableDates" :key="date">
                <button @click="selectDay(date)"
                    :class="dailyDate === date ? 'bg-gradient-to-r from-purple-500 to-pink-500 text-white shadow-lg shadow-purple-500/25' : 'bg-slate-700/50 text-slate-400 hover:bg-slate-600/50 hover:text-slate-200'"
                    class="px-3 py-2 rounded-lg text-sm font-medium transition-all">
                    <span x-text="formatDateLabel(date)"></span>
                </button>
            </template>
            <span x-show="availableDates.length === 0" class="text-sm text-slate-500 italic">
                No hay fechas disponibles en este rango
            </span>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl mb-6 overflow-hidden">
        <div class="flex border-b border-slate-700">
            <button @click="activeTab = 'overview'"
                :class="activeTab === 'overview' ? 'bg-cyan-500/20 text-cyan-400 border-b-2 border-cyan-400' : 'text-slate-400 hover:text-slate-200'"
                class="px-6 py-4 font-semibold transition-all">
                <i class="fas fa-chart-pie mr-2"></i>
                Overview
            </button>
            <button @click="activeTab = 'compare'"
                :class="activeTab === 'compare' ? 'bg-cyan-500/20 text-cyan-400 border-b-2 border-cyan-400' : 'text-slate-400 hover:text-slate-200'"
                class="px-6 py-4 font-semibold transition-all">
                <i class="fas fa-balance-scale mr-2"></i>
                Comparar
            </button>
            <button @click="activeTab = 'rankings'"
                :class="activeTab === 'rankings' ? 'bg-cyan-500/20 text-cyan-400 border-b-2 border-cyan-400' : 'text-slate-400 hover:text-slate-200'"
                class="px-6 py-4 font-semibold transition-all">
                <i class="fas fa-trophy mr-2"></i>
                Rankings
            </button>
        </div>
    </div>

    <!-- TAB: Overview -->
    <div x-show="activeTab === 'overview'" x-transition>
        <!-- KPIs Section -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            <template x-for="(kpi, key) in kpis" :key="key">
                <div
                    class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6 hover:border-slate-600 transition-all">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-slate-400" x-text="kpi.label"></span>
                        <i :class="'fas ' + kpi.icon + ' text-' + kpi.color + '-400'"></i>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-3xl font-bold" :class="'text-' + kpi.color + '-400'"
                            x-text="kpi.value"></span>
                        <span class="text-sm text-slate-500" x-text="kpi.unit"></span>
                    </div>
                </div>
            </template>
        </div>

        <!-- Alerts Section -->
        <div x-show="alerts.length > 0" class="mb-6">
            <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6">
                <h2 class="text-xl font-semibold text-slate-100 mb-4">
                    <i class="fas fa-exclamation-circle text-yellow-400 mr-2"></i>
                    Alertas y Recomendaciones
                </h2>

                <div class="space-y-3">
                    <template x-for="alert in alerts" :key="alert.title">
                        <div :class="{
                            'bg-yellow-500/10 border-yellow-500/50': alert.type === 'warning',
                            'bg-blue-500/10 border-blue-500/50': alert.type === 'info',
                            'bg-red-500/10 border-red-500/50': alert.type === 'danger'
                        }" class="border rounded-lg p-4">
                            <div class="flex items-start gap-3">
                                <i :class="'fas ' + alert.icon + ' text-xl'" :class="{
                                    'text-yellow-400': alert.type === 'warning',
                                    'text-blue-400': alert.type === 'info',
                                    'text-red-400': alert.type === 'danger'
                                }"></i>
                                <div class="flex-1">
                                    <h3 class="font-semibold text-slate-100 mb-1" x-text="alert.title"></h3>
                                    <p class="text-sm text-slate-300" x-text="alert.message"></p>
                                    <div x-show="alert.agents && alert.agents.length > 0" class="mt-2">
                                        <span class="text-xs text-slate-400">Agentes: </span>
                                        <span class="text-xs text-slate-300" x-text="alert.agents.join(', ')"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6">
                <div style="height: 300px;">
                    <canvas id="topAgentsChart"></canvas>
                </div>
            </div>

            <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6">
                <div style="height: 300px;">
                    <canvas id="timeDistributionChart"></canvas>
                </div>
            </div>

            <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6">
                <div style="height: 300px;">
                    <canvas id="trendsChart"></canvas>
                </div>
            </div>

            <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6">
                <div style="height: 300px;">
                    <canvas id="dispositionChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Data Table -->
        <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold text-slate-100">
                    <i class="fas fa-table text-cyan-400 mr-2"></i>
                    Estadísticas Detalladas
                </h2>
                <button @click="exportToExcel"
                    class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-all">
                    <i class="fas fa-file-excel mr-2"></i>
                    Exportar Excel
                </button>
            </div>

            <?php if (empty($stats)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-inbox text-6xl text-slate-600 mb-4"></i>
                    <p class="text-slate-400 text-lg">No se encontraron registros para el rango de fechas seleccionado</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-300 font-semibold">Agente</th>
                                <th class="text-left py-3 px-4 text-slate-300 font-semibold">Campaña</th>
                                <th class="text-right py-3 px-4 text-slate-300 font-semibold">Llamadas</th>
                                <th class="text-right py-3 px-4 text-slate-300 font-semibold">Conversiones</th>
                                <th class="text-right py-3 px-4 text-slate-300 font-semibold">Tasa Conv.</th>
                                <th class="text-right py-3 px-4 text-slate-300 font-semibold">AHT</th>
                                <th class="text-right py-3 px-4 text-slate-300 font-semibold">Ocupación</th>
                                <th class="text-right py-3 px-4 text-slate-300 font-semibold">Tiempo Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats as $row):
                                $conversions = $row['sale'] + $row['pedido'] + $row['orden'];
                                $conversionRate = $row['total_calls'] > 0 ? round(($conversions / $row['total_calls']) * 100, 2) : 0;
                                $aht = $row['total_calls'] > 0 ? round(($row['talk_time'] + $row['dispo_time']) / $row['total_calls'], 0) : 0;
                                $occupancy = $row['time_total'] > 0 ? round((($row['talk_time'] + $row['dispo_time'] + $row['dead_time']) / $row['time_total']) * 100, 2) : 0;
                                ?>
                                <tr class="border-b border-slate-700/50 hover:bg-slate-700/30 transition-colors">
                                    <td class="py-3 px-4 text-slate-200"><?= htmlspecialchars($row['user_name']) ?></td>
                                    <td class="py-3 px-4 text-slate-400"><?= htmlspecialchars($row['current_user_group']) ?>
                                    </td>
                                    <td class="py-3 px-4 text-right text-slate-200"><?= number_format($row['total_calls']) ?>
                                    </td>
                                    <td class="py-3 px-4 text-right text-green-400 font-semibold">
                                        <?= number_format($conversions) ?>
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <span
                                            class="<?= $conversionRate >= 10 ? 'text-green-400' : ($conversionRate >= 5 ? 'text-yellow-400' : 'text-red-400') ?> font-semibold">
                                            <?= $conversionRate ?>%
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-right text-slate-300"><?= gmdate('i:s', $aht) ?></td>
                                    <td class="py-3 px-4 text-right">
                                        <span
                                            class="<?= $occupancy >= 80 ? 'text-green-400' : ($occupancy >= 60 ? 'text-yellow-400' : 'text-red-400') ?> font-semibold">
                                            <?= $occupancy ?>%
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-right text-slate-300"><?= formatTime($row['time_total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Upload History -->
        <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6">
            <h2 class="text-xl font-semibold text-slate-100 mb-4">
                <i class="fas fa-history text-cyan-400 mr-2"></i>
                Historial de Subidas
            </h2>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-3 px-4 text-slate-300 font-semibold">Archivo</th>
                            <th class="text-left py-3 px-4 text-slate-300 font-semibold">Fecha Reporte</th>
                            <th class="text-right py-3 px-4 text-slate-300 font-semibold">Registros</th>
                            <th class="text-left py-3 px-4 text-slate-300 font-semibold">Subido Por</th>
                            <th class="text-left py-3 px-4 text-slate-300 font-semibold">Fecha Subida</th>
                            <th class="text-center py-3 px-4 text-slate-300 font-semibold">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($uploadHistory as $upload): ?>
                            <tr class="border-b border-slate-700/50 hover:bg-slate-700/30 transition-colors">
                                <td class="py-3 px-4 text-slate-200"><?= htmlspecialchars($upload['filename']) ?></td>
                                <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($upload['upload_date']) ?></td>
                                <td class="py-3 px-4 text-right text-slate-300">
                                    <?= number_format($upload['record_count']) ?>
                                </td>
                                <td class="py-3 px-4 text-slate-300"><?= htmlspecialchars($upload['username'] ?? 'N/A') ?>
                                </td>
                                <td class="py-3 px-4 text-slate-400">
                                    <?= date('Y-m-d H:i', strtotime($upload['created_at'])) ?>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <button
                                        @click="deleteUpload(<?= $upload['id'] ?>, '<?= htmlspecialchars($upload['filename']) ?>')"
                                        class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white text-xs rounded transition-all">
                                        <i class="fas fa-trash mr-1"></i>
                                        Eliminar
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB: Compare -->
    <div x-show="activeTab === 'compare'" x-transition>
        <!-- Comparison KPIs -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            <template x-for="(comp, key) in comparisons" :key="key">
                <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-slate-400" x-text="comp.label"></span>
                        <i :class="{
                            'fas fa-arrow-up text-green-400': comp.trend === 'up',
                            'fas fa-arrow-down text-red-400': comp.trend === 'down',
                            'fas fa-minus text-slate-400': comp.trend === 'neutral'
                        }"></i>
                    </div>
                    <div class="flex items-baseline gap-2 mb-2">
                        <span class="text-3xl font-bold text-cyan-400" x-text="comp.current"></span>
                        <span class="text-sm text-slate-500" x-text="comp.unit"></span>
                    </div>
                    <div class="flex items-center gap-2 text-sm">
                        <span class="text-slate-500">vs anterior:</span>
                        <span class="text-slate-400" x-text="comp.previous"></span>
                        <span :class="{
                            'text-green-400': comp.trend === 'up',
                            'text-red-400': comp.trend === 'down',
                            'text-slate-400': comp.trend === 'neutral'
                        }" class="font-semibold" x-text="(comp.variance > 0 ? '+' : '') + comp.variance + '%'"></span>
                    </div>
                </div>
            </template>
        </div>

        <!-- Comparison Chart -->
        <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6 mb-6">
            <div style="height: 400px;">
                <canvas id="comparisonChart"></canvas>
            </div>
        </div>

        <!-- Period Info -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6">
                <h3 class="text-lg font-semibold text-cyan-400 mb-2">Período Actual</h3>
                <p class="text-slate-300" x-text="periodInfo.current"></p>
            </div>
            <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6">
                <h3 class="text-lg font-semibold text-purple-400 mb-2">Período Anterior</h3>
                <p class="text-slate-300" x-text="periodInfo.previous"></p>
            </div>
        </div>
    </div>

    <!-- TAB: Rankings -->
    <div x-show="activeTab === 'rankings'" x-transition>
        <!-- Top 3 Podium -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <template x-for="(agent, index) in rankings.slice(0, 3)" :key="agent.name">
                <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6 text-center">
                    <div class="text-6xl mb-3">
                        <span x-show="index === 0">🥇</span>
                        <span x-show="index === 1">🥈</span>
                        <span x-show="index === 2">🥉</span>
                    </div>
                    <h3 class="text-xl font-bold text-slate-100 mb-2" x-text="agent.name"></h3>
                    <div class="text-3xl font-bold text-cyan-400 mb-2" x-text="agent.score"></div>
                    <p class="text-sm text-slate-400">Puntuación</p>
                    <div class="mt-4 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <span class="text-slate-500">Conversión:</span>
                            <span class="text-slate-300 font-semibold" x-text="agent.conversion_rate + '%'"></span>
                        </div>
                        <div>
                            <span class="text-slate-500">Llamadas:</span>
                            <span class="text-slate-300 font-semibold" x-text="agent.calls"></span>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Selected Agent Radar Chart -->
        <div x-show="selectedAgent"
            class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold text-slate-100">
                    <i class="fas fa-user-chart text-cyan-400 mr-2"></i>
                    Perfil de Rendimiento: <span x-text="selectedAgent?.name"></span>
                </h2>
                <button @click="selectedAgent = null" class="text-slate-400 hover:text-slate-200">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div style="height: 400px;">
                <canvas id="radarChart"></canvas>
            </div>
        </div>

        <!-- Full Rankings Table -->
        <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6">
            <h2 class="text-xl font-semibold text-slate-100 mb-4">
                <i class="fas fa-list-ol text-cyan-400 mr-2"></i>
                Ranking Completo
            </h2>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-700">
                            <th class="text-left py-3 px-4 text-slate-300 font-semibold">Rank</th>
                            <th class="text-left py-3 px-4 text-slate-300 font-semibold">Agente</th>
                            <th class="text-right py-3 px-4 text-slate-300 font-semibold">Puntuación</th>
                            <th class="text-right py-3 px-4 text-slate-300 font-semibold">Conversión</th>
                            <th class="text-right py-3 px-4 text-slate-300 font-semibold">Productividad</th>
                            <th class="text-right py-3 px-4 text-slate-300 font-semibold">Ocupación</th>
                            <th class="text-center py-3 px-4 text-slate-300 font-semibold">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="agent in rankings" :key="agent.name">
                            <tr class="border-b border-slate-700/50 hover:bg-slate-700/30 transition-colors">
                                <td class="py-3 px-4">
                                    <span
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-slate-700 text-slate-200 font-semibold"
                                        x-text="agent.rank"></span>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-2">
                                        <span class="text-slate-200" x-text="agent.name"></span>
                                        <span x-show="agent.medal === 'gold'">🥇</span>
                                        <span x-show="agent.medal === 'silver'">🥈</span>
                                        <span x-show="agent.medal === 'bronze'">🥉</span>
                                    </div>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <span class="text-cyan-400 font-bold text-lg" x-text="agent.score"></span>
                                </td>
                                <td class="py-3 px-4 text-right text-slate-300" x-text="agent.conversion_rate + '%'">
                                </td>
                                <td class="py-3 px-4 text-right text-slate-300" x-text="agent.productivity"></td>
                                <td class="py-3 px-4 text-right text-slate-300" x-text="agent.occupancy + '%'"></td>
                                <td class="py-3 px-4 text-center">
                                    <button @click="viewAgentProfile(agent)"
                                        class="px-3 py-1 bg-cyan-600 hover:bg-cyan-700 text-white text-xs rounded transition-all">
                                        Ver Perfil
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    function vicidialReports() {
        return {
            uploading: false,
            uploadMessage: '',
            uploadSuccess: false,
            uploadDate: '<?= date('Y-m-d') ?>',
            selectedCampaign: '<?= htmlspecialchars($campaign) ?>',
            campaigns: [],
            kpis: {},
            alerts: [],
            activeTab: 'overview',
            comparisons: {},
            periodInfo: { current: '', previous: '' },
            rankings: [],
            selectedAgent: null,
            dailyDate: '<?= htmlspecialchars($dailyDate) ?>',
            availableDates: [],

            init() {
                this.loadAvailableDates();
                this.loadAnalytics();
            },

            async loadAvailableDates() {
                try {
                    const params = new URLSearchParams({
                        start_date: '<?= $_GET['start_date'] ?? date('Y-m-01') ?>',
                        end_date: '<?= $_GET['end_date'] ?? date('Y-m-t') ?>'
                    });
                    const response = await fetch(`api/vicidial_analytics.php?action=available_dates&${params}`);
                    const data = await response.json();
                    if (data.success) {
                        this.availableDates = data.dates;
                    }
                } catch (error) {
                    console.error('Error loading available dates:', error);
                }
            },

            selectDay(date) {
                const url = new URL(window.location.href);
                if (date) {
                    url.searchParams.set('daily_date', date);
                } else {
                    url.searchParams.delete('daily_date');
                }
                window.location.href = url.toString();
            },

            formatDateLabel(dateStr) {
                const date = new Date(dateStr + 'T12:00:00');
                const days = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
                const months = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
                return `${days[date.getDay()]} ${date.getDate()} ${months[date.getMonth()]}`;
            },

            async loadAnalytics() {
                const params = new URLSearchParams({
                    start_date: '<?= $_GET['start_date'] ?? date('Y-m-01') ?>',
                    end_date: '<?= $_GET['end_date'] ?? date('Y-m-t') ?>',
                    campaign: '<?= $campaign ?>'
                });
                if (this.dailyDate) {
                    params.set('daily_date', this.dailyDate);
                }

                try {
                    // Load KPIs
                    const kpiResponse = await fetch(`api/vicidial_analytics.php?action=kpis&${params}`);
                    const kpiData = await kpiResponse.json();
                    if (kpiData.success) {
                        this.kpis = kpiData.kpis;
                    }

                    // Load Top Agents
                    const agentsResponse = await fetch(`api/vicidial_analytics.php?action=top_agents&${params}`);
                    const agentsData = await agentsResponse.json();
                    if (agentsData.success && agentsData.agents.length > 0) {
                        window.vicidialCharts.createTopAgentsChart('topAgentsChart', agentsData.agents);
                    }

                    // Load Time Distribution
                    const timeResponse = await fetch(`api/vicidial_analytics.php?action=time_distribution&${params}`);
                    const timeData = await timeResponse.json();
                    if (timeData.success) {
                        window.vicidialCharts.createTimeDistributionChart('timeDistributionChart', timeData.distribution);
                    }

                    // Load Disposition Breakdown
                    const dispResponse = await fetch(`api/vicidial_analytics.php?action=disposition_breakdown&${params}`);
                    const dispData = await dispResponse.json();
                    if (dispData.success) {
                        window.vicidialCharts.createDispositionChart('dispositionChart', dispData.dispositions);
                    }

                    // Load Trends
                    const trendsResponse = await fetch(`api/vicidial_analytics.php?action=trends&${params}`);
                    const trendsData = await trendsResponse.json();
                    if (trendsData.success && trendsData.trends.length > 0) {
                        window.vicidialCharts.createTrendsChart('trendsChart', trendsData.trends);
                    }

                    // Load Alerts
                    const alertsResponse = await fetch(`api/vicidial_analytics.php?action=alerts&${params}`);
                    const alertsData = await alertsResponse.json();
                    if (alertsData.success) {
                        this.alerts = alertsData.alerts;
                    }

                    // Load Campaigns
                    const campaignsResponse = await fetch(`api/vicidial_analytics.php?action=campaigns&${params}`);
                    const campaignsData = await campaignsResponse.json();
                    if (campaignsData.success) {
                        this.campaigns = campaignsData.campaigns;
                    }

                    // Load Comparisons
                    const compResponse = await fetch(`api/vicidial_comparisons.php?${params}`);
                    const compData = await compResponse.json();
                    if (compData.success) {
                        this.comparisons = compData.comparisons;
                        this.periodInfo.current = `${compData.current_period.start} a ${compData.current_period.end}`;
                        this.periodInfo.previous = `${compData.previous_period.start} a ${compData.previous_period.end}`;
                        window.vicidialCharts.createComparisonChart('comparisonChart', compData.comparisons);
                    }

                    // Load Rankings
                    const rankResponse = await fetch(`api/vicidial_rankings.php?${params}`);
                    const rankData = await rankResponse.json();
                    if (rankData.success) {
                        this.rankings = rankData.rankings;
                    }

                } catch (error) {
                    console.error('Error loading analytics:', error);
                }
            },

            viewAgentProfile(agent) {
                this.selectedAgent = agent;
                this.$nextTick(() => {
                    window.vicidialCharts.createRadarChart('radarChart', agent);
                    // Scroll to radar chart
                    document.getElementById('radarChart').scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
            },

            async uploadCSV(event) {
                this.uploading = true;
                this.uploadMessage = '';

                const formData = new FormData();
                formData.append('csv_file', this.$refs.csvFile.files[0]);
                formData.append('report_date', this.uploadDate);

                try {
                    const response = await fetch('api/vicidial_upload.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();
                    this.uploadSuccess = data.success;
                    this.uploadMessage = data.message + (data.record_count ? ` (${data.record_count} registros)` : '');

                    if (data.success) {
                        setTimeout(() => window.location.reload(), 2000);
                    }
                } catch (error) {
                    this.uploadSuccess = false;
                    this.uploadMessage = 'Error al subir el archivo: ' + error.message;
                } finally {
                    this.uploading = false;
                }
            },

            exportToExcel() {
                let url = `api/vicidial_export.php?start_date=<?= $_GET['start_date'] ?? date('Y-m-01') ?>&end_date=<?= $_GET['end_date'] ?? date('Y-m-t') ?>&campaign=<?= $campaign ?>`;
                if (this.dailyDate) {
                    url += `&daily_date=${this.dailyDate}`;
                }
                window.location.href = url;
            },

            async deleteUpload(uploadId, filename) {
                if (!confirm(`¿Estás seguro de que deseas eliminar la subida "${filename}"?\n\nEsto eliminará todos los registros asociados a esta subida.`)) {
                    return;
                }

                try {
                    const formData = new FormData();
                    formData.append('upload_id', uploadId);

                    const response = await fetch('api/vicidial_delete.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        alert(data.message + (data.deleted_records ? ` (${data.deleted_records} registros eliminados)` : ''));
                        window.location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                } catch (error) {
                    alert('Error al eliminar la subida: ' + error.message);
                }
            }
        }
    }
</script>

<?php include 'footer.php'; ?>