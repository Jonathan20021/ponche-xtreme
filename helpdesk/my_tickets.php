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

<div class="helpdesk-wrapper">
    <div class="helpdesk-container">
        <div class="page-header">
            <div class="header-content">
                <div class="header-title">
                    <div class="header-icon"><i class="fas fa-ticket-alt"></i></div>
                    <div class="header-text">
                        <h1>Mis Tickets de Soporte</h1>
                        <p>Gestiona y da seguimiento a todas tus solicitudes de ayuda</p>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="create_ticket.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Crear Ticket
                    </a>
                </div>
            </div>
        </div>

        <div class="stats-grid" id="statsGrid"></div>
        <div class="filters-section" id="filtersSection"></div>
        <div class="tickets-section" id="ticketsSection"></div>
    </div>
</div>

<div id="ticketModal" class="modal"></div>

<script src="helpdesk_scripts.js"></script>

</main>
</body>
</html>
