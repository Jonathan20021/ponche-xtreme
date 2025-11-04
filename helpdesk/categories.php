<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

if (!userHasPermission('helpdesk')) {
    header("Location: ../unauthorized.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'employee';
$isAdmin = ($user_role === 'Admin' || $user_role === 'HR');

if (!$isAdmin) {
    header("Location: ../unauthorized.php");
    exit;
}

require_once __DIR__ . '/../header.php';
?>

<link rel="stylesheet" href="helpdesk_styles.css">

<style>
.categories-grid {
    display: grid;
    gap: 16px;
}

.category-card {
    background: white;
    border-radius: 6px;
    padding: 20px;
    border: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.15s ease;
}

.category-card:hover {
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.category-info {
    flex: 1;
}

.category-name {
    font-size: 16px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 4px;
}

.category-description {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 8px;
}

.category-meta {
    display: flex;
    gap: 16px;
    font-size: 12px;
    color: #9ca3af;
}

.category-color {
    width: 24px;
    height: 24px;
    border-radius: 4px;
    border: 1px solid #e5e7eb;
}

.category-actions {
    display: flex;
    gap: 8px;
}

.btn-icon {
    padding: 8px 12px;
    border: none;
    border-radius: 4px;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.btn-edit {
    background: #f3f4f6;
    color: #374151;
}

.btn-edit:hover {
    background: #e5e7eb;
}

.btn-delete {
    background: #fee2e2;
    color: #991b1b;
}

.btn-delete:hover {
    background: #fecaca;
}

.modal-form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.color-picker-wrapper {
    display: flex;
    align-items: center;
    gap: 12px;
}

.color-preview {
    width: 40px;
    height: 40px;
    border-radius: 4px;
    border: 1px solid #d1d5db;
}
</style>

<div class="helpdesk-wrapper">
    <div class="helpdesk-container">
        <div class="page-header">
            <div class="header-content">
                <div class="header-title">
                    <div class="header-icon">
                        <i class="fas fa-folder"></i>
                    </div>
                    <div class="header-text">
                        <h1>Gestión de Categorías</h1>
                        <p>Administra las categorías de tickets del sistema</p>
                    </div>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus"></i>
                        Nueva Categoría
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Volver
                    </a>
                </div>
            </div>
        </div>

        <div class="tickets-section">
            <div class="section-header">
                <h2 class="section-title">Categorías Disponibles</h2>
            </div>
            <div class="categories-grid" id="categoriesGrid">
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 32px; color: #667eea;"></i>
                    <p style="margin-top: 15px; color: #6b7280;">Cargando categorías...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Category Modal -->
<div id="categoryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title" id="modalTitle">Nueva Categoría</h2>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form id="categoryForm" class="modal-form" onsubmit="saveCategory(event)">
                <input type="hidden" id="categoryId" name="category_id">
                
                <div class="form-group">
                    <label for="categoryName">Nombre<span style="color: #ef4444;">*</span></label>
                    <input type="text" id="categoryName" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="categoryDescription">Descripción<span style="color: #ef4444;">*</span></label>
                    <textarea id="categoryDescription" name="description" class="form-control" rows="3" required></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="categoryDepartment">Departamento<span style="color: #ef4444;">*</span></label>
                        <input type="text" id="categoryDepartment" name="department" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="categoryColor">Color</label>
                        <div class="color-picker-wrapper">
                            <input type="color" id="categoryColor" name="color" value="#6366f1" style="width: 60px; height: 40px; border: 1px solid #d1d5db; border-radius: 4px; cursor: pointer;">
                            <span id="colorValue" style="font-size: 13px; color: #6b7280;">#6366f1</span>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="slaResponse">SLA Respuesta (horas)<span style="color: #ef4444;">*</span></label>
                        <input type="number" id="slaResponse" name="sla_response_hours" class="form-control" min="1" value="8" required>
                    </div>

                    <div class="form-group">
                        <label for="slaResolution">SLA Resolución (horas)<span style="color: #ef4444;">*</span></label>
                        <input type="number" id="slaResolution" name="sla_resolution_hours" class="form-control" min="1" value="24" required>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeModal()">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary" id="saveBtn">
                        <i class="fas fa-save"></i>
                        Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    loadCategories();
    
    // Update color preview
    document.getElementById('categoryColor').addEventListener('input', function(e) {
        document.getElementById('colorValue').textContent = e.target.value;
    });
});

function loadCategories() {
    fetch('../hr/helpdesk_api.php?action=get_categories')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayCategories(data.categories);
            } else {
                document.getElementById('categoriesGrid').innerHTML = `
                    <div style="text-align: center; padding: 40px;">
                        <i class="fas fa-exclamation-circle" style="font-size: 48px; color: #ef4444;"></i>
                        <p style="margin-top: 15px; color: #6b7280;">Error al cargar categorías</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('categoriesGrid').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-exclamation-circle" style="font-size: 48px; color: #ef4444;"></i>
                    <p style="margin-top: 15px; color: #6b7280;">Error de conexión</p>
                </div>
            `;
        });
}

