<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

ensurePermission('wfm_planning');

include __DIR__ . '/../header.php';
?>

<style>
    .calculator-card {
        background: linear-gradient(135deg, rgba(15, 23, 42, 0.9) 0%, rgba(30, 41, 59, 0.7) 100%);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(100, 200, 255, 0.2);
        box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
    }

    .input-group {
        position: relative;
        margin-bottom: 1.5rem;
    }

    .input-label {
        display: block;
        font-size: 0.875rem;
        font-weight: 600;
        color: #94a3b8;
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .input-field {
        width: 100%;
        padding: 0.75rem 1rem;
        background: rgba(15, 23, 42, 0.8);
        border: 1px solid rgba(100, 200, 255, 0.3);
        border-radius: 0.5rem;
        color: #f1f5f9;
        font-size: 1rem;
        transition: all 0.3s ease;
    }

    .input-field:focus {
        outline: none;
        border-color: #06b6d4;
        box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.1);
        background: rgba(15, 23, 42, 0.95);
    }

    .input-addon {
        position: absolute;
        right: 1rem;
        top: 2.5rem;
        color: #94a3b8;
        font-size: 0.875rem;
        pointer-events: none;
    }

    .result-card {
        background: linear-gradient(135deg, rgba(6, 182, 212, 0.1) 0%, rgba(59, 130, 246, 0.1) 100%);
        border: 1px solid rgba(6, 182, 212, 0.3);
        border-radius: 0.75rem;
        padding: 1.5rem;
        margin-top: 2rem;
    }

    .result-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem;
        margin-bottom: 0.75rem;
        background: rgba(15, 23, 42, 0.5);
        border-radius: 0.5rem;
        transition: transform 0.2s ease;
    }

    .result-item:hover {
        transform: translateX(5px);
    }

    .result-label {
        font-weight: 600;
        color: #94a3b8;
        font-size: 0.95rem;
    }

    .result-value {
        font-size: 1.5rem;
        font-weight: 700;
        background: linear-gradient(135deg, #06b6d4 0%, #3b82f6 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .btn-calculate {
        width: 100%;
        padding: 1rem;
        border-radius: 0.75rem;
        background: linear-gradient(135deg, #06b6d4 0%, #3b82f6 100%);
        color: white;
        font-weight: 700;
        font-size: 1.1rem;
        border: none;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(6, 182, 212, 0.4);
    }

    .btn-calculate:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(6, 182, 212, 0.6);
    }

    .btn-calculate:active {
        transform: translateY(0);
    }

    .btn-reset {
        width: 100%;
        padding: 0.875rem;
        border-radius: 0.75rem;
        background: rgba(71, 85, 105, 0.5);
        color: #cbd5e1;
        font-weight: 600;
        border: 1px solid rgba(100, 116, 139, 0.5);
        cursor: pointer;
        transition: all 0.3s ease;
        margin-top: 0.75rem;
    }

    .btn-reset:hover {
        background: rgba(71, 85, 105, 0.8);
        border-color: rgba(100, 116, 139, 0.8);
    }

    .info-badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        background: rgba(59, 130, 246, 0.2);
        border: 1px solid rgba(59, 130, 246, 0.4);
        border-radius: 9999px;
        font-size: 0.75rem;
        color: #93c5fd;
        margin-left: 0.5rem;
    }

    .metric-badge {
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-weight: 600;
        text-align: center;
    }

    .metric-excellent {
        background: rgba(34, 197, 94, 0.2);
        border: 1px solid rgba(34, 197, 94, 0.4);
        color: #86efac;
    }

    .metric-good {
        background: rgba(59, 130, 246, 0.2);
        border: 1px solid rgba(59, 130, 246, 0.4);
        color: #93c5fd;
    }

    .metric-warning {
        background: rgba(251, 146, 60, 0.2);
        border: 1px solid rgba(251, 146, 60, 0.4);
        color: #fdba74;
    }

    .metric-poor {
        background: rgba(239, 68, 68, 0.2);
        border: 1px solid rgba(239, 68, 68, 0.4);
        color: #fca5a5;
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .animate-slide-in {
        animation: slideIn 0.5s ease-out;
    }
</style>

<div class="container mx-auto px-4 py-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
        <div>
            <h1 class="text-3xl font-bold text-white flex items-center gap-3">
                <i class="fas fa-calculator text-cyan-400"></i> 
                Calculadora de Nivel de Servicio
            </h1>
            <p class="text-slate-400 mt-2">Calcula agentes requeridos usando la fórmula de Erlang C con parámetros personalizables</p>
        </div>
        <div class="flex gap-2">
            <a href="wfm_planning.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors text-sm">
                <i class="fas fa-calendar-check mr-2"></i>WFM Planning
            </a>
            <button onclick="toggleHelp()" class="px-4 py-2 bg-blue-700 hover:bg-blue-600 text-white rounded-lg transition-colors text-sm">
                <i class="fas fa-question-circle mr-2"></i>Ayuda
            </button>
        </div>
    </div>

    <!-- Help Section -->
    <div id="helpSection" class="hidden mb-6 p-6 calculator-card rounded-xl">
        <div class="flex items-start gap-4">
            <div class="text-3xl text-blue-400">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="flex-1">
                <h3 class="text-xl font-bold text-white mb-3">Guía de Uso</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-slate-300">
                    <div>
                        <h4 class="font-semibold text-cyan-400 mb-2">Parámetros de Entrada:</h4>
                        <ul class="space-y-1 ml-4 list-disc text-slate-400">
                            <li><strong>Service Level Goal:</strong> Objetivo de nivel de servicio en % y segundos (ej: 80% en 20 seg)</li>
                            <li><strong>Interval Length:</strong> Duración del intervalo en minutos (15, 30, 60)</li>
                            <li><strong>Calls:</strong> Número de llamadas esperadas en el intervalo</li>
                            <li><strong>Average Handling Time:</strong> Tiempo promedio de manejo en segundos</li>
                            <li><strong>Occupancy Target:</strong> Objetivo de ocupación de agentes (70-90%)</li>
                            <li><strong>Shrinkage:</strong> Tiempo no productivo: breaks, meetings, training (20-35%)</li>
                        </ul>
                    </div>
                    <div>
                        <h4 class="font-semibold text-cyan-400 mb-2">Resultados:</h4>
                        <ul class="space-y-1 ml-4 list-disc text-slate-400">
                            <li><strong>Required Agents:</strong> Agentes mínimos para cumplir el SL objetivo</li>
                            <li><strong>Required Staff:</strong> Staff total incluyendo shrinkage</li>
                            <li><strong>Service Level:</strong> SL proyectado con los agentes calculados</li>
                            <li><strong>Occupancy:</strong> % de tiempo productivo de los agentes</li>
                            <li><strong>Intensity (Erlangs):</strong> Carga de trabajo en Erlangs</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Input Section -->
        <div class="calculator-card rounded-xl p-6">
            <h2 class="text-xl font-bold text-white mb-2 flex items-center gap-2">
                <i class="fas fa-sliders-h text-cyan-400"></i>
                Parámetros de Entrada
            </h2>
            <p class="text-sm text-slate-400 mb-6">Ingresa los valores para calcular el dimensionamiento</p>

            <form id="calculatorForm">
                <!-- Service Level Goal -->
                <div class="border-b border-slate-700/50 pb-4 mb-4">
                    <h3 class="text-sm font-semibold text-cyan-300 mb-3 flex items-center gap-2">
                        <i class="fas fa-bullseye text-xs"></i>
                        Service Level Goal
                    </h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="input-group">
                            <label class="input-label">Porcentaje</label>
                            <input type="number" id="targetSl" name="targetSl" class="input-field" 
                                   value="80" min="1" max="100" step="1" required>
                            <span class="input-addon">%</span>
                        </div>
                        <div class="input-group">
                            <label class="input-label">Segundos</label>
                            <input type="number" id="targetAns" name="targetAns" class="input-field" 
                                   value="20" min="1" max="300" step="1" required>
                            <span class="input-addon">seg</span>
                        </div>
                    </div>
                </div>

                <!-- Interval Length -->
                <div class="input-group">
                    <label class="input-label">
                        Interval Length
                        <span class="info-badge">Duración del intervalo</span>
                    </label>
                    <select id="intervalMinutes" name="intervalMinutes" class="input-field">
                        <option value="15" selected>15 minutos</option>
                        <option value="30">30 minutos</option>
                        <option value="60">60 minutos</option>
                    </select>
                </div>

                <!-- Calls -->
                <div class="input-group">
                    <label class="input-label">
                        Calls
                        <span class="info-badge">Llamadas esperadas</span>
                    </label>
                    <input type="number" id="calls" name="calls" class="input-field" 
                           value="100" min="0" step="1" required>
                </div>

                <!-- Average Handling Time -->
                <div class="input-group">
                    <label class="input-label">
                        Average Handling Time
                        <span class="info-badge">AHT</span>
                    </label>
                    <input type="number" id="ahtSeconds" name="ahtSeconds" class="input-field" 
                           value="180" min="1" step="1" required>
                    <span class="input-addon">seg</span>
                </div>

                <!-- Advanced Settings Toggle -->
                <div class="mb-4">
                    <button type="button" onclick="toggleAdvanced()" 
                        class="text-cyan-400 hover:text-cyan-300 text-sm font-semibold flex items-center gap-2 transition-colors">
                        <i id="advancedIcon" class="fas fa-chevron-right"></i>
                        Configuración Avanzada
                    </button>
                </div>

                <!-- Advanced Settings -->
                <div id="advancedSettings" class="hidden space-y-4">
                    <div class="p-4 bg-slate-900/50 rounded-lg border border-slate-700/50">
                        <!-- Occupancy Target -->
                        <div class="input-group">
                            <label class="input-label">
                                Occupancy Target
                                <span class="info-badge">Ocupación objetivo</span>
                            </label>
                            <input type="number" id="occupancyTarget" name="occupancyTarget" class="input-field" 
                                   value="85" min="50" max="95" step="1" required>
                            <span class="input-addon">%</span>
                        </div>

                        <!-- Shrinkage -->
                        <div class="input-group mb-0">
                            <label class="input-label">
                                Shrinkage
                                <span class="info-badge">Tiempo no productivo</span>
                            </label>
                            <input type="number" id="shrinkage" name="shrinkage" class="input-field" 
                                   value="30" min="0" max="50" step="1" required>
                            <span class="input-addon">%</span>
                        </div>
                    </div>
                </div>

                <!-- Buttons -->
                <div class="mt-6">
                    <button type="submit" class="btn-calculate">
                        <i class="fas fa-calculator mr-2"></i>
                        Calcular
                    </button>
                    <button type="button" onclick="resetForm()" class="btn-reset">
                        <i class="fas fa-redo mr-2"></i>
                        Resetear
                    </button>
                </div>
            </form>
        </div>

        <!-- Results Section -->
        <div class="calculator-card rounded-xl p-6">
            <h2 class="text-xl font-bold text-white mb-2 flex items-center gap-2">
                <i class="fas fa-chart-line text-cyan-400"></i>
                Resultados del Cálculo
            </h2>
            <p class="text-sm text-slate-400 mb-6">Dimensionamiento basado en Erlang C</p>

            <div id="resultsContainer" class="text-center py-12">
                <div class="text-6xl text-slate-700 mb-4">
                    <i class="fas fa-calculator"></i>
                </div>
                <p class="text-slate-500 text-lg">Ingresa los parámetros y haz clic en Calcular</p>
            </div>

            <!-- Results will be populated here -->
            <div id="calculationResults" class="hidden">
                <div class="space-y-3">
                    <div class="result-item">
                        <div>
                            <div class="result-label">Agentes Requeridos</div>
                            <div class="text-xs text-slate-500 mt-1">Mínimo para cumplir SL</div>
                        </div>
                        <div class="result-value" id="resultAgents">-</div>
                    </div>

                    <div class="result-item">
                        <div>
                            <div class="result-label">Staff Total</div>
                            <div class="text-xs text-slate-500 mt-1">Incluye shrinkage</div>
                        </div>
                        <div class="result-value" id="resultStaff">-</div>
                    </div>

                    <div class="result-item">
                        <div>
                            <div class="result-label">Service Level</div>
                            <div class="text-xs text-slate-500 mt-1">SL proyectado</div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="result-value" id="resultSl">-</div>
                            <span id="slBadge" class="metric-badge">-</span>
                        </div>
                    </div>

                    <div class="result-item">
                        <div>
                            <div class="result-label">Occupancy</div>
                            <div class="text-xs text-slate-500 mt-1">Utilización de agentes</div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="result-value" id="resultOccupancy">-</div>
                            <span id="occBadge" class="metric-badge">-</span>
                        </div>
                    </div>

                    <div class="result-item">
                        <div>
                            <div class="result-label">Intensity (Erlangs)</div>
                            <div class="text-xs text-slate-500 mt-1">Carga de trabajo</div>
                        </div>
                        <div class="result-value" id="resultErlangs">-</div>
                    </div>
                </div>

                <!-- Additional Info -->
                <div class="mt-6 p-4 bg-slate-900/50 rounded-lg border border-slate-700/50">
                    <h3 class="text-sm font-semibold text-slate-300 mb-3 flex items-center gap-2">
                        <i class="fas fa-info-circle text-blue-400"></i>
                        Información Adicional
                    </h3>
                    <div class="grid grid-cols-2 gap-3 text-xs">
                        <div>
                            <span class="text-slate-500">Intervalo:</span>
                            <span class="text-slate-300 font-semibold ml-2" id="infoInterval">-</span>
                        </div>
                        <div>
                            <span class="text-slate-500">Llamadas:</span>
                            <span class="text-slate-300 font-semibold ml-2" id="infoCalls">-</span>
                        </div>
                        <div>
                            <span class="text-slate-500">AHT:</span>
                            <span class="text-slate-300 font-semibold ml-2" id="infoAht">-</span>
                        </div>
                        <div>
                            <span class="text-slate-500">Objetivo SL:</span>
                            <span class="text-slate-300 font-semibold ml-2" id="infoTarget">-</span>
                        </div>
                    </div>
                </div>

                <!-- Export Button -->
                <div class="mt-6">
                    <button onclick="exportResults()" class="w-full px-4 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg transition-colors font-semibold">
                        <i class="fas fa-download mr-2"></i>
                        Exportar Resultados
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Presets Section -->
    <div class="mt-6 calculator-card rounded-xl p-6">
        <h2 class="text-xl font-bold text-white mb-2 flex items-center gap-2">
            <i class="fas fa-bookmark text-cyan-400"></i>
            Configuraciones Predefinidas
        </h2>
        <p class="text-sm text-slate-400 mb-4">Carga rápidamente escenarios comunes</p>
        
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <button onclick="loadPreset('highVolume')" class="p-4 bg-slate-800/50 hover:bg-slate-700/50 border border-slate-600 rounded-lg transition-all text-left">
                <div class="text-cyan-400 text-2xl mb-2"><i class="fas fa-phone-volume"></i></div>
                <div class="font-semibold text-white">Alto Volumen</div>
                <div class="text-xs text-slate-400 mt-1">200 calls, 15 min, AHT 180s</div>
            </button>
            
            <button onclick="loadPreset('standard')" class="p-4 bg-slate-800/50 hover:bg-slate-700/50 border border-slate-600 rounded-lg transition-all text-left">
                <div class="text-blue-400 text-2xl mb-2"><i class="fas fa-chart-bar"></i></div>
                <div class="font-semibold text-white">Estándar</div>
                <div class="text-xs text-slate-400 mt-1">100 calls, 30 min, AHT 240s</div>
            </button>
            
            <button onclick="loadPreset('lowVolume')" class="p-4 bg-slate-800/50 hover:bg-slate-700/50 border border-slate-600 rounded-lg transition-all text-left">
                <div class="text-purple-400 text-2xl mb-2"><i class="fas fa-chart-line"></i></div>
                <div class="font-semibold text-white">Bajo Volumen</div>
                <div class="text-xs text-slate-400 mt-1">50 calls, 60 min, AHT 300s</div>
            </button>
            
            <button onclick="loadPreset('premium')" class="p-4 bg-slate-800/50 hover:bg-slate-700/50 border border-slate-600 rounded-lg transition-all text-left">
                <div class="text-yellow-400 text-2xl mb-2"><i class="fas fa-star"></i></div>
                <div class="font-semibold text-white">Premium</div>
                <div class="text-xs text-slate-400 mt-1">80 calls, 30 min, AHT 420s, SL 90/15</div>
            </button>
        </div>
    </div>
</div>

<script>
let lastCalculation = null;

// Toggle advanced settings
function toggleAdvanced() {
    const settings = document.getElementById('advancedSettings');
    const icon = document.getElementById('advancedIcon');
    settings.classList.toggle('hidden');
    icon.classList.toggle('fa-chevron-right');
    icon.classList.toggle('fa-chevron-down');
}

// Toggle help section
function toggleHelp() {
    const help = document.getElementById('helpSection');
    help.classList.toggle('hidden');
}

// Load preset configurations
function loadPreset(type) {
    const presets = {
        highVolume: {
            targetSl: 80,
            targetAns: 20,
            intervalMinutes: 15,
            calls: 200,
            ahtSeconds: 180,
            occupancyTarget: 85,
            shrinkage: 30
        },
        standard: {
            targetSl: 80,
            targetAns: 20,
            intervalMinutes: 30,
            calls: 100,
            ahtSeconds: 240,
            occupancyTarget: 85,
            shrinkage: 30
        },
        lowVolume: {
            targetSl: 80,
            targetAns: 20,
            intervalMinutes: 60,
            calls: 50,
            ahtSeconds: 300,
            occupancyTarget: 80,
            shrinkage: 30
        },
        premium: {
            targetSl: 90,
            targetAns: 15,
            intervalMinutes: 30,
            calls: 80,
            ahtSeconds: 420,
            occupancyTarget: 85,
            shrinkage: 30
        }
    };

    const preset = presets[type];
    if (!preset) return;

    // Populate form
    Object.keys(preset).forEach(key => {
        const input = document.getElementById(key);
        if (input) input.value = preset[key];
    });

    // Show success message
    showToast('Preset cargado: ' + type, 'success');
}

// Reset form
function resetForm() {
    document.getElementById('calculatorForm').reset();
    document.getElementById('resultsContainer').classList.remove('hidden');
    document.getElementById('calculationResults').classList.add('hidden');
    showToast('Formulario reseteado', 'info');
}

// Form submission
document.getElementById('calculatorForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const data = {
        action: 'calculate',
        targetSl: parseFloat(formData.get('targetSl')),
        targetAns: parseInt(formData.get('targetAns')),
        intervalMinutes: parseInt(formData.get('intervalMinutes')),
        calls: parseInt(formData.get('calls')),
        ahtSeconds: parseInt(formData.get('ahtSeconds')),
        occupancyTarget: parseFloat(formData.get('occupancyTarget')),
        shrinkage: parseFloat(formData.get('shrinkage'))
    };

    try {
        const response = await fetch('../api/service_level_calculator.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            displayResults(result.data, data);
            lastCalculation = { result: result.data, params: data };
            showToast('Cálculo completado exitosamente', 'success');
        } else {
            showToast('Error: ' + (result.error || 'Error desconocido'), 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Error al procesar el cálculo', 'error');
    }
});

// Display results
function displayResults(data, params) {
    // Hide placeholder, show results
    document.getElementById('resultsContainer').classList.add('hidden');
    document.getElementById('calculationResults').classList.remove('hidden');

    // Populate results
    document.getElementById('resultAgents').textContent = data.required_agents;
    document.getElementById('resultStaff').textContent = data.required_staff;
    document.getElementById('resultSl').textContent = (data.service_level * 100).toFixed(2) + '%';
    document.getElementById('resultOccupancy').textContent = (data.occupancy * 100).toFixed(2) + '%';
    document.getElementById('resultErlangs').textContent = data.workload.toFixed(3);

    // Additional info
    document.getElementById('infoInterval').textContent = params.intervalMinutes + ' min';
    document.getElementById('infoCalls').textContent = params.calls;
    document.getElementById('infoAht').textContent = params.ahtSeconds + ' seg';
    document.getElementById('infoTarget').textContent = params.targetSl + '% / ' + params.targetAns + 's';

    // Set badges
    const slPercent = data.service_level * 100;
    const slBadge = document.getElementById('slBadge');
    if (slPercent >= 90) {
        slBadge.textContent = 'Excelente';
        slBadge.className = 'metric-badge metric-excellent';
    } else if (slPercent >= 80) {
        slBadge.textContent = 'Bueno';
        slBadge.className = 'metric-badge metric-good';
    } else if (slPercent >= 70) {
        slBadge.textContent = 'Aceptable';
        slBadge.className = 'metric-badge metric-warning';
    } else {
        slBadge.textContent = 'Bajo';
        slBadge.className = 'metric-badge metric-poor';
    }

    const occPercent = data.occupancy * 100;
    const occBadge = document.getElementById('occBadge');
    if (occPercent >= 80 && occPercent <= 90) {
        occBadge.textContent = 'Óptimo';
        occBadge.className = 'metric-badge metric-excellent';
    } else if (occPercent >= 70 && occPercent < 95) {
        occBadge.textContent = 'Bueno';
        occBadge.className = 'metric-badge metric-good';
    } else if (occPercent >= 60 || occPercent >= 95) {
        occBadge.textContent = 'Revisar';
        occBadge.className = 'metric-badge metric-warning';
    } else {
        occBadge.textContent = 'Bajo';
        occBadge.className = 'metric-badge metric-poor';
    }

    // Animate results
    document.getElementById('calculationResults').classList.add('animate-slide-in');
}

// Export results
function exportResults() {
    if (!lastCalculation) {
        showToast('No hay resultados para exportar', 'warning');
        return;
    }

    const { result, params } = lastCalculation;
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-').split('T')[0];
    
    const csvContent = [
        ['Service Level Calculator Results'],
        ['Fecha', new Date().toLocaleString()],
        [],
        ['Parámetros de Entrada'],
        ['Service Level Goal', params.targetSl + '% en ' + params.targetAns + ' segundos'],
        ['Interval Length', params.intervalMinutes + ' minutos'],
        ['Calls', params.calls],
        ['Average Handling Time', params.ahtSeconds + ' segundos'],
        ['Occupancy Target', params.occupancyTarget + '%'],
        ['Shrinkage', params.shrinkage + '%'],
        [],
        ['Resultados'],
        ['Required Agents', result.required_agents],
        ['Required Staff', result.required_staff],
        ['Service Level', (result.service_level * 100).toFixed(2) + '%'],
        ['Occupancy', (result.occupancy * 100).toFixed(2) + '%'],
        ['Intensity (Erlangs)', result.workload.toFixed(3)]
    ].map(row => row.join(',')).join('\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'service_level_calculation_' + timestamp + '.csv';
    link.click();

    showToast('Resultados exportados', 'success');
}

// Toast notifications
function showToast(message, type = 'info') {
    const colors = {
        success: 'bg-emerald-600',
        error: 'bg-red-600',
        warning: 'bg-yellow-600',
        info: 'bg-blue-600'
    };

    const toast = document.createElement('div');
    toast.className = `fixed top-4 right-4 ${colors[type]} text-white px-6 py-3 rounded-lg shadow-lg z-50 animate-slide-in`;
    toast.innerHTML = `
        <div class="flex items-center gap-3">
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    console.log('Service Level Calculator Ready');
});
</script>

<?php include __DIR__ . '/../footer.php'; ?>
