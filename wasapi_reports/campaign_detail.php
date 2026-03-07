<?php
require_once __DIR__ . '/../header.php';

// Only authenticated users
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$campaignId = $_GET['id'] ?? null;
if (!$campaignId) {
    header("Location: index.php");
    exit;
}
?>

<div class="container mx-auto px-4 py-8" x-data="campaignDetailPage(<?= (int)$campaignId ?>)">
    <!-- Back Button & Header -->
    <div class="flex items-center gap-4 mb-6">
        <a href="index.php" class="p-2 bg-gray-200 hover:bg-gray-300 rounded-lg text-gray-600 hover:text-gray-800 transition-colors">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div class="flex-1">
            <div class="flex items-center gap-4">
                <div class="h-14 w-14 rounded-xl flex items-center justify-center"
                    :style="'background-color: ' + (campaign?.color || '#3B82F6') + '20'">
                    <i class="fas fa-bullhorn text-2xl" :style="'color: ' + (campaign?.color || '#3B82F6')"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-gray-800" x-text="campaign?.name || 'Cargando...'"></h1>
                    <p class="text-gray-500" x-text="campaign?.description || 'Sin descripción'"></p>
                </div>
            </div>
        </div>
        
        <!-- Status Badge -->
        <span x-show="campaign?.is_active" class="px-3 py-1 bg-emerald-100 text-emerald-600 rounded-lg text-sm font-medium">
            <i class="fas fa-check-circle mr-1"></i> Activa
        </span>
    </div>
    
    <!-- Date Filter -->
    <div class="flex gap-3 mb-6">
        <input type="date" x-model="filters.startDate" @change="loadData"
            class="border rounded-lg px-3 py-2 text-sm">
        <input type="date" x-model="filters.endDate" @change="loadData"
            class="border rounded-lg px-3 py-2 text-sm">
        <button @click="loadData" class="bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
            <i class="fas fa-sync-alt" :class="{'animate-spin': loading}"></i>
        </button>
    </div>
    
    <!-- Loading State -->
    <div x-show="loading && !data" class="flex items-center justify-center py-12">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-emerald-500"></div>
    </div>
    
    <!-- Main Content -->
    <div x-show="data" class="space-y-6">
        <!-- Summary KPIs -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-6">
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-sm">Agentes</p>
                <p class="text-3xl font-bold text-gray-800" x-text="campaign?.total_agents || 0">0</p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-sm">Supervisores</p>
                <p class="text-3xl font-bold text-blue-500" x-text="campaign?.total_supervisors || 0">0</p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-sm">Total Transacciones</p>
                <p class="text-3xl font-bold text-emerald-500" x-text="formatNumber(totalTransactions)">0</p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-sm">Total Ventas</p>
                <p class="text-3xl font-bold text-purple-500" x-text="formatNumber(totalSales)">0</p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-sm">Llamadas Manejadas</p>
                <p class="text-3xl font-bold text-amber-500" x-text="formatNumber(totalCalls)">0</p>
            </div>
            <div class="bg-white rounded-xl shadow-lg p-5">
                <p class="text-gray-500 text-sm">AHT Promedio</p>
                <p class="text-3xl font-bold text-gray-800" x-text="formatSeconds(avgAht)">0:00</p>
            </div>
        </div>
        
        <!-- Charts Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Daily Transactions Chart -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">
                    <i class="fas fa-chart-bar text-emerald-500 mr-2"></i>
                    Transacciones Diarias
                </h3>
                <div class="h-64">
                    <canvas id="dailyTransChart"></canvas>
                </div>
            </div>
            
            <!-- Daily Performance -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">
                    <i class="fas fa-phone text-blue-500 mr-2"></i>
                    Llamadas Diarias
                </h3>
                <div class="h-64">
                    <canvas id="dailyCallsChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Agents Table -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="p-4 border-b flex items-center justify-between">
                <h3 class="text-xl font-semibold text-gray-800">
                    <i class="fas fa-users text-gray-500 mr-2"></i>
                    Agentes de la Campaña
                </h3>
                <span class="text-gray-500 text-sm" x-text="agents.length + ' agentes'"></span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-100 text-gray-600 font-medium text-xs uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3">Agente</th>
                            <th class="px-4 py-3 text-center">Código</th>
                            <th class="px-4 py-3 text-center">Llamadas</th>
                            <th class="px-4 py-3 text-center">AHT</th>
                            <th class="px-4 py-3 text-center">Horas Login</th>
                            <th class="px-4 py-3 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <template x-for="agent in agents" :key="agent.id">
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="h-8 w-8 rounded-full bg-gray-200 flex items-center justify-center text-xs font-bold text-gray-600"
                                            x-text="(agent.first_name || 'A').substring(0, 1) + (agent.last_name || '').substring(0, 1)"></div>
                                        <span class="text-gray-800 font-medium" x-text="agent.first_name + ' ' + agent.last_name"></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center text-gray-500" x-text="agent.employee_code"></td>
                                <td class="px-4 py-3 text-center text-gray-800 font-medium" x-text="formatNumber(agent.total_calls)"></td>
                                <td class="px-4 py-3 text-center text-gray-600" x-text="formatSeconds(agent.avg_aht)"></td>
                                <td class="px-4 py-3 text-center text-gray-600" x-text="(agent.total_login_seconds / 3600).toFixed(1) + 'h'"></td>
                                <td class="px-4 py-3 text-center">
                                    <a :href="'agent_detail.php?id=' + agent.id" 
                                        class="p-2 text-gray-500 hover:text-blue-500 transition-colors inline-block">
                                        <i class="fas fa-chart-line"></i>
                                    </a>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div x-show="agents.length === 0" class="p-8 text-center text-gray-500">
                No hay agentes asignados a esta campaña
            </div>
        </div>
        
        <!-- Daily Transactions Table -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="p-4 border-b">
                <h3 class="text-xl font-semibold text-gray-800">
                    <i class="fas fa-calendar-alt text-emerald-500 mr-2"></i>
                    Detalle Diario
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-100 text-gray-600 font-medium text-xs uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3">Fecha</th>
                            <th class="px-4 py-3 text-center">Transacciones</th>
                            <th class="px-4 py-3 text-center">Ventas</th>
                            <th class="px-4 py-3 text-center">Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <template x-for="day in dailyTransactions" :key="day.report_date">
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-800 font-medium" x-text="formatDate(day.report_date)"></td>
                                <td class="px-4 py-3 text-center text-gray-600" x-text="formatNumber(day.transactions)"></td>
                                <td class="px-4 py-3 text-center text-emerald-500 font-medium" x-text="formatNumber(day.sales)"></td>
                                <td class="px-4 py-3 text-center text-purple-500" x-text="'$' + formatNumber(day.revenue)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('alpine:init', () => {
    let transChart = null;
    let callsChart = null;
    
    Alpine.data('campaignDetailPage', (campaignId) => ({
        campaignId: campaignId,
        data: null,
        campaign: null,
        agents: [],
        dailyTransactions: [],
        dailyPerformance: [],
        loading: false,
        
        filters: {
            startDate: new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
            endDate: new Date().toISOString().split('T')[0]
        },
        
        // Computed
        get totalTransactions() {
            return this.dailyTransactions.reduce((sum, d) => sum + parseInt(d.transactions || 0), 0);
        },
        get totalSales() {
            return this.dailyTransactions.reduce((sum, d) => sum + parseInt(d.sales || 0), 0);
        },
        get totalCalls() {
            return this.dailyPerformance.reduce((sum, d) => sum + parseInt(d.calls_handled || 0), 0);
        },
        get avgAht() {
            if (this.dailyPerformance.length === 0) return 0;
            const total = this.dailyPerformance.reduce((sum, d) => sum + parseFloat(d.avg_aht || 0), 0);
            return total / this.dailyPerformance.length;
        },
        
        init() {
            this.loadData();
        },
        
        async loadData() {
            this.loading = true;
            
            try {
                const res = await fetch(`api/campaign_transactions.php?action=detail&campaign_id=${this.campaignId}&start_date=${this.filters.startDate}&end_date=${this.filters.endDate}`);
                const data = await res.json();
                
                if (data.success) {
                    this.data = data;
                    this.campaign = data.campaign;
                    this.agents = data.agents || [];
                    this.dailyTransactions = data.daily_transactions || [];
                    this.dailyPerformance = data.daily_performance || [];
                    
                    this.$nextTick(() => this.updateCharts());
                }
            } catch (e) {
                console.error(e);
            } finally {
                this.loading = false;
            }
        },
        
        updateCharts() {
            // Transactions Chart
            const ctx1 = document.getElementById('dailyTransChart');
            if (ctx1 && this.dailyTransactions.length > 0) {
                if (transChart) transChart.destroy();
                transChart = new Chart(ctx1, {
                    type: 'bar',
                    data: {
                        labels: this.dailyTransactions.map(d => this.formatDate(d.report_date)),
                        datasets: [
                            {
                                label: 'Transacciones',
                                data: this.dailyTransactions.map(d => d.transactions),
                                backgroundColor: 'rgba(16, 185, 129, 0.8)',
                                borderRadius: 4
                            },
                            {
                                label: 'Ventas',
                                data: this.dailyTransactions.map(d => d.sales),
                                backgroundColor: 'rgba(139, 92, 246, 0.8)',
                                borderRadius: 4
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } },
                        scales: {
                            y: { beginAtZero: true, grid: { color: 'rgba(51, 65, 85, 0.3)' } },
                            x: { grid: { display: false } }
                        }
                    }
                });
            }
            
            // Calls Chart
            const ctx2 = document.getElementById('dailyCallsChart');
            if (ctx2 && this.dailyPerformance.length > 0) {
                if (callsChart) callsChart.destroy();
                callsChart = new Chart(ctx2, {
                    type: 'line',
                    data: {
                        labels: this.dailyPerformance.map(d => this.formatDate(d.report_date)),
                        datasets: [{
                            label: 'Llamadas',
                            data: this.dailyPerformance.map(d => d.calls_handled),
                            borderColor: 'rgba(59, 130, 246, 1)',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, grid: { color: 'rgba(51, 65, 85, 0.3)' } },
                            x: { grid: { display: false } }
                        }
                    }
                });
            }
        },
        
        formatNumber(num) {
            if (!num) return '0';
            return new Intl.NumberFormat('es-DO').format(num);
        },
        
        formatDate(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr + 'T00:00:00');
            return d.toLocaleDateString('es-DO', { month: 'short', day: 'numeric' });
        },
        
        formatSeconds(seconds) {
            if (!seconds) return '0:00';
            const m = Math.floor(seconds / 60);
            const s = Math.floor(seconds % 60);
            return `${m}:${s.toString().padStart(2, '0')}`;
        }
    }));
});
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
