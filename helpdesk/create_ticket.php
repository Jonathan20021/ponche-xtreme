<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

if (!userHasPermission('helpdesk_tickets')) {
    header("Location: ../unauthorized.php");
    exit;
}

$user_id = $_SESSION['user_id'];
require_once __DIR__ . '/../header.php';
?>

<link rel="stylesheet" href="helpdesk_styles.css">

<style>
.form-container {
    background: var(--bg-card);
    border-radius: var(--radius);
    padding: 40px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-md);
    max-width: 900px;
    margin: 0 auto;
}

.form-group {
    margin-bottom: 28px;
}

.form-group label {
    display: block;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 8px;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-group label .required {
    color: var(--danger);
    margin-left: 4px;
}

.form-control {
    width: 100%;
    padding: 14px 18px;
    border: 2px solid var(--border);
    border-radius: var(--radius);
    font-size: 15px;
    transition: var(--transition);
    background: var(--bg-card);
    color: var(--text-primary);
    font-family: inherit;
}

.theme-dark .form-control {
    background: #0f172a;
    border-color: #475569;
}

.theme-dark .form-control::placeholder {
    color: #64748b;
}

.form-control:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
}

.form-control.error {
    border-color: var(--danger);
}

textarea.form-control {
    min-height: 140px;
    resize: vertical;
    line-height: 1.6;
}

.form-help {
    font-size: 13px;
    color: var(--text-secondary);
    margin-top: 6px;
}

.priority-selector {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}

.priority-option {
    position: relative;
}

.priority-option input[type="radio"] {
    position: absolute;
    opacity: 0;
}

.priority-label {
    display: block;
    padding: 16px;
    border: 2px solid var(--border);
    border-radius: var(--radius);
    text-align: center;
    cursor: pointer;
    transition: var(--transition);
    font-weight: 600;
    font-size: 13px;
}

.priority-option input[type="radio"]:checked + .priority-label {
    border-color: var(--accent);
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
}

.theme-dark .priority-label {
    background: #1e293b;
    border-color: #475569;
}

