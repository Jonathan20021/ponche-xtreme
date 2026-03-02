<?php
session_start();
require_once '../db.php';

// Check permissions - Verificar permiso manage_campaigns
ensurePermission('manage_campaigns', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
if (!in_array($theme, ['dark', 'light'], true)) {
    $theme = 'dark';
}
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
$themeLabel = $theme === 'light' ? 'Modo Oscuro' : 'Modo Claro';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <title>Gestión de Campañas - HR</title>
    <style>
        .campaign-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .campaign-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--campaign-color);
            transition: all 0.3s ease;
        }

        .campaign-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        }

        .campaign-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .campaign-inactive {
            opacity: 0.6;
        }

        .supervisor-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.375rem 0.75rem;
            background: rgba(99, 102, 241, 0.1);
            border: 1px solid rgba(99, 102, 241, 0.2);
            border-radius: 6px;
            font-size: 0.875rem;
            color: #a5b4fc;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 50;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 2rem;
        }
    </style>
</head>

<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="max-w-7xl mx-auto">
            <div class="flex justify-between items-center mb-8">
                <div>
                    <h1 class="text-3xl font-bold text-white mb-2">
                        <i class="fas fa-bullhorn text-blue-400 mr-3"></i>
                        Gestión de Campañas
                    </h1>
                    <p class="text-slate-400">Administra las campañas y asigna supervisores</p>
                </div>
                <div class="flex gap-3">
                    <button onclick="openCreateModal()" class="btn-primary">
                        <i class="fas fa-plus"></i>
                        Nueva Campaña
                    </button>
                    <a href="employees.php" class="btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Volver
                    </a>
                </div>
            </div>

            <div class="glass-card mb-6">
                <div class="p-4">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="bg-blue-500/10 border border-blue-500/20 rounded-lg p-4">
                            <div class="text-2xl font-bold text-blue-400" id="totalCampaigns">-</div>
                            <div class="text-sm text-slate-400 mt-1">
                                <i class="fas fa-bullhorn"></i> Total Campañas
                            </div>
                        </div>
                        <div class="bg-green-500/10 border border-green-500/20 rounded-lg p-4">
                            <div class="text-2xl font-bold text-green-400" id="activeCampaigns">-</div>
                            <div class="text-sm text-slate-400 mt-1">
                                <i class="fas fa-check-circle"></i> Activas
                            </div>
                        </div>
                        <div class="bg-purple-500/10 border border-purple-500/20 rounded-lg p-4">
                            <div class="text-2xl font-bold text-purple-400" id="totalSupervisors">-</div>
                            <div class="text-sm text-slate-400 mt-1">
                                <i class="fas fa-users-cog"></i> Supervisores Asignados
                            </div>
                        </div>
                        <div class="bg-orange-500/10 border border-orange-500/20 rounded-lg p-4">
                            <div class="text-2xl font-bold text-orange-400" id="totalAgents">-</div>
                            <div class="text-sm text-slate-400 mt-1">
                                <i class="fas fa-users"></i> Agentes en Campañas
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="glass-card mb-6">
                <div class="p-5">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-4">
                        <div>
                            <h2 class="text-lg font-semibold text-white">
                                <i class="fas fa-file-import text-emerald-400 mr-2"></i>
                                Carga de Reporte de Ventas (AST Team Performance)
                            </h2>
                            <p class="text-sm text-slate-400">Sube el CSV AST_team_performance_detail para ventas y llamadas.</p>
                            <span
                                onclick="document.getElementById('salesInfo').classList.toggle('hidden')"
                                class="text-xs text-blue-400 mt-2 hover:underline cursor-pointer inline-block"><i class="fas fa-info-circle"></i>
                                Ver información de columnas y análisis</span>
                        </div>
                        
                    </div>

                    <div id="salesInfo"
                        class="hidden bg-slate-800/50 rounded-lg p-4 text-sm text-slate-300 border border-slate-700 mb-6">
                        <strong class="text-white block mb-2"><i class="fas fa-list-alt text-emerald-400 mr-1"></i>
                            Formato Esperado:</strong>
                        <ul class="list-disc pl-5 mb-3 space-y-1 text-xs text-slate-400">
                            <li>El sistema leerá el archivo generado por Vicidial <span class="text-emerald-300">AST_team_performance_detail</span>.</li>
                            <li>La <span class="text-emerald-300">fecha del reporte</span> se extraerá automáticamente del nombre del archivo.</li>
                            <li>Se usarán las métricas de <span class="text-emerald-300">Llamadas (Calls)</span> y <span class="text-emerald-300">Ventas (Sales)</span> de la fila TOTALS del <strong>CALL CENTER TOTAL</strong>.</li>
                        </ul>
                        <strong class="text-white block mb-2"><i class="fas fa-chart-line text-blue-400 mr-1"></i>
                            Análisis en la Web:</strong>
                        <p class="text-xs text-slate-400">Estos datos se utilizan en la vista de control operativo para
                            evaluar el rendimiento general comparando el volumen de atención versus las ventas y para
                            analizar los ingresos financieros de la campaña de manera diaria.</p>
                    </div>

                    <form id="salesReportForm" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                        <div>
                            <label class="text-sm text-slate-400 mb-2 block">Campaña</label>
                            <select id="salesCampaignSelect" name="campaign_id" class="w-full">
                                <option value="">Selecciona una campaña...</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="text-sm text-slate-400 mb-2 block">Archivo CSV</label>
                            <input type="file" id="salesReportFile" name="report_file" accept=".csv" class="w-full">
                            <p class="text-xs text-slate-500 mt-1">Sube directamente el reporte de Vicidial
                                <strong>AST_team_performance_detail</strong> sin modificar.</p>
                        </div>
                        <div>
                            <button type="submit" class="btn-primary w-full">
                                <i class="fas fa-upload mr-2"></i>
                                Subir Reporte
                            </button>
                        </div>
                    </form>
                    <div id="salesReportMessage" class="mt-3 hidden"></div>
                </div>
            </div>

            <div class="glass-card mb-6">
                <div class="p-5">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-4">
                        <div>
                            <h2 class="text-lg font-semibold text-white">
                                <i class="fas fa-users-cog text-cyan-400 mr-2"></i>
                                Carga de Staffing (AST Erlang)
                            </h2>
                            <p class="text-sm text-slate-400">Sube el reporte AST Erlang por hora para calcular
                                analíticas por campaña en WFM.</p>
                            <span
                                onclick="document.getElementById('staffingInfo').classList.toggle('hidden')"
                                class="text-xs text-blue-400 mt-2 hover:underline cursor-pointer inline-block"><i class="fas fa-info-circle"></i>
                                Ver columnas del reporte AST</span>
                        </div>
                        <a href="../assets/templates/campaign_staffing_ast_template.csv" download class="btn-secondary">
                            <i class="fas fa-download mr-2"></i>
                            Descargar Plantilla
                        </a>
                    </div>

                    <div id="staffingInfo"
                        class="hidden bg-slate-800/50 rounded-lg p-4 text-sm text-slate-300 border border-slate-700 mb-6">
                        <strong class="text-white block mb-2"><i class="fas fa-list-alt text-cyan-400 mr-1"></i> Columnas
                            esperadas del AST Erlang:</strong>
                        <ul class="list-disc pl-5 mb-3 space-y-1 text-xs text-slate-400">
                            <li><span class="text-cyan-300">CALLING HOUR</span>: Hora del bloque (ej.
                                <code>2026-02-01 09am</code>).</li>
                            <li><span class="text-cyan-300">CALLS</span>: Volumen de llamadas del bloque horario.</li>
                            <li><span class="text-cyan-300">TOTAL TIME</span>: Tiempo total de conversación del bloque.</li>
                            <li><span class="text-cyan-300">AVG TIME</span>: Tiempo promedio por llamada.</li>
                            <li><span class="text-cyan-300">DROPPED HRS</span>: Tiempo acumulado de llamadas perdidas.</li>
                            <li><span class="text-cyan-300">BLOCKING</span>: Tasa de bloqueo/drop para calcular abandono.</li>
                            <li><span class="text-cyan-300">REC AGENTS / EST AGENTS</span>: Referencia de capacidad por hora.</li>
                        </ul>
                        <strong class="text-white block mb-2"><i class="fas fa-chart-line text-blue-400 mr-1"></i>
                            Analíticas en WFM:</strong>
                        <p class="text-xs text-slate-400">El reporte se guarda por campaña y alimenta los tableros WFM
                            para ver ofrecidas, atendidas estimadas, abandonadas estimadas, AHT y tasas por periodo.</p>
                    </div>
                    <form id="staffingForm" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                        <div>
                            <label class="text-sm text-slate-400 mb-2 block">Campaña</label>
                            <select id="staffingCampaignSelect" name="campaign_id" class="w-full" required>
                                <option value="">Selecciona una campaña...</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="text-sm text-slate-400 mb-2 block">Archivo CSV</label>
                            <input type="file" id="staffingFile" name="report_file" accept=".csv" class="w-full">
                            <p class="text-xs text-slate-500 mt-1">Usa la plantilla o sube el CSV AST original
                                (separado por <strong>,</strong>).</p>
                        </div>
                        <div>
                            <button type="submit" class="btn-primary w-full">
                                <i class="fas fa-upload mr-2"></i>
                                Subir Pronóstico
                            </button>
                        </div>
                    </form>
                    <div id="staffingMessage" class="mt-3 hidden"></div>
                </div>
            </div>

            <div class="campaign-grid" id="campaignGrid">
                <div class="text-center py-8 text-slate-400">
                    <i class="fas fa-spinner fa-spin text-4xl mb-3"></i>
                    <p>Cargando campañas...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Crear/Editar Campaña -->
    <div class="modal-overlay" id="campaignModal" onclick="closeModalOnOverlay(event)">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-semibold text-white" id="modalTitle">
                    <i class="fas fa-bullhorn text-blue-400 mr-2"></i>
                    Nueva Campaña
                </h3>
                <button onclick="closeModal()" class="text-slate-400 hover:text-white">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <div id="campaignFormContainer">
                <input type="hidden" id="campaignId">

                <div class="form-group mb-4">
                    <label for="campaignName">Nombre de la Campaña *</label>
                    <input type="text" id="campaignName" required placeholder="Ej: Soporte Técnico" class="w-full">
                </div>

                <div class="form-group mb-4">
                    <label for="campaignCode">Código *</label>
                    <input type="text" id="campaignCode" required placeholder="Ej: TECH-SUPPORT" maxlength="50"
                        style="text-transform: uppercase;" class="w-full">
                    <p class="text-xs text-slate-400 mt-1">Código único sin espacios</p>
                </div>

                <div class="form-group mb-4">
                    <label for="campaignDescription">Descripción</label>
                    <textarea id="campaignDescription" rows="3" placeholder="Descripción opcional"
                        class="w-full"></textarea>
                </div>

                <div class="form-group mb-4">
                    <label for="campaignColor">Color de Identificación</label>
                    <div class="flex gap-3 items-center">
                        <input type="color" id="campaignColor" value="#6366f1" class="h-10 w-20">
                        <span class="text-sm text-slate-400">Selecciona un color para identificar la campaña</span>
                    </div>
                </div>

                <div class="form-group mb-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="campaignActive" checked class="form-checkbox">
                        <span class="text-sm text-slate-300">Campaña activa</span>
                    </label>
                </div>

                <div id="formMessage" class="mb-4 hidden"></div>

                <div class="flex gap-3">
                    <button type="button" onclick="saveCampaign(event)" class="btn-primary flex-1">
                        <i class="fas fa-save"></i>
                        Guardar Campaña
                    </button>
                    <button type="button" onclick="closeModal()" class="btn-secondary flex-1">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Supervisores -->
    <div class="modal-overlay" id="supervisorsModal" onclick="closeModalOnOverlay(event)">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-semibold text-white" id="supervisorsModalTitle">
                    <i class="fas fa-users-cog text-blue-400 mr-2"></i>
                    Asignar Supervisores
                </h3>
                <button onclick="closeSupervisorsModal()" class="text-slate-400 hover:text-white">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <div class="mb-4">
                <label class="text-sm text-slate-400 mb-2 block">Supervisores Disponibles</label>
                <select id="supervisorSelect" class="w-full mb-2">
                    <option value="">Seleccionar supervisor...</option>
                </select>
                <button onclick="assignSupervisor()" class="btn-primary w-full">
                    <i class="fas fa-plus"></i>
                    Asignar Supervisor
                </button>
            </div>

            <div class="mb-4">
                <label class="text-sm text-slate-400 mb-2 block">Supervisores Asignados</label>
                <div id="assignedSupervisors" class="space-y-2">
                    <div class="text-center text-slate-500 py-4">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                </div>
            </div>

            <div id="supervisorsMessage" class="mb-4 hidden"></div>

            <button onclick="closeSupervisorsModal()" class="btn-secondary w-full">
                Cerrar
            </button>
        </div>
    </div>

    <!-- Employee Assignment Modal -->
    <div class="modal-overlay" id="employeeAssignmentModal" onclick="closeModalOnOverlay(event)">
        <div class="modal-content" style="max-width: 700px;">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-semibold text-white">
                    <i class="fas fa-users text-blue-400 mr-2"></i>
                    Empleados en: <span id="assignmentCampaignName"></span>
                </h3>
                <button onclick="closeEmployeeModal()" class="text-slate-400 hover:text-white">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <div class="mb-4 bg-blue-500/10 border border-blue-500/20 rounded-lg p-4">
                <p class="text-sm text-slate-300">
                    <i class="fas fa-info-circle text-blue-400 mr-2"></i>
                    Para asignar empleados a esta campaña, usa el botón <strong>"Asignar"</strong> (<i
                        class="fas fa-user-tag"></i>) en la lista de empleados.
                </p>
            </div>

            <div class="mb-4">
                <label class="text-sm text-slate-400 mb-2 block">Empleados Asignados</label>
                <div id="employeeList" class="space-y-2 max-h-96 overflow-y-auto">
                    <div class="text-center text-slate-500 py-4">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                </div>
            </div>

            <div id="employeeMessage" class="mb-4 hidden"></div>

            <div class="flex gap-3">
                <a href="employees.php" class="btn-primary flex-1 text-center">
                    <i class="fas fa-user-plus mr-2"></i>
                    Ir a Empleados
                </a>
                <button onclick="closeEmployeeModal()" class="btn-secondary flex-1">
                    Cerrar
                </button>
            </div>
        </div>
    </div>

    <?php include '../footer.php'; ?>

    <script>
        let campaigns = [];
        let allSupervisors = [];
        let currentCampaignId = null;

        // Cargar datos al iniciar
        document.addEventListener('DOMContentLoaded', function () {
            loadCampaigns();
            loadSupervisors();
            const salesForm = document.getElementById('salesReportForm');
            if (salesForm) {
                salesForm.addEventListener('submit', handleSalesReportUpload);
            }
            const staffingForm = document.getElementById('staffingForm');
            if (staffingForm) {
                staffingForm.addEventListener('submit', handleStaffingUpload);
            }
        });

        async function loadCampaigns() {
            try {
                const response = await fetch('../api/campaigns.php?action=list');
                const data = await response.json();

                if (data.success) {
                    campaigns = data.campaigns;
                    renderCampaigns();
                    updateStats();
                    populateSalesCampaigns();
                    populateStaffingCampaigns();
                } else {
                    showError('Error al cargar campañas: ' + data.error);
                }
            } catch (error) {
                showError('Error al cargar campañas: ' + error.message);
            }
        }

        async function loadSupervisors() {
            try {
                const response = await fetch('../api/campaigns.php?action=supervisors');
                const data = await response.json();

                if (data.success) {
                    allSupervisors = data.supervisors;
                }
            } catch (error) {
                console.error('Error al cargar supervisores:', error);
            }
        }

        function renderCampaigns() {
            const grid = document.getElementById('campaignGrid');

            if (campaigns.length === 0) {
                grid.innerHTML = `
                    <div class="col-span-full text-center py-12 text-slate-400">
                        <i class="fas fa-bullhorn text-6xl mb-4 opacity-20"></i>
                        <p class="text-lg">No hay campañas creadas</p>
                        <p class="text-sm mt-2">Haz clic en "Nueva Campaña" para comenzar</p>
                    </div>
                `;
                return;
            }

            grid.innerHTML = campaigns.map(campaign => `
                <div class="campaign-card ${campaign.is_active == 0 ? 'campaign-inactive' : ''}" 
                     style="--campaign-color: ${campaign.color};">
                    <div class="flex justify-between items-start mb-3">
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold text-white mb-1">${escapeHtml(campaign.name)}</h3>
                            <p class="text-sm text-slate-400">
                                <i class="fas fa-tag"></i> ${escapeHtml(campaign.code)}
                                ${campaign.is_active == 0 ? '<span class="ml-2 text-orange-400"><i class="fas fa-pause-circle"></i> Inactiva</span>' : ''}
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <button onclick="editCampaign(${campaign.id})" class="text-blue-400 hover:text-blue-300" title="Editar">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="confirmDelete(${campaign.id})" class="text-red-400 hover:text-red-300" title="Eliminar">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    
                    ${campaign.description ? `<p class="text-sm text-slate-400 mb-3">${escapeHtml(campaign.description)}</p>` : ''}
                    
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <div class="bg-slate-700/30 rounded-lg p-2 text-center">
                            <div class="text-xl font-bold text-green-400">${campaign.agent_count}</div>
                            <div class="text-xs text-slate-400">Agentes</div>
                        </div>
                        <div class="bg-slate-700/30 rounded-lg p-2 text-center">
                            <div class="text-xl font-bold text-purple-400">${campaign.supervisor_count}</div>
                            <div class="text-xs text-slate-400">Supervisores</div>
                        </div>
                    </div>
                    
                    <div class="flex gap-2">
                        <button onclick="openEmployeeAssignment(${campaign.id})" class="btn-secondary flex-1">
                            <i class="fas fa-users"></i>
                            Empleados
                        </button>
                        <button onclick="manageSupervisors(${campaign.id})" class="btn-secondary flex-1">
                            <i class="fas fa-users-cog"></i>
                            Supervisores
                        </button>
                    </div>
                </div>
            `).join('');
        }

        function updateStats() {
            const active = campaigns.filter(c => c.is_active == 1).length;
            const totalSupervisors = campaigns.reduce((sum, c) => sum + parseInt(c.supervisor_count), 0);
            const totalAgents = campaigns.reduce((sum, c) => sum + parseInt(c.agent_count), 0);

            document.getElementById('totalCampaigns').textContent = campaigns.length;
            document.getElementById('activeCampaigns').textContent = active;
            document.getElementById('totalSupervisors').textContent = totalSupervisors;
            document.getElementById('totalAgents').textContent = totalAgents;
        }

        function populateSalesCampaigns() {
            const select = document.getElementById('salesCampaignSelect');
            if (!select) return;
            select.innerHTML = '<option value="">Selecciona una campaña...</option>' +
                campaigns.map(c => `<option value="${c.id}">${escapeHtml(c.name)} (${escapeHtml(c.code)})</option>`).join('');
        }

        function populateStaffingCampaigns() {
            const select = document.getElementById('staffingCampaignSelect');
            if (!select) return;
            select.innerHTML = '<option value="">Selecciona una campaña...</option>' +
                campaigns.map(c => `<option value="${c.id}">${escapeHtml(c.name)} (${escapeHtml(c.code)})</option>`).join('');
        }

        async function handleSalesReportUpload(event) {
            event.preventDefault();
            event.stopPropagation();

            const campaignId = document.getElementById('salesCampaignSelect').value;
            const fileInput = document.getElementById('salesReportFile');
            const file = fileInput.files[0];

            if (!campaignId) {
                showSalesReportMessage('Selecciona una campaña.', 'error');
                return;
            }
            if (!file) {
                showSalesReportMessage('Selecciona un archivo CSV.', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('campaign_id', campaignId);
            formData.append('report_file', file);

            showSalesReportMessage('Subiendo reporte...', 'info');

            try {
                const response = await fetch('../api/campaign_sales.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.success) {
                    showSalesReportMessage(
                        `Carga completada. Insertados: ${data.inserted}, Actualizados: ${data.updated}, Omitidos: ${data.skipped}`,
                        'success'
                    );
                    fileInput.value = '';
                } else {
                    showSalesReportMessage(data.error || 'Error al procesar el archivo.', 'error');
                }
            } catch (error) {
                showSalesReportMessage('Error al subir el archivo: ' + error.message, 'error');
            }
        }

        async function handleStaffingUpload(event) {
            event.preventDefault();
            event.stopPropagation();

            const campaignId = document.getElementById('staffingCampaignSelect').value;
            const fileInput = document.getElementById('staffingFile');
            const file = fileInput.files[0];

            if (!campaignId) {
                showStaffingMessage('Selecciona una campaña.', 'error');
                return;
            }
            if (!file) {
                showStaffingMessage('Selecciona un archivo CSV.', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('campaign_id', campaignId);
            formData.append('report_file', file);

            showStaffingMessage('Subiendo pronóstico...', 'info');

            try {
                const response = await fetch('../api/campaign_staffing.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.success) {
                    const forecastInfo = (typeof data.forecast_inserted !== 'undefined' || typeof data.forecast_updated !== 'undefined')
                        ? ` | Staffing WFM: Insertados ${data.forecast_inserted ?? 0}, Actualizados ${data.forecast_updated ?? 0}`
                        : '';
                    showStaffingMessage(
                        `Carga completada. Inbound: Insertados ${data.inserted}, Actualizados ${data.updated}, Omitidos ${data.skipped}${forecastInfo}`,
                        'success'
                    );
                    fileInput.value = '';
                } else {
                    showStaffingMessage(data.error || 'Error al procesar el archivo.', 'error');
                }
            } catch (error) {
                showStaffingMessage('Error al subir el archivo: ' + error.message, 'error');
            }
        }

        function showStaffingMessage(message, type) {
            const div = document.getElementById('staffingMessage');
            if (!div) return;
            const icon = type === 'success' ? 'check-circle' : (type === 'info' ? 'spinner fa-spin' : 'exclamation-circle');
            const bannerType = type === 'info' ? '' : type;
            div.className = `status-banner ${bannerType} mt-3`;
            div.innerHTML = `<i class="fas fa-${icon} mr-2"></i>${message}`;
            div.classList.remove('hidden');

            if (type !== 'info') {
                setTimeout(() => {
                    div.classList.add('hidden');
                }, 5000);
            }
        }

        function showSalesReportMessage(message, type) {
            const div = document.getElementById('salesReportMessage');
            if (!div) return;
            const icon = type === 'success' ? 'check-circle' : (type === 'info' ? 'spinner fa-spin' : 'exclamation-circle');
            const bannerType = type === 'info' ? '' : type;
            div.className = `status-banner ${bannerType} mt-3`;
            div.innerHTML = `<i class="fas fa-${icon} mr-2"></i>${message}`;
            div.classList.remove('hidden');

            if (type !== 'info') {
                setTimeout(() => {
                    div.classList.add('hidden');
                }, 5000);
            }
        }

        function openCreateModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-bullhorn text-blue-400 mr-2"></i>Nueva Campaña';
            document.getElementById('campaignId').value = '';
            document.getElementById('campaignName').value = '';
            document.getElementById('campaignCode').value = '';
            document.getElementById('campaignDescription').value = '';
            document.getElementById('campaignColor').value = '#6366f1';
            document.getElementById('campaignActive').checked = true;
            document.getElementById('formMessage').classList.add('hidden');
            document.getElementById('campaignModal').classList.add('active');
        }

        async function editCampaign(id) {
            try {
                const response = await fetch(`../api/campaigns.php?action=get&id=${id}`);
                const data = await response.json();

                if (data.success) {
                    const campaign = data.campaign;
                    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit text-blue-400 mr-2"></i>Editar Campaña';
                    document.getElementById('campaignId').value = campaign.id;
                    document.getElementById('campaignName').value = campaign.name;
                    document.getElementById('campaignCode').value = campaign.code;
                    document.getElementById('campaignDescription').value = campaign.description || '';
                    document.getElementById('campaignColor').value = campaign.color;
                    document.getElementById('campaignActive').checked = campaign.is_active == 1;
                    document.getElementById('formMessage').classList.add('hidden');
                    document.getElementById('campaignModal').classList.add('active');
                } else {
                    showError('Error al cargar campaña: ' + data.error);
                }
            } catch (error) {
                showError('Error al cargar campaña: ' + error.message);
            }
        }

        async function saveCampaign(event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }

            // Validación básica
            const name = document.getElementById('campaignName').value.trim();
            const code = document.getElementById('campaignCode').value.trim().toUpperCase();

            if (!name || !code) {
                const messageDiv = document.getElementById('formMessage');
                messageDiv.className = 'status-banner error mb-4';
                messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>Nombre y código son obligatorios';
                messageDiv.classList.remove('hidden');
                return false;
            }

            const id = document.getElementById('campaignId').value;
            const formData = {
                name: name,
                code: code,
                description: document.getElementById('campaignDescription').value.trim(),
                color: document.getElementById('campaignColor').value,
                is_active: document.getElementById('campaignActive').checked ? 1 : 0
            };

            if (id) {
                formData.id = parseInt(id);
            }

            const messageDiv = document.getElementById('formMessage');
            messageDiv.className = 'status-banner mb-4';
            messageDiv.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Guardando...';
            messageDiv.classList.remove('hidden');

            try {
                const url = '../api/campaigns.php';
                const method = 'POST';

                if (id) {
                    formData.action = 'update';
                } else {
                    formData.action = 'create';
                }

                console.log('Saving campaign:', { url, method, formData });

                const response = await fetch(url, {
                    method: method,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(formData)
                });

                console.log('Response status:', response.status);
                const data = await response.json();
                console.log('Response data:', data);

                if (data.success) {
                    messageDiv.className = 'status-banner success mb-4';
                    messageDiv.innerHTML = '<i class="fas fa-check-circle mr-2"></i>' + data.message;

                    setTimeout(() => {
                        closeModal();
                        loadCampaigns();
                    }, 1000);
                } else {
                    messageDiv.className = 'status-banner error mb-4';
                    messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>' + data.error;
                }
            } catch (error) {
                messageDiv.className = 'status-banner error mb-4';
                messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>Error: ' + error.message;
            }

            return false; // Prevenir submit del form
        }

        async function confirmDelete(id) {
            const campaign = campaigns.find(c => c.id == id);
            if (!campaign) return;

            if (!confirm(`¿Estás seguro de que deseas eliminar la campaña "${campaign.name}"?\n\nEsta acción no se puede deshacer.`)) {
                return;
            }

            try {
                const response = await fetch(`../api/campaigns.php?id=${id}`, {
                    method: 'DELETE'
                });

                const data = await response.json();

                if (data.success) {
                    showSuccess(data.message);
                    loadCampaigns();
                } else {
                    showError(data.error);
                }
            } catch (error) {
                showError('Error al eliminar: ' + error.message);
            }
        }

        async function manageSupervisors(campaignId) {
            currentCampaignId = campaignId;
            const campaign = campaigns.find(c => c.id == campaignId);

            if (campaign) {
                document.getElementById('supervisorsModalTitle').innerHTML =
                    `<i class="fas fa-users-cog text-blue-400 mr-2"></i>Supervisores - ${escapeHtml(campaign.name)}`;
            }

            // Cargar supervisores asignados
            await loadAssignedSupervisors(campaignId);

            // Poblar select de supervisores disponibles
            const select = document.getElementById('supervisorSelect');
            select.innerHTML = '<option value="">Seleccionar supervisor...</option>' +
                allSupervisors.map(s => `<option value="${s.id}">${escapeHtml(s.full_name)} (${escapeHtml(s.role)})</option>`).join('');

            document.getElementById('supervisorsMessage').classList.add('hidden');
            document.getElementById('supervisorsModal').classList.add('active');
        }

        async function loadAssignedSupervisors(campaignId) {
            try {
                const response = await fetch(`../api/campaigns.php?action=get&id=${campaignId}`);
                const data = await response.json();

                if (data.success) {
                    const container = document.getElementById('assignedSupervisors');
                    const supervisors = data.campaign.supervisors || [];

                    if (supervisors.length === 0) {
                        container.innerHTML = `
                            <div class="text-center text-slate-500 py-4">
                                <i class="fas fa-user-slash"></i>
                                <p class="text-sm mt-2">No hay supervisores asignados</p>
                            </div>
                        `;
                    } else {
                        container.innerHTML = supervisors.map(s => `
                            <div class="flex items-center justify-between p-3 bg-slate-700/30 rounded-lg">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-user-tie text-purple-400"></i>
                                    <span class="text-white">${escapeHtml(s.full_name)}</span>
                                    <span class="text-sm text-slate-400">(${escapeHtml(s.username)})</span>
                                </div>
                                <button onclick="unassignSupervisor(${s.supervisor_id})" class="text-red-400 hover:text-red-300">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        `).join('');
                    }
                }
            } catch (error) {
                console.error('Error al cargar supervisores asignados:', error);
            }
        }

        async function assignSupervisor() {
            const supervisorId = document.getElementById('supervisorSelect').value;
            if (!supervisorId) {
                showSupervisorsMessage('Selecciona un supervisor', 'error');
                return;
            }

            try {
                const response = await fetch('../api/campaigns.php?action=assign_supervisor', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        supervisor_id: parseInt(supervisorId),
                        campaign_id: currentCampaignId
                    })
                });

                const data = await response.json();

                if (data.success) {
                    showSupervisorsMessage(data.message, 'success');
                    loadAssignedSupervisors(currentCampaignId);
                    loadCampaigns();
                    document.getElementById('supervisorSelect').value = '';
                } else {
                    showSupervisorsMessage(data.error, 'error');
                }
            } catch (error) {
                showSupervisorsMessage('Error: ' + error.message, 'error');
            }
        }

        async function unassignSupervisor(supervisorId) {
            if (!confirm('¿Desasignar este supervisor de la campaña?')) {
                return;
            }

            try {
                const response = await fetch('../api/campaigns.php?action=unassign_supervisor', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        supervisor_id: supervisorId,
                        campaign_id: currentCampaignId
                    })
                });

                const data = await response.json();

                if (data.success) {
                    showSupervisorsMessage(data.message, 'success');
                    loadAssignedSupervisors(currentCampaignId);
                    loadCampaigns();
                } else {
                    showSupervisorsMessage(data.error, 'error');
                }
            } catch (error) {
                showSupervisorsMessage('Error: ' + error.message, 'error');
            }
        }

        function closeModal() {
            document.getElementById('campaignModal').classList.remove('active');
        }

        function closeSupervisorsModal() {
            document.getElementById('supervisorsModal').classList.remove('active');
            currentCampaignId = null;
        }

        function closeModalOnOverlay(event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.classList.remove('active');
            }
        }

        function showSupervisorsMessage(message, type) {
            const div = document.getElementById('supervisorsMessage');
            div.className = `status-banner ${type} mb-4`;
            div.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} mr-2"></i>${message}`;
            div.classList.remove('hidden');

            setTimeout(() => {
                div.classList.add('hidden');
            }, 3000);
        }

        // Employee Assignment Functions
        function openEmployeeAssignment(campaignId) {
            currentCampaignId = campaignId;
            loadCampaignEmployees(campaignId);
            document.getElementById('employeeAssignmentModal').classList.add('active');
        }

        async function loadCampaignEmployees(campaignId) {
            try {
                const response = await fetch(`../api/campaigns.php?action=get_employees&campaign_id=${campaignId}`);
                const data = await response.json();

                if (data.success) {
                    renderEmployeeList(data.employees, data.campaign);
                } else {
                    showEmployeeMessage(data.error, 'error');
                }
            } catch (error) {
                showEmployeeMessage('Error al cargar empleados', 'error');
            }
        }

        function renderEmployeeList(employees, campaign) {
            const container = document.getElementById('employeeList');
            const campaignName = document.getElementById('assignmentCampaignName');

            campaignName.textContent = campaign.name;
            campaignName.style.color = campaign.color;

            if (employees.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-8 text-slate-400">
                        <i class="fas fa-users-slash text-4xl mb-3"></i>
                        <p>No hay empleados asignados a esta campaña</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = employees.map(emp => `
                <div class="flex items-center justify-between p-3 bg-slate-700/50 rounded-lg hover:bg-slate-700 transition">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-semibold">
                            ${emp.full_name.charAt(0)}
                        </div>
                        <div>
                            <div class="font-medium text-white">${escapeHtml(emp.full_name)}</div>
                            <div class="text-sm text-slate-400">
                                <i class="fas fa-briefcase mr-1"></i>${escapeHtml(emp.position || 'Sin posición')}
                            </div>
                        </div>
                    </div>
                    <button onclick="unassignEmployee(${emp.id})" class="text-red-400 hover:text-red-300 px-3 py-1.5 rounded hover:bg-red-500/10 transition">
                        <i class="fas fa-times mr-1"></i> Desasignar
                    </button>
                </div>
            `).join('');
        }

        async function unassignEmployee(employeeId) {
            if (!confirm('¿Desasignar este empleado de la campaña?')) return;

            try {
                const formData = new FormData();
                formData.append('employee_id', employeeId);
                formData.append('campaign_id', '');

                const response = await fetch('../api/employees.php?action=quick_assign', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    showEmployeeMessage('Empleado desasignado correctamente', 'success');
                    loadCampaignEmployees(currentCampaignId);
                    loadCampaigns(); // Refresh stats
                } else {
                    showEmployeeMessage(data.error, 'error');
                }
            } catch (error) {
                showEmployeeMessage('Error al desasignar empleado', 'error');
            }
        }

        function showEmployeeMessage(message, type) {
            const div = document.getElementById('employeeMessage');
            div.className = `status-banner ${type} mb-4`;
            div.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} mr-2"></i>${message}`;
            div.classList.remove('hidden');

            setTimeout(() => {
                div.classList.add('hidden');
            }, 3000);
        }

        function closeEmployeeModal() {
            document.getElementById('employeeAssignmentModal').classList.remove('active');
            currentCampaignId = null;
        }

        function showError(message) {
            alert('Error: ' + message);
        }

        function showSuccess(message) {
            alert(message);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>

</html>
