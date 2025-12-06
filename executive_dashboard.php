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

    <!-- Campaign Costs Section -->
    <div class="glass-card mb-6">
        <h2 class="text-xl font-bold text-primary mb-4 flex items-center">
            <i class="fas fa-layer-group mr-2 text-blue-500"></i>Desglose por Campaña
        </h2>
        <div id="campaignCosts" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <!-- Campaign cards will be populated here -->
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
