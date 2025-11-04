<?php
// Public application tracking page - no login required
session_start();
require_once 'db.php';

$application = null;
$error = null;
$application_code = $_GET['code'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($application_code)) {
    $code = $_POST['application_code'] ?? $application_code;
    $email = $_POST['email'] ?? '';
    
    try {
        // If email is provided, search with both code and email for security
        // If email is empty (URL access), search with code only
        if (!empty($email)) {
            // Search with code and email
            $stmt = $pdo->prepare("
                SELECT a.*, j.title as job_title, j.department, j.location
                FROM job_applications a
                LEFT JOIN job_postings j ON a.job_posting_id = j.id
                WHERE a.application_code = :code AND a.email = :email
            ");
            $stmt->execute(['code' => $code, 'email' => $email]);
        } else {
            // Direct access with code only (from URL or manual without email)
            $stmt = $pdo->prepare("
                SELECT a.*, j.title as job_title, j.department, j.location
                FROM job_applications a
                LEFT JOIN job_postings j ON a.job_posting_id = j.id
                WHERE a.application_code = :code
            ");
            $stmt->execute(['code' => $code]);
        }
        
        $application = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$application) {
            $error = "No se encontró ninguna solicitud con los datos proporcionados.";
        } else {
            // Get status history
            $stmt = $pdo->prepare("
                SELECT sh.*, u.full_name as changed_by_name
                FROM application_status_history sh
                LEFT JOIN users u ON sh.changed_by = u.id
                WHERE sh.application_id = :id
                ORDER BY sh.changed_at DESC
            ");
            $stmt->execute(['id' => $application['id']]);
            $status_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get upcoming interviews
            $stmt = $pdo->prepare("
                SELECT * FROM recruitment_interviews
                WHERE application_id = :id AND status = 'scheduled' AND interview_date >= NOW()
                ORDER BY interview_date ASC
            ");
            $stmt->execute(['id' => $application['id']]);
            $upcoming_interviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get public comments (non-internal)
            $stmt = $pdo->prepare("
                SELECT c.*, u.full_name as user_name
                FROM application_comments c
                LEFT JOIN users u ON c.user_id = u.id
                WHERE c.application_id = :id AND c.is_internal = FALSE
                ORDER BY c.created_at DESC
            ");
            $stmt->execute(['id' => $application['id']]);
            $public_comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $error = "Error al buscar la solicitud.";
        error_log("Track application error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rastrear Solicitud - Ponche Xtreme</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-color: #2563eb;
            --secondary-color: #1e40af;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #3b82f6;
            --text-dark: #1f2937;
            --text-light: #6b7280;
            --bg-light: #f9fafb;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }

        .track-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        .search-card {
            background: white;
            border-radius: 20px;
            padding: 3rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .search-card h2 {
            color: var(--primary-color);
            font-weight: 700;
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .form-control {
            border-radius: 10px;
            border: 2px solid #e5e7eb;
            padding: 0.75rem;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.1);
        }

        .btn-track {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 10px;
            font-weight: 600;
            width: 100%;
        }

        .btn-track:hover {
            transform: scale(1.02);
            box-shadow: 0 5px 15px rgba(37, 99, 235, 0.4);
            color: white;
        }

        .status-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .status-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e5e7eb;
        }

        .status-badge {
            padding: 0.75rem 1.5rem;
            border-radius: 30px;
            font-weight: 600;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-new { background: #dbeafe; color: #1e40af; }
        .status-reviewing { background: #fef3c7; color: #92400e; }
        .status-shortlisted { background: #e0e7ff; color: #4338ca; }
        .status-interview_scheduled { background: #ddd6fe; color: #5b21b6; }
        .status-interviewed { background: #fce7f3; color: #9f1239; }
        .status-offer_extended { background: #d1fae5; color: #065f46; }
        .status-hired { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-withdrawn { background: #f3f4f6; color: #4b5563; }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-item {
            display: flex;
            align-items: start;
            gap: 1rem;
        }

        .info-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .info-content h6 {
            color: var(--text-light);
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            font-weight: 600;
        }

        .info-content p {
            color: var(--text-dark);
            font-size: 1rem;
            margin: 0;
            font-weight: 500;
        }

        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e5e7eb;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 2rem;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -2.5rem;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary-color);
            border: 3px solid white;
            box-shadow: 0 0 0 2px var(--primary-color);
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-date {
            color: var(--text-light);
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }

        .timeline-status {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .timeline-notes {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .interview-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }

        .interview-card h5 {
            margin-bottom: 1rem;
        }

        .interview-info {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .interview-info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .comment-card {
            background: var(--bg-light);
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .comment-author {
            font-weight: 600;
            color: var(--text-dark);
        }

        .comment-date {
            color: var(--text-light);
            font-size: 0.85rem;
        }

        .comment-text {
            color: var(--text-dark);
        }

        .section-title {
            color: var(--text-dark);
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-custom {
            border-radius: 10px;
            border: none;
            padding: 1rem 1.5rem;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: white;
            text-decoration: none;
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .back-link:hover {
            color: white;
            text-decoration: underline;
        }

        .progress-bar-custom {
            height: 8px;
            border-radius: 10px;
            background: #e5e7eb;
            margin: 1rem 0;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--success-color));
            transition: width 0.3s ease;
        }
    </style>
</head>
<body>
    <div class="track-container">
        <a href="careers.php" class="back-link">
            <i class="bi bi-arrow-left"></i> Volver a Oportunidades
        </a>

        <?php if (!$application): ?>
            <!-- Search Form -->
            <div class="search-card">
                <h2><i class="bi bi-search"></i> Rastrear Mi Solicitud</h2>
                <p class="text-center text-muted mb-4">Ingresa tu código de solicitud y correo electrónico para ver el estado de tu aplicación</p>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-custom">
                        <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Código de Solicitud</label>
                        <input type="text" class="form-control" name="application_code" placeholder="APP-XXXXXXXX-2025" required>
                        <small class="text-muted">El código que recibiste al enviar tu solicitud</small>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Correo Electrónico (Opcional)</label>
                        <input type="email" class="form-control" name="email" placeholder="tu@email.com">
                        <small class="text-muted">Para mayor seguridad, puedes ingresar tu email</small>
                    </div>
                    <button type="submit" class="btn btn-track">
                        <i class="bi bi-search"></i> Buscar Solicitud
                    </button>
                </form>
            </div>
        <?php else: ?>
            <!-- Application Status Display -->
            <div class="status-card">
                <div class="status-header">
                    <div>
                        <h3 class="mb-1"><?php echo htmlspecialchars($application['first_name'] . ' ' . $application['last_name']); ?></h3>
                        <p class="text-muted mb-0">Código: <strong><?php echo htmlspecialchars($application['application_code']); ?></strong></p>
                    </div>
                    <span class="status-badge status-<?php echo $application['status']; ?>">
                        <i class="bi bi-circle-fill"></i>
                        <?php 
                            $statuses = [
                                'new' => 'Nueva',
                                'reviewing' => 'En Revisión',
                                'shortlisted' => 'Preseleccionado',
                                'interview_scheduled' => 'Entrevista Agendada',
                                'interviewed' => 'Entrevistado',
                                'offer_extended' => 'Oferta Extendida',
                                'hired' => 'Contratado',
                                'rejected' => 'Rechazado',
                                'withdrawn' => 'Retirado'
                            ];
                            echo $statuses[$application['status']];
                        ?>
                    </span>
                </div>

                <!-- Application Info -->
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-briefcase"></i>
                        </div>
                        <div class="info-content">
                            <h6>Posición</h6>
                            <p><?php echo htmlspecialchars($application['job_title']); ?></p>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-building"></i>
                        </div>
                        <div class="info-content">
                            <h6>Departamento</h6>
                            <p><?php echo htmlspecialchars($application['department']); ?></p>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-calendar"></i>
                        </div>
                        <div class="info-content">
                            <h6>Fecha de Aplicación</h6>
                            <p><?php echo date('d/m/Y', strtotime($application['applied_date'])); ?></p>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div class="info-content">
                            <h6>Última Actualización</h6>
                            <p><?php echo date('d/m/Y H:i', strtotime($application['last_updated'])); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Progress Bar -->
                <?php
                    $progress_map = [
                        'new' => 15,
                        'reviewing' => 30,
                        'shortlisted' => 50,
                        'interview_scheduled' => 65,
                        'interviewed' => 80,
                        'offer_extended' => 95,
                        'hired' => 100,
                        'rejected' => 100,
                        'withdrawn' => 100
                    ];
                    $progress = $progress_map[$application['status']];
                ?>
                <div class="progress-bar-custom">
                    <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                </div>
                <p class="text-center text-muted small mb-0">Progreso del proceso de reclutamiento: <?php echo $progress; ?>%</p>
            </div>

            <!-- Upcoming Interviews -->
            <?php if (!empty($upcoming_interviews)): ?>
                <div class="status-card">
                    <h4 class="section-title">
                        <i class="bi bi-calendar-event"></i> Próximas Entrevistas
                    </h4>
                    <?php foreach ($upcoming_interviews as $interview): ?>
                        <div class="interview-card">
                            <h5>
                                <i class="bi bi-camera-video"></i>
                                <?php 
                                    $interview_types = [
                                        'phone_screening' => 'Llamada de Filtro',
                                        'technical' => 'Entrevista Técnica',
                                        'hr' => 'Entrevista de RRHH',
                                        'manager' => 'Entrevista con Gerente',
                                        'final' => 'Entrevista Final',
                                        'other' => 'Otra Entrevista'
                                    ];
                                    echo $interview_types[$interview['interview_type']];
                                ?>
                            </h5>
                            <div class="interview-info">
                                <div class="interview-info-item">
                                    <i class="bi bi-calendar3"></i>
                                    <span><?php echo date('d/m/Y', strtotime($interview['interview_date'])); ?></span>
                                </div>
                                <div class="interview-info-item">
                                    <i class="bi bi-clock"></i>
                                    <span><?php echo date('H:i', strtotime($interview['interview_date'])); ?> (<?php echo $interview['duration_minutes']; ?> min)</span>
                                </div>
                                <?php if ($interview['location']): ?>
                                    <div class="interview-info-item">
                                        <i class="bi bi-geo-alt"></i>
                                        <span><?php echo htmlspecialchars($interview['location']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($interview['meeting_link']): ?>
                                    <div class="interview-info-item">
                                        <i class="bi bi-link-45deg"></i>
                                        <a href="<?php echo htmlspecialchars($interview['meeting_link']); ?>" target="_blank" style="color: white; text-decoration: underline;">Unirse a la reunión</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($interview['notes']): ?>
                                <div class="mt-3">
                                    <strong>Notas:</strong> <?php echo nl2br(htmlspecialchars($interview['notes'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Public Comments -->
            <?php if (!empty($public_comments)): ?>
                <div class="status-card">
                    <h4 class="section-title">
                        <i class="bi bi-chat-left-text"></i> Mensajes del Equipo de RRHH
                    </h4>
                    <?php foreach ($public_comments as $comment): ?>
                        <div class="comment-card">
                            <div class="comment-header">
                                <span class="comment-author">
                                    <i class="bi bi-person-circle"></i>
                                    <?php echo htmlspecialchars($comment['user_name'] ?? 'Recursos Humanos'); ?>
                                </span>
                                <span class="comment-date"><?php echo date('d/m/Y H:i', strtotime($comment['created_at'])); ?></span>
                            </div>
                            <p class="comment-text"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Status History -->
            <?php if (!empty($status_history)): ?>
                <div class="status-card">
                    <h4 class="section-title">
                        <i class="bi bi-clock-history"></i> Historial de Estados
                    </h4>
                    <div class="timeline">
                        <?php foreach ($status_history as $history): ?>
                            <div class="timeline-item">
                                <div class="timeline-date">
                                    <?php echo date('d/m/Y H:i', strtotime($history['changed_at'])); ?>
                                </div>
                                <div class="timeline-status">
                                    <?php echo $statuses[$history['new_status']] ?? $history['new_status']; ?>
                                </div>
                                <?php if ($history['notes']): ?>
                                    <div class="timeline-notes">
                                        <?php echo htmlspecialchars($history['notes']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Help Section -->
            <div class="status-card">
                <h4 class="section-title">
                    <i class="bi bi-question-circle"></i> ¿Necesitas Ayuda?
                </h4>
                <p class="text-muted">Si tienes preguntas sobre tu solicitud, por favor contacta a nuestro equipo de Recursos Humanos.</p>
                <div class="d-flex gap-3 flex-wrap">
                    <a href="mailto:rrhh@empresa.com" class="btn btn-outline-primary">
                        <i class="bi bi-envelope"></i> Enviar Correo
                    </a>
                    <button class="btn btn-outline-secondary" onclick="window.print()">
                        <i class="bi bi-printer"></i> Imprimir Estado
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
