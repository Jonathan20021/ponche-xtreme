<!-- Create Medical Leave Modal -->
<div id="createModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 overflow-y-auto">
    <div class="glass-card m-4" style="width: min(700px, 95%); max-height: 90vh; overflow-y: auto;">
        <h3 class="text-xl font-semibold text-white mb-4">
            <i class="fas fa-notes-medical text-red-400 mr-2"></i>
            Nueva Licencia Médica
        </h3>
        <form method="POST">
            <input type="hidden" name="create_leave" value="1">
            
            <div class="form-group mb-4">
                <label for="employee_id">Empleado *</label>
                <select id="employee_id" name="employee_id" required>
                    <option value="">Seleccionar empleado...</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>">
                            <?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_code'] . ')') ?>
                            <?php if ($emp['department_name']): ?>
                                - <?= htmlspecialchars($emp['department_name']) ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div class="form-group">
                    <label for="leave_type">Tipo de Licencia *</label>
                    <select id="leave_type" name="leave_type" required>
                        <option value="MEDICAL">Médica</option>
                        <option value="MATERNITY">Maternidad</option>
                        <option value="PATERNITY">Paternidad</option>
                        <option value="ACCIDENT">Accidente</option>
                        <option value="SURGERY">Cirugía</option>
                        <option value="CHRONIC">Crónica</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="diagnosis">Diagnóstico</label>
                    <input type="text" id="diagnosis" name="diagnosis" placeholder="Ej: Gripe, Fractura...">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div class="form-group">
                    <label for="start_date">Fecha de Inicio *</label>
                    <input type="date" id="start_date" name="start_date" required>
                </div>
                <div class="form-group">
                    <label for="end_date">Fecha de Fin *</label>
                    <input type="date" id="end_date" name="end_date" required>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div class="form-group">
                    <label for="doctor_name">Nombre del Médico</label>
                    <input type="text" id="doctor_name" name="doctor_name" placeholder="Dr. Juan Pérez">
                </div>
                <div class="form-group">
                    <label for="medical_center">Centro Médico</label>
                    <input type="text" id="medical_center" name="medical_center" placeholder="Hospital General">
                </div>
            </div>

            <div class="form-group mb-4">
                <label for="medical_certificate_number">Número de Certificado Médico</label>
                <input type="text" id="medical_certificate_number" name="medical_certificate_number" placeholder="Ej: CM-2025-001">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div class="form-group">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="is_paid" name="is_paid" checked class="form-checkbox">
                        <span>Licencia Pagada</span>
                    </label>
                </div>
                <div class="form-group">
                    <label for="payment_percentage">Porcentaje de Pago (%)</label>
                    <input type="number" id="payment_percentage" name="payment_percentage" value="100" min="0" max="100" step="0.01">
                </div>
            </div>

            <div class="form-group mb-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="is_work_related" name="is_work_related" class="form-checkbox">
                    <span>Relacionado con el Trabajo (Accidente Laboral)</span>
                </label>
            </div>

            <div class="form-group mb-6">
                <label for="reason">Razón / Detalles *</label>
                <textarea id="reason" name="reason" rows="3" required placeholder="Describa la razón de la licencia médica..."></textarea>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="btn-primary flex-1">
                    <i class="fas fa-save"></i>
                    Crear Licencia
                </button>
                <button type="button" onclick="document.getElementById('createModal').classList.add('hidden')" class="btn-secondary flex-1">
                    <i class="fas fa-times"></i>
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Medical Leave Modal -->
<div id="viewModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 overflow-y-auto">
    <div class="glass-card m-4" style="width: min(800px, 95%); max-height: 90vh; overflow-y: auto;">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-semibold text-white">
                <i class="fas fa-file-medical text-red-400 mr-2"></i>
                Detalles de Licencia Médica
            </h3>
            <button onclick="document.getElementById('viewModal').classList.add('hidden')" class="text-slate-400 hover:text-white">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <div id="viewContent" class="space-y-4">
            <!-- Content will be populated by JavaScript -->
        </div>
    </div>
</div>

