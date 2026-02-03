<?php
/**
 * Dashboard Ejecutivo - Gerencia General
 * Vista completa de nómina, costos por campaña y monitor en tiempo real/histórico
 */
session_start();
require_once 'db.php';
require_once 'lib/authorization_functions.php';

// Verificar permisos
ensurePermission('executive_dashboard');

$pageTitle = "Dashboard Ejecutivo";
include 'header.php';
?>

<div class="container mx-auto px-4 py-6">
    <!-- Header Section -->
    <div class="glass-card mb-6 relative overflow-hidden">
        <div class="absolute top-0 right-0 p-4 opacity-10">
            <i class="fas fa-chart-line text-9xl"></i>
        </div>
        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold mb-2 text-primary">
                    <i class="fas fa-chart-pie mr-3 text-cyan-400"></i>Dashboard Ejecutivo
                </h1>
                <p class="text-muted">Vista integral de operaciones, costos y rendimiento</p>
            </div>
            
            <div class="flex flex-col sm:flex-row gap-3 items-end sm:items-center">
                <!-- Date Range Picker -->
                <div class="flex items-center bg-slate-800/50 rounded-lg p-1 border border-slate-700">
                    <input type="date" id="startDate" class="bg-transparent border-none text-sm text-white focus:ring-0 px-2 py-1" value="<?php echo date('Y-m-d'); ?>">
                    <span class="text-gray-400 px-1">-</span>
                    <input type="date" id="endDate" class="bg-transparent border-none text-sm text-white focus:ring-0 px-2 py-1" value="<?php echo date('Y-m-d'); ?>">
                    <button id="applyDateFilter" class="ml-2 bg-cyan-600 hover:bg-cyan-500 text-white px-3 py-1 rounded text-sm transition-colors">
                        <i class="fas fa-filter mr-1"></i> Filtrar
                    </button>
                </div>

                <div class="text-right">
                    <div class="text-xs text-muted">Última actualización</div>
                    <div id="lastUpdate" class="text-sm font-semibold text-cyan-300">--:--:--</div>
                </div>
                <button id="refreshBtn" class="p-2 rounded-full hover:bg-slate-700 text-cyan-400 transition-colors">
                    <i class="fas fa-sync-alt" id="refreshIcon"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- KPIs Section -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
        <div class="metric-card">
            <div class="flex items-center justify-between mb-2">
                <span class="label">Total Empleados</span>
                <i class="fas fa-users text-blue-400 opacity-50"></i>
            </div>
            <div class="value" id="totalEmployees">0</div>
            <div class="text-xs text-muted mt-1" id="activeEmployeesLabel">
                <span class="text-green-400 font-medium" id="activeEmployees">0</span> activos ahora
            </div>
        </div>
        
        <div class="metric-card">
            <div class="flex items-center justify-between mb-2">
                <span class="label">Horas (Periodo)</span>
                <i class="fas fa-clock text-purple-400 opacity-50"></i>
            </div>
            <div class="value text-2xl" id="totalHours">0h</div>
            <div class="flex justify-between text-xs text-muted mt-1">
                <span>USD: <span id="totalHoursUSD" class="text-gray-300">0h</span></span>
                <span>DOP: <span id="totalHoursDOP" class="text-gray-300">0h</span></span>
            </div>
        </div>
        
        <div class="metric-card">
            <div class="flex items-center justify-between mb-2">
                <span class="label">Costo USD</span>
                <i class="fas fa-dollar-sign text-green-400 opacity-50"></i>
            </div>
            <div class="value text-2xl text-green-400" id="totalCostUSD">$0.00</div>
            <div class="text-xs text-muted mt-1">Acumulado en periodo</div>
        </div>
        
        <div class="metric-card">
            <div class="flex items-center justify-between mb-2">
                <span class="label">Costo DOP</span>
                <i class="fas fa-money-bill text-blue-400 opacity-50"></i>
            </div>
            <div class="value text-2xl text-blue-400" id="totalCostDOP">RD$0.00</div>
            <div class="text-xs text-muted mt-1">Acumulado en periodo</div>
        </div>

        <div class="metric-card">
            <div class="flex items-center justify-between mb-2">
                <span class="label">Campañas</span>
                <i class="fas fa-bullhorn text-yellow-400 opacity-50"></i>
            </div>
            <div class="value" id="totalCampaigns">0</div>
            <div class="text-xs text-muted mt-1">Activas en periodo</div>
        </div>
    </div>

    <!-- Operational Summary Section -->
    <div class="glass-card mb-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-bold text-primary flex items-center">
                <i class="fas fa-briefcase mr-2 text-cyan-400"></i>Resumen Operativo
            </h2>
            <span class="chip text-xs" id="attendanceCoverage">Cobertura: --</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4">
            <div class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="label">Activos</span>
                    <i class="fas fa-user-check text-green-400 opacity-50"></i>
                </div>
                <div class="value" id="activeEmployeesTotal">0</div>
                <div class="text-xs text-muted mt-1">Empleados en servicio</div>
            </div>
            <div class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="label">En prueba</span>
                    <i class="fas fa-user-clock text-yellow-400 opacity-50"></i>
                </div>
                <div class="value" id="trialEmployeesTotal">0</div>
                <div class="text-xs text-muted mt-1">Periodo de entrenamiento</div>
            </div>
            <div class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="label">Ausentes</span>
                    <i class="fas fa-user-times text-red-400 opacity-50"></i>
                </div>
                <div class="value" id="absentEmployeesTotal">0</div>
                <div class="text-xs text-muted mt-1">Sin marcaje en rango</div>
            </div>
            <div class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="label">Nuevos ingresos</span>
                    <i class="fas fa-user-plus text-blue-400 opacity-50"></i>
                </div>
                <div class="value" id="newHiresTotal">0</div>
                <div class="text-xs text-muted mt-1">Contrataciones en periodo</div>
            </div>
            <div class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="label">Bajas</span>
                    <i class="fas fa-user-minus text-orange-400 opacity-50"></i>
                </div>
                <div class="value" id="terminationsTotal">0</div>
                <div class="text-xs text-muted mt-1">Terminaciones en periodo</div>
            </div>
            <div class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="label">Asistencias</span>
                    <i class="fas fa-fingerprint text-purple-400 opacity-50"></i>
                </div>
                <div class="value" id="attendanceRecordsTotal">0</div>
                <div class="text-xs text-muted mt-1">Registros en el periodo</div>
            </div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Trend Chart -->
        <div class="glass-card">
            <h3 class="text-lg font-semibold mb-4 text-primary flex items-center">
                <i class="fas fa-chart-area mr-2 text-cyan-400"></i>Tendencia de Actividad
            </h3>
            <div class="relative h-64 w-full">
                <canvas id="trendChart"></canvas>
            </div>
        </div>

        <!-- Cost Distribution Chart -->
        <div class="glass-card">
            <h3 class="text-lg font-semibold mb-4 text-primary flex items-center">
                <i class="fas fa-chart-bar mr-2 text-green-400"></i>Distribución de Costos
            </h3>
            <div class="relative h-64 w-full">
                <canvas id="costChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Operational Charts Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="glass-card">
            <h3 class="text-lg font-semibold mb-4 text-primary flex items-center">
                <i class="fas fa-building mr-2 text-indigo-400"></i>Distribución por Departamento
            </h3>
            <div class="relative h-64 w-full">
                <canvas id="departmentChart"></canvas>
            </div>
        </div>
        <div class="glass-card">
            <h3 class="text-lg font-semibold mb-4 text-primary flex items-center">
                <i class="fas fa-layer-group mr-2 text-amber-400"></i>Asistencia por Tipo
            </h3>
            <div class="relative h-64 w-full">
                <canvas id="attendanceTypeChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Payroll Summary Section -->
    <div class="glass-card mb-6">
        <h2 class="text-xl font-bold text-primary mb-4 flex items-center">
            <i class="fas fa-file-invoice-dollar mr-2 text-emerald-400"></i>Resumen de Nómina
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="label">Estado</span>
                    <i class="fas fa-clipboard-check text-emerald-400 opacity-50"></i>
                </div>
                <div class="value" id="payrollStatus">--</div>
                <div class="text-xs text-muted mt-1" id="payrollPeriod">Periodo: --</div>
            </div>
            <div class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="label">Bruto</span>
                    <i class="fas fa-arrow-trend-up text-green-400 opacity-50"></i>
                </div>
                <div class="value text-green-400" id="payrollGross">$0.00</div>
                <div class="text-xs text-muted mt-1">Total bruto</div>
            </div>
            <div class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="label">Neto</span>
                    <i class="fas fa-arrow-trend-down text-blue-400 opacity-50"></i>
                </div>
                <div class="value text-blue-400" id="payrollNet">$0.00</div>
                <div class="text-xs text-muted mt-1">Total neto</div>
            </div>
            <div class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="label">Promedio hora</span>
                    <i class="fas fa-business-time text-purple-400 opacity-50"></i>
                </div>
                <div class="value" id="payrollAvgRate">$0.00</div>
                <div class="text-xs text-muted mt-1">Tarifa media</div>
            </div>
        </div>
    </div>

    <!-- Campaign Costs Section -->
    <div class="glass-card mb-6">
        <h2 class="text-xl font-bold text-primary mb-4 flex items-center">
            <i class="fas fa-layer-group mr-2 text-blue-500"></i>Desglose por Campaña
        </h2>
        <div id="campaignCosts" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <!-- Campaign cards will be populated here -->
        </div>
    </div>

    <!-- Top Campaigns Section -->
    <div class="glass-card mb-6">
        <h2 class="text-xl font-bold text-primary mb-4 flex items-center">
            <i class="fas fa-trophy mr-2 text-yellow-400"></i>Top Campañas
        </h2>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div>
                <h3 class="text-sm font-semibold text-muted mb-2">Por costo</h3>
                <div class="responsive-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Campaña</th>
                                <th>Empleados</th>
                                <th>Costos</th>
                            </tr>
                        </thead>
                        <tbody id="topCampaignsCost">
                            <tr><td colspan="3" class="text-center text-muted">--</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-muted mb-2">Por horas</h3>
                <div class="responsive-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Campaña</th>
                                <th>Empleados</th>
                                <th>Horas</th>
                            </tr>
                        </thead>
                        <tbody id="topCampaignsHours">
                            <tr><td colspan="3" class="text-center text-muted">--</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Monitor/List Section -->
    <div class="glass-card">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between mb-6 gap-4">
            <h2 class="text-xl font-bold text-primary flex items-center">
                <i class="fas fa-list mr-2 text-purple-500"></i>Detalle de Empleados
            </h2>
            
            <div class="flex flex-col sm:flex-row gap-4 w-full lg:w-auto">
                <!-- Search Box -->
                <div class="relative flex-grow sm:flex-grow-0">
                    <input type="text" 
                           id="searchInput" 
                           placeholder="Buscar empleado..." 
                           class="input-control pl-10 pr-4 w-full sm:w-64">
                    <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                </div>
                
                <!-- View Toggle -->
                <div class="pill-switch">
                    <button id="listViewBtn" class="is-active">
                        <i class="fas fa-list mr-2"></i>Lista
                    </button>
                    <button id="campaignViewBtn">
                        <i class="fas fa-layer-group mr-2"></i>Agrupado
                    </button>
                </div>
            </div>
        </div>

        <!-- Employee List View -->
        <div id="listView">
            <div class="responsive-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Empleado</th>
                            <th>Campaña</th>
                            <th>Estado</th>
                            <th>Horas (Periodo)</th>
                            <th>Ingresos (Periodo)</th>
                            <th>Última Actividad</th>
                        </tr>
                    </thead>
                    <tbody id="employeeTableBody">
                        <!-- Employee rows will be populated here -->
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <div id="pagination" class="flex items-center justify-between mt-6 px-2">
                <div class="text-sm text-muted">
                    Mostrando <span id="showingFrom" class="text-primary font-medium">0</span> a <span id="showingTo" class="text-primary font-medium">0</span> de <span id="totalRecords" class="text-primary font-medium">0</span>
                </div>
                <div class="flex space-x-2" id="paginationButtons">
                    <!-- Pagination buttons will be populated here -->
                </div>
            </div>
        </div>

        <!-- Campaign View -->
        <div id="campaignView" style="display: none;">
            <div id="campaignGroups">
                <!-- Campaign groups will be populated here -->
            </div>
        </div>
    </div>

    <!-- Quality Section -->
    <div class="glass-card mt-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-bold text-primary flex items-center">
                <i class="fas fa-star mr-2 text-amber-400"></i>Calidad y Cumplimiento
            </h2>
            <span class="chip text-xs" id="qualityStatus">Calidad: --</span>
        </div>

        <div id="qualityError" class="hidden mb-4 bg-amber-500/10 border border-amber-500/30 rounded-lg p-4 text-amber-200 text-sm"></div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4 mb-6" id="qualityKpis">
            <div class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="label">Evaluaciones</span>
                    <i class="fas fa-clipboard-check text-amber-400 opacity-50"></i>
                </div>
                <div class="value" id="qualityTotalEvaluations">0</div>
                <div class="text-xs text-muted mt-1">Total en periodo</div>
            </div>
            <div class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="label">Promedio QA</span>
                    <i class="fas fa-chart-line text-green-400 opacity-50"></i>
                </div>
                <div class="value" id="qualityAvgScore">0%</div>
                <div class="text-xs text-muted mt-1">Score general</div>
            </div>
            <div class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="label">Auditorías</span>
                    <i class="fas fa-headphones text-blue-400 opacity-50"></i>
                </div>
                <div class="value" id="qualityAuditedCalls">0</div>
                <div class="text-xs text-muted mt-1">Con llamadas</div>
            </div>
            <div class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="label">Score AI</span>
                    <i class="fas fa-robot text-purple-400 opacity-50"></i>
                </div>
                <div class="value" id="qualityAiScore">0</div>
                <div class="text-xs text-muted mt-1">Promedio analítico</div>
            </div>
            <div class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="label">Agentes evaluados</span>
                    <i class="fas fa-user-check text-cyan-400 opacity-50"></i>
                </div>
                <div class="value" id="qualityAgents">0</div>
                <div class="text-xs text-muted mt-1">Cobertura QA</div>
            </div>
            <div class="metric-card">
                <div class="flex items-center justify-between mb-2">
                    <span class="label">Campañas QA</span>
                    <i class="fas fa-bullseye text-rose-400 opacity-50"></i>
                </div>
                <div class="value" id="qualityCampaigns">0</div>
                <div class="text-xs text-muted mt-1">Con evaluaciones</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="glass-card">
                <h3 class="text-lg font-semibold mb-4 text-primary flex items-center">
                    <i class="fas fa-chart-area mr-2 text-amber-300"></i>Tendencia QA
                </h3>
                <div class="relative h-64 w-full">
                    <canvas id="qualityTrendChart"></canvas>
                </div>
            </div>
            <div class="glass-card">
                <h3 class="text-lg font-semibold mb-4 text-primary flex items-center">
                    <i class="fas fa-chart-bar mr-2 text-blue-300"></i>Calidad por Campaña
                </h3>
                <div class="relative h-64 w-full">
                    <canvas id="qualityCampaignChart"></canvas>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="glass-card">
                <h3 class="text-lg font-semibold mb-4 text-primary flex items-center">
                    <i class="fas fa-trophy mr-2 text-green-400"></i>Top agentes QA
                </h3>
                <div class="responsive-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Agente</th>
                                <th>Evaluaciones</th>
                                <th>Promedio</th>
                            </tr>
                        </thead>
                        <tbody id="qualityTopAgentsTable">
                            <tr><td colspan="3" class="text-center text-muted">--</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="glass-card">
                <h3 class="text-lg font-semibold mb-4 text-primary flex items-center">
                    <i class="fas fa-triangle-exclamation mr-2 text-red-400"></i>Riesgos QA
                </h3>
                <div class="responsive-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Agente</th>
                                <th>Evaluaciones</th>
                                <th>Promedio</th>
                            </tr>
                        </thead>
                        <tbody id="qualityBottomAgentsTable">
                            <tr><td colspan="3" class="text-center text-muted">--</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script>
    // Global variables
    let employeesData = [];
    let campaignsData = [];
    let filteredData = [];
    let currentPage = 1;
    let itemsPerPage = 20;
    let currentView = 'list';
    let searchTerm = '';
    let trendChartInstance = null;
    let costChartInstance = null;
    let departmentChartInstance = null;
    let attendanceTypeChartInstance = null;
    let qualityTrendChartInstance = null;
    let qualityCampaignChartInstance = null;
    let autoRefreshInterval = null;
    
    // Initialize dashboard
    document.addEventListener('DOMContentLoaded', function() {
        loadDashboardData();
        setupEventListeners();
        
        // Auto-refresh every 30 seconds if viewing today
        checkAutoRefresh();
    });
    
    function setupEventListeners() {
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            searchTerm = e.target.value.toLowerCase();
            currentPage = 1;
            filterAndDisplayData();
        });
        
        // View toggle
        document.getElementById('listViewBtn').addEventListener('click', function() {
            switchView('list');
        });
        
        document.getElementById('campaignViewBtn').addEventListener('click', function() {
            switchView('campaign');
        });

        // Date Filter
        document.getElementById('applyDateFilter').addEventListener('click', function() {
            loadDashboardData();
            checkAutoRefresh();
        });

        // Refresh Button
        document.getElementById('refreshBtn').addEventListener('click', function() {
            loadDashboardData();
        });
    }

    function checkAutoRefresh() {
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        const today = new Date().toISOString().split('T')[0];

        if (autoRefreshInterval) clearInterval(autoRefreshInterval);

        if (startDate === today && endDate === today) {
            autoRefreshInterval = setInterval(loadDashboardData, 30000); // 30s refresh
        }
    }
    
    function switchView(view) {
        currentView = view;
        
        // Update button states
        document.getElementById('listViewBtn').classList.toggle('is-active', view === 'list');
        document.getElementById('campaignViewBtn').classList.toggle('is-active', view === 'campaign');
        
        // Show/hide views
        document.getElementById('listView').style.display = view === 'list' ? 'block' : 'none';
        document.getElementById('campaignView').style.display = view === 'campaign' ? 'block' : 'none';
        
        // Refresh display
        filterAndDisplayData();
    }
    
    function loadDashboardData() {
        const refreshIcon = document.getElementById('refreshIcon');
        refreshIcon.classList.add('fa-spin');
        
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;

        fetch(`executive_dashboard_api.php?start_date=${startDate}&end_date=${endDate}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    employeesData = data.employees;
                    campaignsData = data.campaigns;
                    updateKPIs(data.summary, data.is_today);
                    updateOperationalMetrics(data.workforce, data.summary);
                    updateAttendanceTypes(data.attendance);
                    updateDepartments(data.departments);
                    updatePayroll(data.payroll);
                    updateTopCampaigns(data.campaigns_top);
                    updateQualityMetrics(data.quality);
                    updateCampaignCosts(data.campaigns);
                    updateCharts(data.charts);
                    filterAndDisplayData();
                    document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString();
                } else {
                    console.error('Error loading data:', data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            })
            .finally(() => {
                refreshIcon.classList.remove('fa-spin');
            });
    }
    
    function updateKPIs(summary, isToday) {
        document.getElementById('totalEmployees').textContent = summary.total_employees;
        
        const activeLabel = document.getElementById('activeEmployeesLabel');
        if (isToday) {
            document.getElementById('activeEmployees').textContent = summary.active_now;
            activeLabel.style.display = 'block';
        } else {
            activeLabel.style.display = 'none';
        }
        
        // Calculate total hours (USD + DOP)
        const totalHours = summary.total_hours_usd + summary.total_hours_dop;
        document.getElementById('totalHours').textContent = formatHours(totalHours);
        document.getElementById('totalHoursUSD').textContent = formatHours(summary.total_hours_usd);
        document.getElementById('totalHoursDOP').textContent = formatHours(summary.total_hours_dop);
        
        document.getElementById('totalCostUSD').textContent = summary.total_earnings_usd_formatted;
        document.getElementById('totalCostDOP').textContent = summary.total_earnings_dop_formatted;
        document.getElementById('totalCampaigns').textContent = summary.total_campaigns;
    }

    function updateCharts(chartsData) {
        // Trend Chart
        const ctxTrend = document.getElementById('trendChart').getContext('2d');
        if (trendChartInstance) trendChartInstance.destroy();

        const labels = chartsData.daily_trend.map(d => d.date);
        const activeData = chartsData.daily_trend.map(d => d.active_employees);
        const costUSDData = chartsData.daily_trend.map(d => d.cost_usd);

        trendChartInstance = new Chart(ctxTrend, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Empleados Activos',
                        data: activeData,
                        borderColor: '#22d3ee', // Cyan
                        backgroundColor: 'rgba(34, 211, 238, 0.1)',
                        yAxisID: 'y',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Costo USD',
                        data: costUSDData,
                        borderColor: '#4ade80', // Green
                        backgroundColor: 'rgba(74, 222, 128, 0.0)',
                        yAxisID: 'y1',
                        borderDash: [5, 5],
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        labels: { color: '#94a3b8' }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: '#94a3b8' },
                        grid: { color: 'rgba(148, 163, 184, 0.1)' }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        ticks: { color: '#94a3b8' },
                        grid: { color: 'rgba(148, 163, 184, 0.1)' }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: { color: '#4ade80' }
                    }
                }
            }
        });

        // Cost Distribution Chart (Bar)
        const ctxCost = document.getElementById('costChart').getContext('2d');
        if (costChartInstance) costChartInstance.destroy();

        costChartInstance = new Chart(ctxCost, {
            type: 'bar',
            data: {
                labels: ['USD', 'DOP'],
                datasets: [{
                    label: 'Costo Total',
                    data: [chartsData.cost_distribution.USD, chartsData.cost_distribution.DOP],
                    backgroundColor: [
                        'rgba(74, 222, 128, 0.6)', // Green
                        'rgba(96, 165, 250, 0.6)'  // Blue
                    ],
                    borderColor: [
                        '#4ade80',
                        '#60a5fa'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        ticks: { color: '#94a3b8' },
                        grid: { display: false }
                    },
                    y: {
                        ticks: { color: '#94a3b8' },
                        grid: { color: 'rgba(148, 163, 184, 0.1)' }
                    }
                }
            }
        });
    }

    function updateOperationalMetrics(workforce, summary) {
        if (!workforce) return;

        const activeEmployees = workforce.active_employees || 0;
        const trialEmployees = workforce.trial_employees || 0;
        const absentEmployees = workforce.absent_employees || 0;
        const newHires = workforce.new_hires || 0;
        const terminations = workforce.terminations || 0;
        const attendanceRecords = workforce.attendance_records || 0;
        const attendanceUsers = workforce.attendance_users || 0;
        const totalActivePool = activeEmployees + trialEmployees;

        document.getElementById('activeEmployeesTotal').textContent = activeEmployees;
        document.getElementById('trialEmployeesTotal').textContent = trialEmployees;
        document.getElementById('absentEmployeesTotal').textContent = absentEmployees;
        document.getElementById('newHiresTotal').textContent = newHires;
        document.getElementById('terminationsTotal').textContent = terminations;
        document.getElementById('attendanceRecordsTotal').textContent = attendanceRecords;

        const coverage = totalActivePool > 0 ? Math.round((attendanceUsers / totalActivePool) * 100) : 0;
        document.getElementById('attendanceCoverage').textContent = `Cobertura: ${coverage}% (${attendanceUsers}/${totalActivePool})`;
    }

    function updateDepartments(departments) {
        const ctxDept = document.getElementById('departmentChart').getContext('2d');
        if (departmentChartInstance) departmentChartInstance.destroy();

        if (!departments || departments.length === 0) {
            departmentChartInstance = new Chart(ctxDept, {
                type: 'bar',
                data: { labels: ['Sin datos'], datasets: [{ data: [0], backgroundColor: ['rgba(148,163,184,0.3)'] }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
            });
            return;
        }

        const labels = departments.map(d => d.name);
        const data = departments.map(d => d.active_employees ?? d.employees ?? 0);

        departmentChartInstance = new Chart(ctxDept, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Activos',
                    data: data,
                    backgroundColor: 'rgba(99, 102, 241, 0.6)',
                    borderColor: '#6366f1',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#94a3b8' }, grid: { display: false } },
                    y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148, 163, 184, 0.1)' } }
                }
            }
        });
    }

    function updateAttendanceTypes(attendance) {
        const ctxAttendance = document.getElementById('attendanceTypeChart').getContext('2d');
        if (attendanceTypeChartInstance) attendanceTypeChartInstance.destroy();

        if (!attendance || !attendance.by_type || attendance.by_type.length === 0) {
            attendanceTypeChartInstance = new Chart(ctxAttendance, {
                type: 'doughnut',
                data: { labels: ['Sin datos'], datasets: [{ data: [1], backgroundColor: ['rgba(148,163,184,0.3)'] }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#94a3b8' } } } }
            });
            return;
        }

        const labels = attendance.by_type.map(t => t.label);
        const data = attendance.by_type.map(t => t.count);
        const colors = attendance.by_type.map(t => t.color_start || '#94a3b8');

        attendanceTypeChartInstance = new Chart(ctxAttendance, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: colors,
                    borderColor: '#0f172a',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#94a3b8' } } }
            }
        });
    }

    function updatePayroll(payroll) {
        if (!payroll) return;

        if (payroll.has_payroll) {
            document.getElementById('payrollStatus').textContent = payroll.status || 'Disponible';
            document.getElementById('payrollGross').textContent = payroll.total_gross_formatted || '$0.00';
            document.getElementById('payrollNet').textContent = payroll.total_net_formatted || '$0.00';
            document.getElementById('payrollAvgRate').textContent = payroll.avg_hourly_rate ? `$${payroll.avg_hourly_rate.toFixed(2)}` : '$0.00';
            const period = payroll.period_start && payroll.period_end
                ? `${new Date(payroll.period_start).toLocaleDateString()} - ${new Date(payroll.period_end).toLocaleDateString()}`
                : '--';
            document.getElementById('payrollPeriod').textContent = `Periodo: ${period}`;
        } else {
            document.getElementById('payrollStatus').textContent = payroll.message || 'Sin nómina';
            document.getElementById('payrollGross').textContent = '$0.00';
            document.getElementById('payrollNet').textContent = '$0.00';
            document.getElementById('payrollAvgRate').textContent = '$0.00';
            document.getElementById('payrollPeriod').textContent = 'Periodo: --';
        }
    }

    function updateTopCampaigns(campaignsTop) {
        const costBody = document.getElementById('topCampaignsCost');
        const hoursBody = document.getElementById('topCampaignsHours');

        costBody.innerHTML = '';
        hoursBody.innerHTML = '';

        const renderEmpty = (body) => {
            body.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Sin datos</td></tr>';
        };

        if (!campaignsTop || !campaignsTop.by_cost || campaignsTop.by_cost.length === 0) {
            renderEmpty(costBody);
        } else {
            campaignsTop.by_cost.forEach(camp => {
                const row = document.createElement('tr');
                const costParts = [];
                const costUsd = Number(camp.total_cost_usd || 0);
                const costDop = Number(camp.total_cost_dop || 0);
                if (costUsd > 0) costParts.push(`$${costUsd.toFixed(2)}`);
                if (costDop > 0) costParts.push(`RD$${costDop.toFixed(2)}`);
                row.innerHTML = `
                    <td>${camp.name || 'Sin Campaña'}</td>
                    <td>${camp.employees || 0}</td>
                    <td class="text-green-400">${costParts.join(' / ') || '$0.00'}</td>
                `;
                costBody.appendChild(row);
            });
        }

        if (!campaignsTop || !campaignsTop.by_hours || campaignsTop.by_hours.length === 0) {
            renderEmpty(hoursBody);
        } else {
            campaignsTop.by_hours.forEach(camp => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${camp.name || 'Sin Campaña'}</td>
                    <td>${camp.employees || 0}</td>
                    <td class="text-cyan-300">${formatHours(camp.total_hours || 0)}</td>
                `;
                hoursBody.appendChild(row);
            });
        }
    }

    function updateQualityMetrics(quality) {
        const status = document.getElementById('qualityStatus');
        const errorBox = document.getElementById('qualityError');

        if (!quality || !quality.available) {
            status.textContent = 'Calidad: No disponible';
            errorBox.textContent = quality && quality.error ? quality.error : 'Sin datos de calidad.';
            errorBox.classList.remove('hidden');
            updateQualityTrendChart([]);
            updateQualityCampaignChart([]);
            updateQualityTables([], []);
            return;
        }

        errorBox.classList.add('hidden');
        status.textContent = 'Calidad: Activa';

        const summary = quality.summary || {};
        document.getElementById('qualityTotalEvaluations').textContent = summary.total_evaluations || 0;
        document.getElementById('qualityAvgScore').textContent = `${(summary.avg_percentage || 0).toFixed(2)}%`;
        document.getElementById('qualityAuditedCalls').textContent = summary.audited_calls || 0;
        document.getElementById('qualityAiScore').textContent = (summary.avg_ai_score || 0).toFixed(2);
        document.getElementById('qualityAgents').textContent = summary.agents_evaluated || 0;
        document.getElementById('qualityCampaigns').textContent = summary.campaigns_evaluated || 0;

        updateQualityTrendChart(quality.trend || []);
        updateQualityCampaignChart(quality.by_campaign || []);
        updateQualityTables(quality.top_agents || [], quality.bottom_agents || []);
    }

    function updateQualityTrendChart(trend) {
        const ctxQualityTrend = document.getElementById('qualityTrendChart').getContext('2d');
        if (qualityTrendChartInstance) qualityTrendChartInstance.destroy();

        if (!trend || trend.length === 0) {
            qualityTrendChartInstance = new Chart(ctxQualityTrend, {
                type: 'line',
                data: { labels: ['Sin datos'], datasets: [{ data: [0], borderColor: '#94a3b8' }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
            });
            return;
        }

        const labels = trend.map(t => t.date);
        const evals = trend.map(t => t.evaluations);
        const avgScores = trend.map(t => t.avg_score);

        qualityTrendChartInstance = new Chart(ctxQualityTrend, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Evaluaciones',
                        data: evals,
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245, 158, 11, 0.15)',
                        yAxisID: 'y',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Promedio QA',
                        data: avgScores,
                        borderColor: '#38bdf8',
                        backgroundColor: 'rgba(56, 189, 248, 0)',
                        yAxisID: 'y1',
                        borderDash: [6, 4],
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#94a3b8' } } },
                scales: {
                    x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148,163,184,0.1)' } },
                    y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148,163,184,0.1)' } },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        ticks: { color: '#38bdf8' },
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
    }

    function updateQualityCampaignChart(campaigns) {
        const ctxQualityCampaign = document.getElementById('qualityCampaignChart').getContext('2d');
        if (qualityCampaignChartInstance) qualityCampaignChartInstance.destroy();

        if (!campaigns || campaigns.length === 0) {
            qualityCampaignChartInstance = new Chart(ctxQualityCampaign, {
                type: 'bar',
                data: { labels: ['Sin datos'], datasets: [{ data: [0], backgroundColor: 'rgba(148,163,184,0.3)' }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
            });
            return;
        }

        const labels = campaigns.map(c => c.campaign_name || 'Sin campaña');
        const evals = campaigns.map(c => c.evaluations || 0);
        const avgScores = campaigns.map(c => c.avg_score || 0);

        qualityCampaignChartInstance = new Chart(ctxQualityCampaign, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Evaluaciones',
                        data: evals,
                        backgroundColor: 'rgba(99, 102, 241, 0.6)',
                        borderColor: '#6366f1',
                        borderWidth: 1,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Promedio QA',
                        data: avgScores,
                        backgroundColor: 'rgba(34, 211, 238, 0.3)',
                        borderColor: '#22d3ee',
                        borderWidth: 2,
                        type: 'line',
                        yAxisID: 'y1',
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#94a3b8' } } },
                scales: {
                    x: { ticks: { color: '#94a3b8' }, grid: { display: false } },
                    y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148,163,184,0.1)' } },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        ticks: { color: '#22d3ee' },
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
    }

    function updateQualityTables(topAgents, bottomAgents) {
        const topBody = document.getElementById('qualityTopAgentsTable');
        const bottomBody = document.getElementById('qualityBottomAgentsTable');

        topBody.innerHTML = '';
        bottomBody.innerHTML = '';

        const renderEmpty = (body) => {
            body.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Sin datos</td></tr>';
        };

        if (!topAgents || topAgents.length === 0) {
            renderEmpty(topBody);
        } else {
            topAgents.forEach(agent => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${agent.full_name || agent.username || 'N/A'}</td>
                    <td>${agent.evaluations || 0}</td>
                    <td class="text-green-400">${(agent.avg_score || 0).toFixed(2)}%</td>
                `;
                topBody.appendChild(row);
            });
        }

        if (!bottomAgents || bottomAgents.length === 0) {
            renderEmpty(bottomBody);
        } else {
            bottomAgents.forEach(agent => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${agent.full_name || agent.username || 'N/A'}</td>
                    <td>${agent.evaluations || 0}</td>
                    <td class="text-red-400">${(agent.avg_score || 0).toFixed(2)}%</td>
                `;
                bottomBody.appendChild(row);
            });
        }
    }
    
    function updateCampaignCosts(campaigns) {
        const campaignCostsContainer = document.getElementById('campaignCosts');
        campaignCostsContainer.innerHTML = '';
        
        if (!campaigns || campaigns.length === 0) {
            campaignCostsContainer.innerHTML = '<div class="col-span-full text-center text-muted py-8">No hay datos de campañas disponibles</div>';
            return;
        }
        
        campaigns.forEach(campaign => {
            const card = document.createElement('div');
            card.className = 'glass-card p-4 relative overflow-hidden group hover:bg-slate-800/50 transition-colors';
            
            // Color accent bar
            const accent = document.createElement('div');
            accent.className = 'absolute left-0 top-0 bottom-0 w-1';
            accent.style.backgroundColor = campaign.color;
            card.appendChild(accent);
            
            const totalCost = campaign.total_cost_usd + campaign.total_cost_dop; // Rough estimate for sorting visual importance
            const efficiency = campaign.total_hours > 0 ? (totalCost / campaign.total_hours).toFixed(2) : 0;
            
            card.innerHTML += `
                <div class="pl-2">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-semibold text-primary truncate pr-2">${campaign.name}</h3>
                        <span class="chip text-xs">${campaign.code}</span>
                    </div>
                    <div class="space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-muted">Empleados:</span>
                            <span class="font-medium text-primary">${campaign.employees} <span class="text-green-400 text-xs">(${campaign.active_employees} activos)</span></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-muted">Horas:</span>
                            <span class="font-medium text-primary">${formatHours(campaign.total_hours)}</span>
                        </div>
                        ${campaign.total_cost_usd > 0 ? `
                        <div class="flex justify-between text-sm">
                            <span class="text-muted">Costo USD:</span>
                            <span class="font-medium text-green-400">$${campaign.total_cost_usd.toFixed(2)}</span>
                        </div>
                        ` : ''}
                        ${campaign.total_cost_dop > 0 ? `
                        <div class="flex justify-between text-sm">
                            <span class="text-muted">Costo DOP:</span>
                            <span class="font-medium text-blue-400">RD$${campaign.total_cost_dop.toFixed(2)}</span>
                        </div>
                        ` : ''}
                        <div class="border-t border-slate-700/50 pt-2 mt-2">
                            <div class="flex justify-between text-xs">
                                <span class="text-muted">Eficiencia:</span>
                                <span class="font-medium text-purple-400">~${efficiency}/hora</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            campaignCostsContainer.appendChild(card);
        });
    }
    
    function filterAndDisplayData() {
        // Filter data based on search term
        filteredData = employeesData.filter(emp => {
            const fullName = emp.full_name.toLowerCase();
            const position = (emp.position || '').toLowerCase();
            const campaign = (emp.campaign.name || '').toLowerCase();
            
            return fullName.includes(searchTerm) || 
                   position.includes(searchTerm) || 
                   campaign.includes(searchTerm);
        });
        
        if (currentView === 'list') {
            displayListView();
        } else {
            displayCampaignView();
        }
    }
    
    function displayListView() {
        const tbody = document.getElementById('employeeTableBody');
        tbody.innerHTML = '';
        
        // Calculate pagination
        const totalItems = filteredData.length;
        const totalPages = Math.ceil(totalItems / itemsPerPage);
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = Math.min(startIndex + itemsPerPage, totalItems);
        const pageData = filteredData.slice(startIndex, endIndex);
        
        // Display employees
        pageData.forEach(emp => {
            const row = document.createElement('tr');
            
            const statusColors = {
                'active': 'text-green-400',
                'completed': 'text-gray-400',
                'not_today': 'text-gray-500',
                'offline': 'text-gray-500',
                'historical': 'text-blue-300'
            };
            const statusColor = statusColors[emp.status] || 'text-gray-500';
            const photoSrc = emp.photo_path ? `uploads/employee_photos/${emp.photo_path}` : 'assets/images/default-avatar.png';
            
            row.innerHTML = `
                <td>
                    <div class="flex items-center">
                        <img class="h-8 w-8 rounded-full object-cover mr-3 border border-slate-600" src="${photoSrc}" alt="${emp.full_name}">
                        <div>
                            <div class="text-sm font-medium text-primary">${emp.full_name}</div>
                            <div class="text-xs text-muted">${emp.position || 'N/A'}</div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="flex items-center">
                        <div class="w-2 h-2 rounded-full mr-2" style="background-color: ${emp.campaign.color || '#6b7280'}"></div>
                        <span class="text-sm text-primary">${emp.campaign.name || 'Sin Campaña'}</span>
                    </div>
                </td>
                <td>
                    <span class="text-xs font-medium ${statusColor}">
                        <i class="fas fa-circle text-[8px] mr-1"></i>${emp.status_label}
                    </span>
                </td>
                <td class="text-sm text-primary">
                    ${emp.hours_formatted}
                </td>
                <td class="text-sm font-medium text-green-400">
                    ${emp.earnings_formatted}
                </td>
                <td class="text-sm text-muted">
                    ${emp.last_activity ? formatRelativeTime(emp.last_activity) : 'N/A'}
                </td>
            `;
            
            tbody.appendChild(row);
        });
        
        // Update pagination info
        document.getElementById('showingFrom').textContent = totalItems > 0 ? startIndex + 1 : 0;
        document.getElementById('showingTo').textContent = endIndex;
        document.getElementById('totalRecords').textContent = totalItems;
        
        // Update pagination buttons
        updatePaginationButtons(totalPages);
    }
    
    function displayCampaignView() {
        const campaignGroups = {};
        
        filteredData.forEach(emp => {
            const campaign = emp.campaign.name || 'Sin Campaña';
            if (!campaignGroups[campaign]) {
                campaignGroups[campaign] = {
                    name: campaign,
                    color: emp.campaign.color || '#6b7280',
                    employees: []
                };
            }
            campaignGroups[campaign].employees.push(emp);
        });
        
        const container = document.getElementById('campaignGroups');
        container.innerHTML = '';
        
        Object.values(campaignGroups).forEach(group => {
            const groupDiv = document.createElement('div');
            groupDiv.className = 'mb-6';
            
            groupDiv.innerHTML = `
                <div class="flex items-center mb-4 border-b border-slate-700 pb-2">
                    <div class="w-3 h-3 rounded-full mr-3" style="background-color: ${group.color}"></div>
                    <h3 class="text-lg font-semibold text-primary">${group.name}</h3>
                    <span class="ml-2 text-sm text-muted">(${group.employees.length} empleados)</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    ${group.employees.map(emp => `
                        <div class="bg-slate-800/40 rounded-lg p-3 border border-slate-700/50 hover:bg-slate-800/60 transition-colors">
                            <div class="flex items-center mb-3">
                                <img class="h-8 w-8 rounded-full object-cover mr-3" 
                                     src="${emp.photo_path ? `uploads/employee_photos/${emp.photo_path}` : 'assets/images/default-avatar.png'}" 
                                     alt="${emp.full_name}">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-primary truncate">${emp.full_name}</div>
                                    <div class="text-xs text-muted truncate">${emp.position || 'N/A'}</div>
                                </div>
                            </div>
                            <div class="space-y-1">
                                <div class="flex justify-between text-xs">
                                    <span class="text-muted">Horas:</span>
                                    <span class="font-medium text-primary">${emp.hours_formatted}</span>
                                </div>
                                <div class="flex justify-between text-xs">
                                    <span class="text-muted">Ingresos:</span>
                                    <span class="font-medium text-green-400">${emp.earnings_formatted}</span>
                                </div>
                            </div>
                        </div>
                    `).join('')}
                </div>
            `;
            
            container.appendChild(groupDiv);
        });
    }
    
    function updatePaginationButtons(totalPages) {
        const container = document.getElementById('paginationButtons');
        container.innerHTML = '';
        
        if (totalPages <= 1) return;
        
        // Helper to create button
        const createBtn = (text, page, disabled = false, active = false) => {
            const btn = document.createElement('button');
            btn.className = `px-3 py-1 text-sm rounded transition-colors ${
                active 
                ? 'bg-cyan-600 text-white' 
                : 'bg-slate-800 text-slate-300 hover:bg-slate-700 border border-slate-600'
            } ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`;
            btn.textContent = text;
            btn.disabled = disabled;
            if (!disabled) {
                btn.onclick = () => {
                    currentPage = page;
                    displayListView();
                };
            }
            return btn;
        };

        // Previous
        container.appendChild(createBtn('Anterior', currentPage - 1, currentPage === 1));
        
        // Page numbers (simplified window)
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        
        for (let i = startPage; i <= endPage; i++) {
            container.appendChild(createBtn(i, i, false, i === currentPage));
        }
        
        // Next
        container.appendChild(createBtn('Siguiente', currentPage + 1, currentPage === totalPages));
    }
    
    function formatHours(hours) {
        if (hours === 0) return '0h';
        const h = Math.floor(hours);
        const m = Math.round((hours - h) * 60);
        if (m === 0) return h + 'h';
        return h + 'h ' + m + 'm';
    }
    
    function formatRelativeTime(timestamp) {
        const now = new Date();
        const time = new Date(timestamp);
        const diffMs = now - time;
        const diffMins = Math.floor(diffMs / 60000);
        
        if (diffMins < 1) return 'Ahora mismo';
        if (diffMins < 60) return diffMins + 'm';
        
        const diffHours = Math.floor(diffMins / 60);
        if (diffHours < 24) return diffHours + 'h';
        
        return time.toLocaleDateString();
    }
</script>