.priority-label.low { color: #3730a3; }
.priority-label.medium { color: #92400e; }
.priority-label.high { color: #9a3412; }
.priority-label.critical { color: #991b1b; }

.priority-option input[type="radio"]:checked + .priority-label.low {
    background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
}

.theme-dark .priority-option input[type="radio"]:checked + .priority-label.low {
    background: linear-gradient(135deg, #312e81, #3730a3);
    border-color: #4338ca;
}

.priority-option input[type="radio"]:checked + .priority-label.medium {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
}

.theme-dark .priority-option input[type="radio"]:checked + .priority-label.medium {
    background: linear-gradient(135deg, #78350f, #92400e);
    border-color: #b45309;
}

.priority-option input[type="radio"]:checked + .priority-label.high {
    background: linear-gradient(135deg, #fed7aa, #fdba74);
}

.theme-dark .priority-option input[type="radio"]:checked + .priority-label.high {
    background: linear-gradient(135deg, #7c2d12, #9a3412);
    border-color: #c2410c;
}

.priority-option input[type="radio"]:checked + .priority-label.critical {
    background: linear-gradient(135deg, #fecaca, #fca5a5);
}

.theme-dark .priority-option input[type="radio"]:checked + .priority-label.critical {
    background: linear-gradient(135deg, #7f1d1d, #991b1b);
    border-color: #dc2626;
}

.form-actions {
    display: flex;
    gap: 16px;
    justify-content: flex-end;
    margin-top: 32px;
    padding-top: 32px;
    border-top: 2px solid var(--border);
}

.btn-cancel {
    background: white;
    color: var(--text-primary);
    border: 2px solid var(--border);
}

.btn-cancel:hover {
    border-color: var(--accent);
    color: var(--accent);
}

.success-message {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    border: 2px solid var(--success);
    color: #065f46;
    padding: 20px;
    border-radius: var(--radius);
    margin-bottom: 24px;
    display: none;
    font-size: 15px;
    font-weight: 500;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
}

.error-message {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    border: 2px solid var(--danger);
    color: #991b1b;
    padding: 20px;
    border-radius: var(--radius);
    margin-bottom: 24px;
    display: none;
    font-size: 15px;
    font-weight: 500;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
}
</style>

<div class="helpdesk-wrapper">
    <div class="helpdesk-container">
        <div class="page-header">
            <div class="header-content">
                <div class="header-title">
                    <div class="header-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="header-text">
                        <h1>Crear Nuevo Ticket</h1>
                        <p>Reporta un problema o solicita asistencia</p>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="my_tickets.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Volver a Mis Tickets
                    </a>
                </div>
            </div>
        </div>

        <div class="form-container">
            <div id="successMessage" class="success-message">
                <i class="fas fa-check-circle"></i>
                <strong>¡Ticket creado exitosamente!</strong>
                <p>Tu ticket ha sido registrado y será atendido pronto.</p>
            </div>

            <div id="errorMessage" class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <strong>Error al crear el ticket</strong>
                <p id="errorText"></p>
            </div>

            <form id="createTicketForm" onsubmit="submitTicket(event)">
                <div class="form-group">
                    <label for="category">
                        Categoría<span class="required">*</span>
                    </label>
                    <select id="category" name="category_id" class="form-control" required>
                        <option value="">Selecciona una categoría</option>
                    </select>
                    <div class="form-help">Selecciona la categoría que mejor describe tu solicitud</div>
                </div>

                <div class="form-group">
                    <label for="subject">
                        Asunto<span class="required">*</span>
                    </label>
                    <input type="text" id="subject" name="subject" class="form-control" 
                           placeholder="Describe brevemente el problema" required maxlength="255">
                    <div class="form-help">Un resumen claro y conciso de tu solicitud</div>
                </div>

                <div class="form-group">
                    <label for="description">
                        Descripción<span class="required">*</span>
                    </label>
                    <textarea id="description" name="description" class="form-control" 
                              placeholder="Proporciona todos los detalles relevantes sobre tu solicitud..." required></textarea>
                    <div class="form-help">Incluye toda la información necesaria para entender y resolver tu solicitud</div>
                </div>

                <div class="form-group">
                    <label>
                        Prioridad<span class="required">*</span>
                    </label>
                    <div class="priority-selector">
                        <div class="priority-option">
                            <input type="radio" id="priority-low" name="priority" value="low" checked>
                            <label for="priority-low" class="priority-label low">
                                <i class="fas fa-arrow-down"></i><br>
                                Baja
                            </label>
                        </div>
                        <div class="priority-option">
                            <input type="radio" id="priority-medium" name="priority" value="medium">
                            <label for="priority-medium" class="priority-label medium">
                                <i class="fas fa-minus"></i><br>
                                Media
                            </label>
                        </div>
                        <div class="priority-option">
                            <input type="radio" id="priority-high" name="priority" value="high">
                            <label for="priority-high" class="priority-label high">
                                <i class="fas fa-arrow-up"></i><br>
                                Alta
                            </label>
                        </div>
                        <div class="priority-option">
                            <input type="radio" id="priority-critical" name="priority" value="critical">
                            <label for="priority-critical" class="priority-label critical">
                                <i class="fas fa-exclamation-triangle"></i><br>
                                Crítica
                            </label>
                        </div>
                    </div>
                    <div class="form-help">Selecciona la urgencia de tu solicitud</div>
                </div>

                <div class="form-actions">
                    <a href="my_tickets.php" class="btn btn-cancel">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-paper-plane"></i>
                        Crear Ticket
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    loadCategories();
});

function loadCategories() {
    fetch('../hr/helpdesk_api.php?action=get_categories')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const select = document.getElementById('category');
                data.categories.forEach(cat => {
                    const option = document.createElement('option');
                    option.value = cat.id;
                    option.textContent = cat.name + ' - ' + cat.description;
                    select.appendChild(option);
                });
            }
        });
}

function submitTicket(event) {
    event.preventDefault();
    
    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creando...';
    
    const formData = new FormData(event.target);
    formData.append('action', 'create_ticket');
    
    fetch('../hr/helpdesk_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        
        if (data.success) {
            document.getElementById('successMessage').style.display = 'block';
            document.getElementById('errorMessage').style.display = 'none';
            document.getElementById('createTicketForm').reset();
            
            setTimeout(() => {
                window.location.href = 'my_tickets.php';
            }, 2000);
        } else {
            document.getElementById('errorMessage').style.display = 'block';
            document.getElementById('successMessage').style.display = 'none';
            document.getElementById('errorText').textContent = data.error || 'Error desconocido';
        }
    })
    .catch(error => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        document.getElementById('errorMessage').style.display = 'block';
        document.getElementById('successMessage').style.display = 'none';
        document.getElementById('errorText').textContent = 'Error de conexión. Por favor intenta nuevamente.';
    });
}
</script>

</main>
</body>
</html>
