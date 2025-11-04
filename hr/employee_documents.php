<?php
session_start();
require_once '../db.php';
ensurePermission('hr_employee_documents');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
$employeeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$employeeId) {
    header('Location: employees.php');
    exit;
}

// Get employee details
$stmt = $pdo->prepare("
    SELECT e.*, u.username, d.name as department_name
    FROM employees e
    JOIN users u ON u.id = e.user_id
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE e.id = ?
");
$stmt->execute([$employeeId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header('Location: employees.php');
    exit;
}

// Document types for HR records
$documentTypes = [
    'Identificación' => ['Cédula', 'Pasaporte', 'Licencia de Conducir', 'Otro ID'],
    'Educación' => ['Diploma Bachiller', 'Título Universitario', 'Certificado Técnico', 'Maestría', 'Doctorado', 'Certificaciones'],
    'Laboral' => ['Contrato de Trabajo', 'Carta de Oferta', 'Evaluación de Desempeño', 'Carta de Recomendación', 'Certificado Laboral'],
    'Médico' => ['Certificado Médico', 'Examen Pre-empleo', 'Seguro Médico', 'Vacunas', 'Alergias'],
    'Financiero' => ['Información Bancaria', 'Comprobante de Cuenta', 'TSS', 'AFP'],
    'Legal' => ['Acuerdo de Confidencialidad', 'Política de Empresa', 'Código de Conducta'],
    'Personal' => ['Foto', 'CV/Resume', 'Referencias', 'Acta de Nacimiento', 'Certificado de Matrimonio'],
    'Otros' => ['Otros Documentos']
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentos - <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        .document-card {
            transition: all 0.3s ease;
        }
        .document-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(59, 130, 246, 0.3);
        }
        .file-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 24px;
        }
        .upload-zone {
            border: 2px dashed #475569;
            transition: all 0.3s ease;
        }
        .upload-zone:hover, .upload-zone.dragover {
            border-color: #3b82f6;
            background: rgba(59, 130, 246, 0.1);
        }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div class="flex items-center gap-4">
                <a href="employee_profile.php?id=<?= $employeeId ?>" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-3xl font-bold text-white">
                        <i class="fas fa-folder-open text-blue-400 mr-3"></i>
                        Documentos de Empleado
                    </h1>
                    <p class="text-slate-400">
                        <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?> - 
                        <?= htmlspecialchars($employee['employee_code']) ?>
                    </p>
                </div>
            </div>
            <button onclick="openUploadModal()" class="btn-primary">
                <i class="fas fa-upload"></i>
                Subir Documento
            </button>
        </div>

        <!-- Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Total Documentos</p>
                        <h3 class="text-3xl font-bold text-white" id="totalDocs">0</h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                        <i class="fas fa-file text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Categorías</p>
                        <h3 class="text-3xl font-bold text-white" id="totalCategories">0</h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                        <i class="fas fa-folder text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Tamaño Total</p>
                        <h3 class="text-2xl font-bold text-white" id="totalSize">0 MB</h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                        <i class="fas fa-database text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Último Documento</p>
                        <h3 class="text-lg font-bold text-white" id="lastUpload">-</h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                        <i class="fas fa-clock text-white text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter -->
        <div class="glass-card mb-6">
            <div class="flex flex-wrap gap-4 items-center">
                <div class="form-group flex-1 min-w-[200px] mb-0">
                    <label for="filterType">Filtrar por Categoría</label>
                    <select id="filterType" onchange="filterDocuments()">
                        <option value="">Todas las Categorías</option>
                        <?php foreach ($documentTypes as $category => $types): ?>
                            <optgroup label="<?= htmlspecialchars($category) ?>">
                                <?php foreach ($types as $type): ?>
                                    <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group flex-1 min-w-[200px] mb-0">
                    <label for="searchDoc">Buscar</label>
                    <input type="text" id="searchDoc" placeholder="Buscar por nombre..." oninput="filterDocuments()">
                </div>
            </div>
        </div>

        <!-- Documents Display -->
        <div class="glass-card">
            <h2 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-file-alt text-blue-400 mr-2"></i>
                Documentos del Record de HR
            </h2>
            <div id="documentsContainer">
                <div class="text-center py-8">
                    <i class="fas fa-spinner fa-spin text-4xl text-blue-400 mb-4"></i>
                    <p class="text-slate-400">Cargando documentos...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Preview Modal -->
    <div id="previewModal" class="hidden fixed inset-0 bg-black bg-opacity-90 flex items-center justify-center z-50 p-4">
        <div class="relative w-full h-full max-w-6xl max-h-[90vh] flex flex-col">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold text-white" id="previewTitle">Vista Previa</h3>
                <div class="flex gap-2">
                    <a id="previewDownload" href="#" class="btn-primary" download>
                        <i class="fas fa-download"></i>
                        Descargar
                    </a>
                    <button onclick="closePreviewModal()" class="btn-secondary">
                        <i class="fas fa-times"></i>
                        Cerrar
                    </button>
                </div>
            </div>
            <div class="flex-1 bg-white rounded-lg overflow-hidden">
                <div id="previewContent" class="w-full h-full flex items-center justify-center">
                    <i class="fas fa-spinner fa-spin text-4xl text-blue-500"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div id="uploadModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 overflow-y-auto">
        <div class="glass-card m-4" style="width: min(600px, 95%); max-height: 90vh; overflow-y: auto;">
            <h3 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-upload text-blue-400 mr-2"></i>
                Subir Documento
            </h3>
            <form id="uploadForm" enctype="multipart/form-data">
                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                
                <div class="form-group mb-4">
                    <label for="document_type">Tipo de Documento *</label>
                    <select id="document_type" name="document_type" required>
                        <option value="">Seleccionar tipo...</option>
                        <?php foreach ($documentTypes as $category => $types): ?>
                            <optgroup label="<?= htmlspecialchars($category) ?>">
                                <?php foreach ($types as $type): ?>
                                    <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-4">
                    <label for="description">Descripción</label>
                    <textarea id="description" name="description" rows="3" placeholder="Descripción opcional del documento..."></textarea>
                </div>

                <div class="form-group mb-4">
                    <label>Archivo *</label>
                    <div class="upload-zone p-8 rounded-lg text-center cursor-pointer" id="uploadZone">
                        <i class="fas fa-cloud-upload-alt text-5xl text-blue-400 mb-3"></i>
                        <p class="text-white font-semibold mb-2">Arrastra el archivo aquí o haz clic para seleccionar</p>
                        <p class="text-slate-400 text-sm">Formatos: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, ZIP, etc.</p>
                        <p class="text-slate-400 text-sm">Tamaño máximo: 10MB</p>
                        <input type="file" id="document_file" name="document_file" class="hidden" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt,.zip,.rar">
                    </div>
                    <div id="fileInfo" class="hidden mt-3 p-3 bg-slate-700/50 rounded-lg">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <i class="fas fa-file text-blue-400 text-2xl"></i>
                                <div>
                                    <p class="text-white font-semibold" id="fileName"></p>
                                    <p class="text-slate-400 text-sm" id="fileSize"></p>
                                </div>
                            </div>
                            <button type="button" onclick="clearFile()" class="text-red-400 hover:text-red-300">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div id="uploadMessage" class="mb-4 hidden"></div>

                <div class="flex gap-3">
                    <button type="submit" class="btn-primary flex-1">
                        <i class="fas fa-upload"></i>
                        Subir Documento
                    </button>
                    <button type="button" onclick="closeUploadModal()" class="btn-secondary flex-1">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const employeeId = <?= $employeeId ?>;
        let allDocuments = [];

        // Load documents on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadDocuments();
        });

        function loadDocuments() {
            fetch(`get_employee_documents.php?employee_id=${employeeId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        allDocuments = data.documents;
                        updateStatistics(data);
                        displayDocuments(data.documents);
                    } else {
                        showError('Error al cargar documentos: ' + data.error);
                    }
                })
                .catch(error => {
                    showError('Error de conexión: ' + error.message);
                });
        }

        function updateStatistics(data) {
            document.getElementById('totalDocs').textContent = data.total;
            
            const categories = new Set(data.documents.map(d => d.document_type));
            document.getElementById('totalCategories').textContent = categories.size;
            
            const totalBytes = data.documents.reduce((sum, d) => sum + parseInt(d.file_size), 0);
            document.getElementById('totalSize').textContent = formatFileSize(totalBytes);
            
            if (data.documents.length > 0) {
                const lastDoc = data.documents[0];
                const date = new Date(lastDoc.uploaded_at);
                document.getElementById('lastUpload').textContent = formatDate(date);
            }
        }

        function displayDocuments(documents) {
            const container = document.getElementById('documentsContainer');
            
            if (documents.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-12">
                        <i class="fas fa-folder-open text-6xl text-slate-600 mb-4"></i>
                        <p class="text-slate-400 text-lg">No hay documentos cargados</p>
                        <button onclick="openUploadModal()" class="btn-primary mt-4">
                            <i class="fas fa-upload"></i>
                            Subir Primer Documento
                        </button>
                    </div>
                `;
                return;
            }

            // Group by document type
            const grouped = {};
            documents.forEach(doc => {
                if (!grouped[doc.document_type]) {
                    grouped[doc.document_type] = [];
                }
                grouped[doc.document_type].push(doc);
            });

            let html = '';
            for (const [type, docs] of Object.entries(grouped)) {
                html += `
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-white mb-3 flex items-center gap-2">
                            <i class="fas fa-folder text-yellow-400"></i>
                            ${type}
                            <span class="text-sm text-slate-400 font-normal">(${docs.length})</span>
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            ${docs.map(doc => createDocumentCard(doc)).join('')}
                        </div>
                    </div>
                `;
            }
            
            container.innerHTML = html;
        }

        function createDocumentCard(doc) {
            const icon = getFileIcon(doc.file_extension);
            const color = getFileColor(doc.file_extension);
            const date = new Date(doc.uploaded_at);
            const canPreview = isPreviewable(doc.file_extension);
            
            return `
                <div class="document-card bg-slate-800/50 rounded-lg p-4 border border-slate-700">
                    <div class="flex items-start gap-3 mb-3">
                        <div class="file-icon" style="background: ${color};">
                            <i class="${icon} text-white"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h4 class="text-white font-semibold text-sm truncate" title="${doc.document_name}">
                                ${doc.document_name}
                            </h4>
                            <p class="text-slate-400 text-xs">${formatFileSize(doc.file_size)}</p>
                            <p class="text-slate-400 text-xs">${formatDate(date)}</p>
                        </div>
                    </div>
                    ${doc.description ? `<p class="text-slate-300 text-sm mb-3">${doc.description}</p>` : ''}
                    <div class="flex gap-2">
                        ${canPreview ? `
                            <button onclick="previewDocument(${doc.id}, '${doc.document_name}', '${doc.file_extension}')" class="btn-primary text-xs flex-1">
                                <i class="fas fa-eye"></i>
                                Ver
                            </button>
                        ` : ''}
                        <a href="download_employee_document.php?id=${doc.id}" class="btn-primary text-xs ${canPreview ? 'px-3' : 'flex-1'} text-center">
                            <i class="fas fa-download"></i>
                            ${canPreview ? '' : 'Descargar'}
                        </a>
                        <button onclick="deleteDocument(${doc.id}, '${doc.document_name}')" class="btn-secondary text-xs px-3" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
        }

        function isPreviewable(extension) {
            const previewableTypes = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'txt'];
            return previewableTypes.includes(extension.toLowerCase());
        }

        function getFileIcon(extension) {
            const icons = {
                'pdf': 'fas fa-file-pdf',
                'doc': 'fas fa-file-word',
                'docx': 'fas fa-file-word',
                'xls': 'fas fa-file-excel',
                'xlsx': 'fas fa-file-excel',
                'jpg': 'fas fa-file-image',
                'jpeg': 'fas fa-file-image',
                'png': 'fas fa-file-image',
                'gif': 'fas fa-file-image',
                'zip': 'fas fa-file-archive',
                'rar': 'fas fa-file-archive',
                'txt': 'fas fa-file-alt'
            };
            return icons[extension.toLowerCase()] || 'fas fa-file';
        }

        function getFileColor(extension) {
            const colors = {
                'pdf': 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)',
                'doc': 'linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)',
                'docx': 'linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)',
                'xls': 'linear-gradient(135deg, #10b981 0%, #059669 100%)',
                'xlsx': 'linear-gradient(135deg, #10b981 0%, #059669 100%)',
                'jpg': 'linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%)',
                'jpeg': 'linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%)',
                'png': 'linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%)',
                'zip': 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)',
                'rar': 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)'
            };
            return colors[extension.toLowerCase()] || 'linear-gradient(135deg, #64748b 0%, #475569 100%)';
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
        }

        function formatDate(date) {
            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            return date.toLocaleDateString('es-ES', options);
        }

        function filterDocuments() {
            const typeFilter = document.getElementById('filterType').value.toLowerCase();
            const searchFilter = document.getElementById('searchDoc').value.toLowerCase();
            
            const filtered = allDocuments.filter(doc => {
                const matchesType = !typeFilter || doc.document_type.toLowerCase() === typeFilter;
                const matchesSearch = !searchFilter || 
                    doc.document_name.toLowerCase().includes(searchFilter) ||
                    (doc.description && doc.description.toLowerCase().includes(searchFilter));
                return matchesType && matchesSearch;
            });
            
            displayDocuments(filtered);
        }

        // Upload Modal Functions
        function openUploadModal() {
            document.getElementById('uploadModal').classList.remove('hidden');
            document.getElementById('uploadForm').reset();
            clearFile();
        }

        function closeUploadModal() {
            document.getElementById('uploadModal').classList.add('hidden');
        }

        // File Upload Handling
        const uploadZone = document.getElementById('uploadZone');
        const fileInput = document.getElementById('document_file');

        uploadZone.addEventListener('click', () => fileInput.click());

        uploadZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadZone.classList.add('dragover');
        });

        uploadZone.addEventListener('dragleave', () => {
            uploadZone.classList.remove('dragover');
        });

        uploadZone.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadZone.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                showFileInfo(e.dataTransfer.files[0]);
            }
        });

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length) {
                showFileInfo(e.target.files[0]);
            }
        });

        function showFileInfo(file) {
            document.getElementById('fileName').textContent = file.name;
            document.getElementById('fileSize').textContent = formatFileSize(file.size);
            document.getElementById('fileInfo').classList.remove('hidden');
            uploadZone.style.display = 'none';
        }

        function clearFile() {
            fileInput.value = '';
            document.getElementById('fileInfo').classList.add('hidden');
            uploadZone.style.display = 'block';
        }

        // Form Submission
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const messageDiv = document.getElementById('uploadMessage');
            
            messageDiv.className = 'status-banner mb-4';
            messageDiv.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Subiendo documento...';
            messageDiv.classList.remove('hidden');
            
            fetch('upload_employee_document.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    messageDiv.className = 'status-banner success mb-4';
                    messageDiv.innerHTML = '<i class="fas fa-check-circle mr-2"></i>' + data.message;
                    
                    setTimeout(() => {
                        closeUploadModal();
                        loadDocuments();
                    }, 1500);
                } else {
                    messageDiv.className = 'status-banner error mb-4';
                    messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>' + data.error;
                }
            })
            .catch(error => {
                messageDiv.className = 'status-banner error mb-4';
                messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>Error: ' + error.message;
            });
        });

        function deleteDocument(id, name) {
            if (!confirm(`¿Estás seguro de que deseas eliminar "${name}"?\n\nEsta acción no se puede deshacer.`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('id', id);
            
            fetch('delete_employee_document.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadDocuments();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error al eliminar: ' + error.message);
            });
        }

        function showError(message) {
            document.getElementById('documentsContainer').innerHTML = `
                <div class="text-center py-8">
                    <i class="fas fa-exclamation-triangle text-4xl text-red-400 mb-4"></i>
                    <p class="text-slate-400">${message}</p>
                </div>
            `;
        }

        // Preview functionality
        function previewDocument(id, name, extension) {
            const modal = document.getElementById('previewModal');
            const title = document.getElementById('previewTitle');
            const content = document.getElementById('previewContent');
            const downloadLink = document.getElementById('previewDownload');
            
            title.textContent = name;
            downloadLink.href = `download_employee_document.php?id=${id}`;
            downloadLink.download = name;
            
            modal.classList.remove('hidden');
            content.innerHTML = '<i class="fas fa-spinner fa-spin text-4xl text-blue-500"></i>';
            
            const previewUrl = `preview_employee_document.php?id=${id}`;
            const ext = extension.toLowerCase();
            
            // Handle different file types
            if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(ext)) {
                // Image preview
                content.innerHTML = `
                    <img src="${previewUrl}" 
                         alt="${name}" 
                         class="max-w-full max-h-full object-contain"
                         onerror="this.parentElement.innerHTML='<p class=text-red-500>Error al cargar la imagen</p>'">
                `;
            } else if (ext === 'pdf') {
                // PDF preview
                content.innerHTML = `
                    <iframe src="${previewUrl}" 
                            class="w-full h-full border-0"
                            onerror="this.parentElement.innerHTML='<p class=text-red-500>Error al cargar el PDF</p>'">
                    </iframe>
                `;
            } else if (ext === 'txt') {
                // Text preview
                fetch(previewUrl)
                    .then(response => response.text())
                    .then(text => {
                        content.innerHTML = `
                            <pre class="p-4 text-left overflow-auto w-full h-full text-sm text-gray-800 whitespace-pre-wrap">${escapeHtml(text)}</pre>
                        `;
                    })
                    .catch(error => {
                        content.innerHTML = '<p class="text-red-500">Error al cargar el archivo</p>';
                    });
            } else {
                content.innerHTML = '<p class="text-gray-600">Vista previa no disponible para este tipo de archivo</p>';
            }
        }

        function closePreviewModal() {
            document.getElementById('previewModal').classList.add('hidden');
            document.getElementById('previewContent').innerHTML = '<i class="fas fa-spinner fa-spin text-4xl text-blue-500"></i>';
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close preview modal when clicking outside
        document.getElementById('previewModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closePreviewModal();
            }
        });

        // Keyboard shortcut to close preview (ESC key)
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const previewModal = document.getElementById('previewModal');
                const uploadModal = document.getElementById('uploadModal');
                if (!previewModal.classList.contains('hidden')) {
                    closePreviewModal();
                } else if (!uploadModal.classList.contains('hidden')) {
                    closeUploadModal();
                }
            }
        });
    </script>

    <?php include '../footer.php'; ?>
</body>
</html>
