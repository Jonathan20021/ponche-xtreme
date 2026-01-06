<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_job_postings', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

if (!isset($_GET['id'])) {
    header("Location: job_postings.php");
    exit;
}

$jobId = $_GET['id'];

// Fetch job details
$stmt = $pdo->prepare("SELECT * FROM job_postings WHERE id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    $_SESSION['error_message'] = "Vacante no encontrada.";
    header("Location: job_postings.php");
    exit;
}

require_once '../header.php';
?>

<link rel="stylesheet" href="../assets/css/recruitment.css">

<div class="container mx-auto px-4 py-8">
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
        <div>
            <h1 class="text-3xl font-bold text-white mb-2">
                <i class="fas fa-edit text-indigo-400 mr-3"></i>
                Editar Vacante
            </h1>
            <p class="text-slate-400">Modificar detalles de la vacante #<?php echo htmlspecialchars($job['id']); ?></p>
        </div>
        <div class="flex gap-3">
            <a href="job_postings.php" class="btn-secondary">
                <i class="fas fa-arrow-left"></i> Cancelar
            </a>
        </div>
    </div>

    <div class="glass-card max-w-4xl mx-auto">
        <form action="save_job_posting.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?php echo htmlspecialchars($job['id']); ?>">
            
            <div class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Título del Puesto *</label>
                        <input type="text" class="form-input w-full" name="title" value="<?php echo htmlspecialchars($job['title']); ?>" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Departamento *</label>
                        <input type="text" class="form-input w-full" name="department" value="<?php echo htmlspecialchars($job['department']); ?>" required>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Ubicación *</label>
                        <input type="text" class="form-input w-full" name="location" value="<?php echo htmlspecialchars($job['location']); ?>" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Tipo de Empleo *</label>
                        <select class="form-input w-full" name="employment_type" required>
                            <option value="full_time" <?php echo $job['employment_type'] === 'full_time' ? 'selected' : ''; ?>>Tiempo Completo</option>
                            <option value="part_time" <?php echo $job['employment_type'] === 'part_time' ? 'selected' : ''; ?>>Medio Tiempo</option>
                            <option value="contract" <?php echo $job['employment_type'] === 'contract' ? 'selected' : ''; ?>>Contrato</option>
                            <option value="internship" <?php echo $job['employment_type'] === 'internship' ? 'selected' : ''; ?>>Pasantía</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Descripción *</label>
                    <textarea class="form-input w-full" name="description" rows="5" required><?php echo htmlspecialchars($job['description']); ?></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Requisitos</label>
                    <textarea class="form-input w-full" name="requirements" rows="4"><?php echo htmlspecialchars($job['requirements']); ?></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">Responsabilidades</label>
                    <textarea class="form-input w-full" name="responsibilities" rows="4"><?php echo htmlspecialchars($job['responsibilities']); ?></textarea>
                </div>

                <div class="border border-slate-700 rounded-lg p-4 bg-slate-800/30">
                    <label class="block text-sm font-medium text-slate-300 mb-2">Banner de la Vacante</label>
                    
                    <?php 
                    $bannerPath = __DIR__ . '/../uploads/job_banners/job_' . $job['id'];
                    $bannerUrl = null;
                    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
                        if (file_exists($bannerPath . '.' . $ext)) {
                            $bannerUrl = '../uploads/job_banners/job_' . $job['id'] . '.' . $ext;
                            break;
                        }
                    }
                    ?>
                    
                    <?php if ($bannerUrl): ?>
                        <div class="mb-4">
                            <p class="text-xs text-slate-400 mb-2">Banner actual:</p>
                            <img src="<?php echo $bannerUrl; ?>?v=<?php echo time(); ?>" alt="Current Banner" class="h-32 rounded border border-slate-600">
                        </div>
                    <?php endif; ?>

                    <input type="file" class="form-input w-full file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:bg-indigo-600 file:text-white file:cursor-pointer" name="banner_image" accept="image/png, image/jpeg, image/webp">
                    <p class="text-xs text-slate-400 mt-2">Deja en blanco para mantener el banner actual. Formatos: JPG, PNG o WebP. Tamaño máx. 5MB.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Rango Salarial</label>
                        <input type="text" class="form-input w-full" name="salary_range" value="<?php echo htmlspecialchars($job['salary_range']); ?>" placeholder="RD$25,000 - RD$35,000 DOP">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-2">Fecha de Cierre</label>
                        <input type="date" class="form-input w-full" name="closing_date" value="<?php echo htmlspecialchars($job['closing_date']); ?>">
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3 mt-8 pt-4 border-t border-slate-700">
                <a href="job_postings.php" class="btn-secondary">Cancelar</a>
                <button type="submit" class="btn-primary">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../footer.php'; ?>