<!-- Review Medical Leave Modal -->
<div id="reviewModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="glass-card m-4" style="width: min(500px, 95%);">
        <h3 class="text-xl font-semibold text-white mb-4">
            <i class="fas fa-check-circle text-green-400 mr-2"></i>
            Revisar Licencia Médica
        </h3>
        <form method="POST">
            <input type="hidden" name="review_leave" value="1">
            <input type="hidden" id="review_leave_id" name="leave_id">
            
            <p class="text-slate-300 mb-4">
                Empleado: <span id="review_employee_name" class="font-semibold text-white"></span>
            </p>

            <div class="form-group mb-4">
                <label for="new_status">Estado *</label>
                <select id="new_status" name="new_status" required>
                    <option value="APPROVED">Aprobar</option>
                    <option value="REJECTED">Rechazar</option>
                    <option value="CANCELLED">Cancelar</option>
                </select>
            </div>

            <div class="form-group mb-6">
                <label for="review_notes">Notas de Revisión</label>
                <textarea id="review_notes" name="review_notes" rows="3" placeholder="Comentarios sobre la decisión..."></textarea>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="btn-primary flex-1">
                    <i class="fas fa-save"></i>
                    Guardar Revisión
                </button>
                <button type="button" onclick="document.getElementById('reviewModal').classList.add('hidden')" class="btn-secondary flex-1">
                    <i class="fas fa-times"></i>
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Extend Medical Leave Modal -->
<div id="extendModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="glass-card m-4" style="width: min(500px, 95%);">
        <h3 class="text-xl font-semibold text-white mb-4">
            <i class="fas fa-plus-circle text-purple-400 mr-2"></i>
            Extender Licencia Médica
        </h3>
        <form method="POST">
            <input type="hidden" name="extend_leave" value="1">
            <input type="hidden" id="extend_leave_id" name="leave_id">
            
            <div class="form-group mb-4">
                <label>Fecha de Fin Actual</label>
                <input type="date" id="extend_current_end" disabled class="bg-slate-700">
            </div>

            <div class="form-group mb-4">
                <label for="new_end_date">Nueva Fecha de Fin *</label>
                <input type="date" id="new_end_date" name="new_end_date" required>
            </div>

            <div class="form-group mb-6">
                <label for="extension_reason">Razón de la Extensión *</label>
                <textarea id="extension_reason" name="extension_reason" rows="3" required placeholder="Explique por qué se extiende la licencia..."></textarea>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="btn-primary flex-1">
                    <i class="fas fa-save"></i>
                    Extender Licencia
                </button>
                <button type="button" onclick="document.getElementById('extendModal').classList.add('hidden')" class="btn-secondary flex-1">
                    <i class="fas fa-times"></i>
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Followup Modal -->
<div id="followupModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="glass-card m-4" style="width: min(500px, 95%);">
        <h3 class="text-xl font-semibold text-white mb-4">
            <i class="fas fa-stethoscope text-cyan-400 mr-2"></i>
            Agregar Seguimiento Médico
        </h3>
        <form method="POST">
            <input type="hidden" name="add_followup" value="1">
            <input type="hidden" id="followup_leave_id" name="leave_id">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div class="form-group">
                    <label for="followup_date">Fecha de Seguimiento *</label>
                    <input type="date" id="followup_date" name="followup_date" required>
                </div>
                <div class="form-group">
                    <label for="followup_type">Tipo *</label>
                    <select id="followup_type" name="followup_type" required>
                        <option value="CHECKUP">Chequeo</option>
                        <option value="TREATMENT">Tratamiento</option>
                        <option value="THERAPY">Terapia</option>
                        <option value="EXAM">Examen</option>
                        <option value="OTHER">Otro</option>
                    </select>
                </div>
            </div>

            <div class="form-group mb-6">
                <label for="followup_notes">Notas del Seguimiento *</label>
                <textarea id="followup_notes" name="followup_notes" rows="3" required placeholder="Detalles del seguimiento médico..."></textarea>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="btn-primary flex-1">
                    <i class="fas fa-save"></i>
                    Registrar Seguimiento
                </button>
                <button type="button" onclick="document.getElementById('followupModal').classList.add('hidden')" class="btn-secondary flex-1">
                    <i class="fas fa-times"></i>
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function viewLeave(leave) {
    const modal = document.getElementById('viewModal');
    const content = document.getElementById('viewContent');
    
    const statusColors = {
        'PENDING': 'bg-yellow-500',
        'APPROVED': 'bg-green-500',
        'EXTENDED': 'bg-blue-500',
        'COMPLETED': 'bg-gray-500',
        'REJECTED': 'bg-red-500',
        'CANCELLED': 'bg-orange-500'
    };
    
    const typeLabels = {
        'MEDICAL': 'Médica',
        'MATERNITY': 'Maternidad',
        'PATERNITY': 'Paternidad',
        'ACCIDENT': 'Accidente',
        'SURGERY': 'Cirugía',
        'CHRONIC': 'Crónica'
    };
    
    content.innerHTML = `
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <p class="text-slate-400 text-sm mb-1">Empleado</p>
                <p class="text-white font-semibold">${leave.first_name} ${leave.last_name}</p>
                <p class="text-slate-400 text-sm">${leave.employee_code}</p>
            </div>
            <div>
                <p class="text-slate-400 text-sm mb-1">Departamento</p>
                <p class="text-white">${leave.department_name || 'N/A'}</p>
            </div>
        </div>
        
        <div class="border-t border-slate-700 pt-4 mt-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-slate-400 text-sm mb-1">Tipo de Licencia</p>
                    <p class="text-white font-semibold">${typeLabels[leave.leave_type] || leave.leave_type}</p>
                </div>
                <div>
                    <p class="text-slate-400 text-sm mb-1">Estado</p>
                    <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold text-white ${statusColors[leave.status] || 'bg-gray-500'}">
                        ${leave.status}
                    </span>
                </div>
            </div>
        </div>
        
        <div class="border-t border-slate-700 pt-4 mt-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <p class="text-slate-400 text-sm mb-1">Fecha de Inicio</p>
                    <p class="text-white">${new Date(leave.start_date).toLocaleDateString('es-DO')}</p>
                </div>
                <div>
                    <p class="text-slate-400 text-sm mb-1">Fecha de Fin</p>
                    <p class="text-white">${new Date(leave.end_date).toLocaleDateString('es-DO')}</p>
                </div>
                <div>
                    <p class="text-slate-400 text-sm mb-1">Total de Días</p>
                    <p class="text-white font-bold text-xl">${leave.total_days}</p>
                </div>
            </div>
        </div>
        
        ${leave.diagnosis ? `
        <div class="border-t border-slate-700 pt-4 mt-4">
            <p class="text-slate-400 text-sm mb-1">Diagnóstico</p>
            <p class="text-white">${leave.diagnosis}</p>
        </div>
        ` : ''}
        
        ${leave.reason ? `
        <div class="border-t border-slate-700 pt-4 mt-4">
            <p class="text-slate-400 text-sm mb-1">Razón / Detalles</p>
            <p class="text-white">${leave.reason}</p>
        </div>
        ` : ''}
        
        ${leave.doctor_name || leave.medical_center ? `
        <div class="border-t border-slate-700 pt-4 mt-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                ${leave.doctor_name ? `
                <div>
                    <p class="text-slate-400 text-sm mb-1">Médico</p>
                    <p class="text-white">${leave.doctor_name}</p>
                </div>
                ` : ''}
                ${leave.medical_center ? `
                <div>
                    <p class="text-slate-400 text-sm mb-1">Centro Médico</p>
                    <p class="text-white">${leave.medical_center}</p>
                </div>
                ` : ''}
            </div>
        </div>
        ` : ''}
        
        <div class="border-t border-slate-700 pt-4 mt-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-slate-400 text-sm mb-1">Licencia Pagada</p>
                    <p class="text-white">${leave.is_paid ? 'Sí' : 'No'}</p>
                </div>
                ${leave.is_paid ? `
                <div>
                    <p class="text-slate-400 text-sm mb-1">Porcentaje de Pago</p>
                    <p class="text-white">${leave.payment_percentage}%</p>
                </div>
                ` : ''}
            </div>
        </div>
        
        ${leave.is_work_related ? `
        <div class="border-t border-slate-700 pt-4 mt-4">
            <p class="text-orange-400 font-semibold">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                Relacionado con el Trabajo (Accidente Laboral)
            </p>
        </div>
        ` : ''}
        
        ${leave.reviewed_by ? `
        <div class="border-t border-slate-700 pt-4 mt-4">
            <p class="text-slate-400 text-sm mb-1">Revisado por</p>
            <p class="text-white">${leave.reviewer_username || 'N/A'}</p>
            <p class="text-slate-400 text-sm">${leave.reviewed_at ? new Date(leave.reviewed_at).toLocaleString('es-DO') : ''}</p>
            ${leave.review_notes ? `<p class="text-slate-300 mt-2">${leave.review_notes}</p>` : ''}
        </div>
        ` : ''}
        
        ${leave.extension_count > 0 ? `
        <div class="border-t border-slate-700 pt-4 mt-4">
            <p class="text-blue-400 font-semibold">
                <i class="fas fa-plus-circle mr-2"></i>
                Esta licencia tiene ${leave.extension_count} extensión(es)
            </p>
        </div>
        ` : ''}
    `;
    
    modal.classList.remove('hidden');
}

function reviewLeave(leaveId, employeeName) {
    document.getElementById('review_leave_id').value = leaveId;
    document.getElementById('review_employee_name').textContent = employeeName;
    document.getElementById('reviewModal').classList.remove('hidden');
}

function extendLeave(leaveId, currentEndDate) {
    document.getElementById('extend_leave_id').value = leaveId;
    document.getElementById('extend_current_end').value = currentEndDate;
    document.getElementById('new_end_date').min = currentEndDate;
    document.getElementById('extendModal').classList.remove('hidden');
}

function addFollowup(leaveId) {
    document.getElementById('followup_leave_id').value = leaveId;
    document.getElementById('followupModal').classList.remove('hidden');
}

// Close modals when clicking outside
document.querySelectorAll('.fixed').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.add('hidden');
        }
    });
});
</script>