function displayCategories(categories) {
    const grid = document.getElementById('categoriesGrid');
    
    if (categories.length === 0) {
        grid.innerHTML = `
            <div style="text-align: center; padding: 40px;">
                <i class="fas fa-folder-open" style="font-size: 48px; color: #d1d5db;"></i>
                <p style="margin-top: 15px; color: #6b7280;">No hay categorías creadas</p>
            </div>
        `;
        return;
    }
    
    grid.innerHTML = categories.map(cat => `
        <div class="category-card">
            <div class="category-info">
                <div class="category-name">${escapeHtml(cat.name)}</div>
                <div class="category-description">${escapeHtml(cat.description)}</div>
                <div class="category-meta">
                    <span><i class="fas fa-building"></i> ${escapeHtml(cat.department)}</span>
                    <span><i class="fas fa-clock"></i> Respuesta: ${cat.sla_response_hours}h</span>
                    <span><i class="fas fa-check-circle"></i> Resolución: ${cat.sla_resolution_hours}h</span>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 12px;">
                <div class="category-color" style="background-color: ${cat.color};"></div>
                <div class="category-actions">
                    <button class="btn-icon btn-edit" onclick="editCategory(${cat.id})" title="Editar">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn-icon btn-delete" onclick="deleteCategory(${cat.id}, '${escapeHtml(cat.name)}')" title="Eliminar">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    `).join('');
}

function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Nueva Categoría';
    document.getElementById('categoryForm').reset();
    document.getElementById('categoryId').value = '';
    document.getElementById('categoryColor').value = '#6366f1';
    document.getElementById('colorValue').textContent = '#6366f1';
    document.getElementById('categoryModal').style.display = 'block';
}

function editCategory(id) {
    fetch(`../hr/helpdesk_api.php?action=get_category&id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const cat = data.category;
                document.getElementById('modalTitle').textContent = 'Editar Categoría';
                document.getElementById('categoryId').value = cat.id;
                document.getElementById('categoryName').value = cat.name;
                document.getElementById('categoryDescription').value = cat.description;
                document.getElementById('categoryDepartment').value = cat.department;
                document.getElementById('categoryColor').value = cat.color;
                document.getElementById('colorValue').textContent = cat.color;
                document.getElementById('slaResponse').value = cat.sla_response_hours;
                document.getElementById('slaResolution').value = cat.sla_resolution_hours;
                document.getElementById('categoryModal').style.display = 'block';
            }
        });
}

function saveCategory(event) {
    event.preventDefault();
    
    const saveBtn = document.getElementById('saveBtn');
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';
    
    const formData = new FormData(event.target);
    const categoryId = document.getElementById('categoryId').value;
    formData.append('action', categoryId ? 'update_category' : 'create_category');
    
    fetch('../hr/helpdesk_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
        
        if (data.success) {
            closeModal();
            loadCategories();
        } else {
            alert('Error: ' + (data.error || 'Error desconocido'));
        }
    })
    .catch(error => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
        alert('Error de conexión');
    });
}

function deleteCategory(id, name) {
    if (!confirm(`¿Estás seguro de eliminar la categoría "${name}"?\n\nEsta acción no se puede deshacer.`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'delete_category');
    formData.append('category_id', id);
    
    fetch('../hr/helpdesk_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadCategories();
        } else {
            alert('Error: ' + (data.error || 'No se puede eliminar esta categoría'));
        }
    });
}

function closeModal() {
    document.getElementById('categoryModal').style.display = 'none';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

window.onclick = function(event) {
    const modal = document.getElementById('categoryModal');
    if (event.target == modal) {
        closeModal();
    }
}
</script>

</main>
</body>
</html>
