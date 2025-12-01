<?php
/**
 * Payroll Email Functions
 * 
 * Functions for sending individual payroll slips via email
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/email_functions.php';

/**
 * Send payroll slip emails to all employees in a period
 * 
 * @param PDO $pdo Database connection
 * @param int $periodId Payroll period ID
 * @return array Result with 'success' boolean and 'message' string
 */
function sendPayrollSlipEmails(PDO $pdo, int $periodId): array {
    try {
        // Get period data
        $periodStmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
        $periodStmt->execute([$periodId]);
        $period = $periodStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$period) {
            return ['success' => false, 'message' => 'Período de nómina no encontrado'];
        }
        
        // Get employees with payroll records and email addresses
        $employeesStmt = $pdo->prepare("
            SELECT pr.*, 
                   e.first_name, e.last_name, e.employee_code, e.identification_number, 
                   e.position, e.email, e.employment_status,
                   d.name as department_name
            FROM payroll_records pr
            JOIN employees e ON e.id = pr.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE pr.payroll_period_id = ? AND e.email IS NOT NULL AND e.email != ''
            ORDER BY e.last_name, e.first_name
        ");
        $employeesStmt->execute([$periodId]);
        $employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($employees)) {
            return ['success' => false, 'message' => 'No se encontraron empleados con direcciones de correo válidas'];
        }
        
        $sentCount = 0;
        $errorCount = 0;
        $errors = [];
        
        foreach ($employees as $employee) {
            // Check if email was already sent
            if (checkPayrollEmailSent($pdo, $periodId, $employee['employee_id'])) {
                continue; // Skip if already sent
            }
            
            $result = sendIndividualPayrollSlip($pdo, $periodId, $employee['employee_id']);
            
            if ($result['success']) {
                $sentCount++;
                // Log the email send
                logPayrollEmailSent($pdo, $periodId, $employee['employee_id'], $employee['email']);
            } else {
                $errorCount++;
                $errors[] = $employee['first_name'] . ' ' . $employee['last_name'] . ': ' . $result['message'];
            }
        }
        
        $message = "Volantes enviados: $sentCount";
        if ($errorCount > 0) {
            $message .= ", Errores: $errorCount";
            if (!empty($errors)) {
                $message .= " (" . implode(', ', array_slice($errors, 0, 3)) . ")";
            }
        }
        
        return [
            'success' => $sentCount > 0,
            'message' => $message,
            'sent_count' => $sentCount,
            'error_count' => $errorCount
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error al enviar volantes: ' . $e->getMessage()
        ];
    }
}

/**
 * Send individual payroll slip email
 * 
 * @param PDO $pdo Database connection
 * @param int $periodId Payroll period ID
 * @param int $employeeId Employee ID
 * @return array Result with 'success' boolean and 'message' string
 */
function sendIndividualPayrollSlip(PDO $pdo, int $periodId, int $employeeId): array {
    try {
        // Load email configuration
        $config = require __DIR__ . '/../config/email_config.php';
        
        // Get payroll data
        $payrollData = getEmployeePayrollData($pdo, $periodId, $employeeId);
        
        if (!$payrollData) {
            return ['success' => false, 'message' => 'Datos de nómina no encontrados'];
        }
        
        if (empty($payrollData['email'])) {
            return ['success' => false, 'message' => 'Empleado sin dirección de correo'];
        }
        
        // Validate email format
        if (!filter_var($payrollData['email'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Dirección de correo inválida'];
        }
        
        // Create PHPMailer instance
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = $config['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp_username'];
        $mail->Password = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port = $config['smtp_port'];
        $mail->CharSet = $config['charset'];
        
        // Recipients
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($payrollData['email'], $payrollData['employee_name']);
        
        // Add reply-to if configured
        if (!empty($config['reply_to_email'])) {
            $mail->addReplyTo($config['reply_to_email'], $config['reply_to_name']);
        }
        
        // Generate payroll slip HTML
        $htmlContent = generatePayrollSlipHTML($payrollData);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = "Volante de Nómina - {$payrollData['period_name']} - {$payrollData['employee_name']}";
        $mail->Body = $htmlContent;
        
        // Plain text alternative
        $mail->AltBody = generatePayrollSlipPlainText($payrollData);
        
        // Send email
        $mail->send();
        
        return [
            'success' => true,
            'message' => 'Volante enviado exitosamente a ' . $payrollData['email']
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error al enviar email: ' . $e->getMessage()
        ];
    }
}

/**
 * Get employee payroll data for email
 * 
 * @param PDO $pdo Database connection
 * @param int $periodId Payroll period ID
 * @param int $employeeId Employee ID
 * @return array|null Payroll data or null if not found
 */
function getEmployeePayrollData(PDO $pdo, int $periodId, int $employeeId): ?array {
    $stmt = $pdo->prepare("
        SELECT pr.*, 
               pp.name as period_name, pp.start_date, pp.end_date, pp.payment_date,
               e.first_name, e.last_name, e.employee_code, e.identification_number, 
               e.position, e.email, e.employment_status, e.hire_date,
               d.name as department_name,
               u.hourly_rate, u.monthly_salary, u.overtime_multiplier
        FROM payroll_records pr
        JOIN payroll_periods pp ON pp.id = pr.payroll_period_id
        JOIN employees e ON e.id = pr.employee_id
        JOIN users u ON u.id = e.user_id
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE pr.payroll_period_id = ? AND pr.employee_id = ?
    ");
    
    $stmt->execute([$periodId, $employeeId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($data) {
        $data['employee_name'] = $data['first_name'] . ' ' . $data['last_name'];
        $data['period_formatted'] = date('d/m/Y', strtotime($data['start_date'])) . ' - ' . date('d/m/Y', strtotime($data['end_date']));
        $data['payment_date_formatted'] = date('d/m/Y', strtotime($data['payment_date']));
    }
    
    return $data ?: null;
}

/**
 * Generate HTML content for payroll slip email
 * 
 * @param array $data Payroll data
 * @return string HTML content
 */
function generatePayrollSlipHTML(array $data): string {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Volante de Nómina</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333;
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
                background-color: #f4f4f4;
            }
            .container {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 0 20px rgba(0,0,0,0.1);
            }
            .header {
                text-align: center;
                border-bottom: 3px solid #2563eb;
                padding-bottom: 20px;
                margin-bottom: 30px;
            }
            .header h1 {
                color: #1e40af;
                margin: 0;
                font-size: 24px;
            }
            .header p {
                color: #666;
                margin: 5px 0;
            }
            .employee-info {
                background: #f8fafc;
                padding: 20px;
                border-radius: 8px;
                margin-bottom: 25px;
                border-left: 4px solid #2563eb;
            }
            .employee-info h3 {
                margin: 0 0 15px 0;
                color: #1e40af;
            }
            .info-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            .info-item {
                display: flex;
                justify-content: space-between;
                padding: 5px 0;
            }
            .info-label {
                font-weight: bold;
                color: #374151;
            }
            .info-value {
                color: #6b7280;
            }
            .payroll-table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
                background: white;
            }
            .payroll-table th {
                background: #2563eb;
                color: white;
                padding: 12px;
                text-align: left;
                font-weight: bold;
            }
            .payroll-table td {
                padding: 12px;
                border-bottom: 1px solid #e5e7eb;
            }
            .payroll-table tr:nth-child(even) {
                background: #f9fafb;
            }
            .amount {
                text-align: right;
                font-weight: bold;
            }
            .positive {
                color: #059669;
            }
            .negative {
                color: #dc2626;
            }
            .total-row {
                background: #dbeafe !important;
                font-weight: bold;
                font-size: 16px;
            }
            .total-row td {
                border-top: 2px solid #2563eb;
                padding: 15px 12px;
            }
            .footer {
                text-align: center;
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #e5e7eb;
                color: #6b7280;
                font-size: 12px;
            }
            .signature-section {
                margin-top: 40px;
                text-align: center;
            }
            .signature-line {
                border-top: 1px solid #333;
                width: 200px;
                margin: 50px auto 10px;
                padding-top: 5px;
                font-size: 12px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>VOLANTE DE NÓMINA</h1>
                <p><strong><?= htmlspecialchars($data['period_name']) ?></strong></p>
                <p>Período: <?= htmlspecialchars($data['period_formatted']) ?></p>
                <p>Fecha de Pago: <?= htmlspecialchars($data['payment_date_formatted']) ?></p>
            </div>

            <div class="employee-info">
                <h3>Información del Empleado</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Nombre:</span>
                        <span class="info-value"><?= htmlspecialchars($data['employee_name']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Código:</span>
                        <span class="info-value"><?= htmlspecialchars($data['employee_code']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Cédula:</span>
                        <span class="info-value"><?= htmlspecialchars($data['identification_number']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Posición:</span>
                        <span class="info-value"><?= htmlspecialchars($data['position']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Departamento:</span>
                        <span class="info-value"><?= htmlspecialchars($data['department_name'] ?: 'N/A') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Horas Trabajadas:</span>
                        <span class="info-value"><?= number_format($data['total_hours'], 1) ?></span>
                    </div>
                    <?php if (!empty($data['hourly_rate']) && $data['hourly_rate'] > 0): ?>
                    <div class="info-item">
                        <span class="info-label">Tarifa por Hora:</span>
                        <span class="info-value"><?= formatDOP($data['hourly_rate']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($data['monthly_salary']) && $data['monthly_salary'] > 0): ?>
                    <div class="info-item">
                        <span class="info-label">Salario Mensual:</span>
                        <span class="info-value"><?= formatDOP($data['monthly_salary']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($data['overtime_multiplier']) && $data['overtime_multiplier'] > 1): ?>
                    <div class="info-item">
                        <span class="info-label">Multiplicador H. Extra:</span>
                        <span class="info-value"><?= number_format($data['overtime_multiplier'], 2) ?>x</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <table class="payroll-table">
                <thead>
                    <tr>
                        <th>Concepto</th>
                        <th class="amount">Monto</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Salario Base</td>
                        <td class="amount positive"><?= formatDOP($data['base_salary']) ?></td>
                    </tr>
                    <?php if ($data['overtime_amount'] > 0): ?>
                    <tr>
                        <td>Horas Extra (<?= number_format($data['overtime_hours'], 1) ?> hrs)</td>
                        <td class="amount positive"><?= formatDOP($data['overtime_amount']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($data['bonuses'] > 0): ?>
                    <tr>
                        <td>Bonificaciones</td>
                        <td class="amount positive"><?= formatDOP($data['bonuses']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($data['commissions'] > 0): ?>
                    <tr>
                        <td>Comisiones</td>
                        <td class="amount positive"><?= formatDOP($data['commissions']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($data['other_income'] > 0): ?>
                    <tr>
                        <td>Otros Ingresos</td>
                        <td class="amount positive"><?= formatDOP($data['other_income']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr style="background: #f3f4f6; font-weight: bold;">
                        <td>SALARIO BRUTO</td>
                        <td class="amount positive"><?= formatDOP($data['gross_salary']) ?></td>
                    </tr>
                    <tr>
                        <td>AFP (Empleado)</td>
                        <td class="amount negative">-<?= formatDOP($data['afp_employee']) ?></td>
                    </tr>
                    <tr>
                        <td>SFS (Empleado)</td>
                        <td class="amount negative">-<?= formatDOP($data['sfs_employee']) ?></td>
                    </tr>
                    <?php if ($data['isr'] > 0): ?>
                    <tr>
                        <td>ISR (Impuesto Sobre la Renta)
                            <div style="font-size: 10px; color: #6b7280; margin-top: 2px;">
                                <?php 
                                $annualSalary = $data['gross_salary'] * 12;
                                if ($annualSalary <= 416220.00) {
                                    echo "Exento (hasta RD$ 416,220 anuales)";
                                } elseif ($annualSalary <= 624329.00) {
                                    echo "15% sobre excedente de RD$ 416,220";
                                } elseif ($annualSalary <= 867123.00) {
                                    echo "RD$ 31,216 + 20% sobre excedente de RD$ 624,329";
                                } else {
                                    echo "RD$ 79,775 + 25% sobre excedente de RD$ 867,123";
                                }
                                ?>
                                <br>Salario anual proyectado: <?= formatDOP($annualSalary) ?>
                            </div>
                        </td>
                        <td class="amount negative">-<?= formatDOP($data['isr']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($data['other_deductions'] > 0): ?>
                    <tr>
                        <td>Otras Deducciones</td>
                        <td class="amount negative">-<?= formatDOP($data['other_deductions']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr style="background: #fee2e2; font-weight: bold;">
                        <td>TOTAL DEDUCCIONES</td>
                        <td class="amount negative">-<?= formatDOP($data['total_deductions']) ?></td>
                    </tr>
                    <tr class="total-row">
                        <td>SALARIO NETO</td>
                        <td class="amount positive"><?= formatDOP($data['net_salary']) ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="signature-section">
                <div class="signature-line">
                    Firma del Empleado
                </div>
            </div>

            <div class="footer">
                <p>Este documento es generado automáticamente por el sistema de nómina.</p>
                <p>Generado el <?= date('d/m/Y H:i:s') ?></p>
                <p>Para consultas, contacte al departamento de Recursos Humanos.</p>
            </div>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * Generate plain text version of payroll slip
 * 
 * @param array $data Payroll data
 * @return string Plain text content
 */
function generatePayrollSlipPlainText(array $data): string {
    $text = "VOLANTE DE NÓMINA\n";
    $text .= "==================\n\n";
    $text .= "Período: {$data['period_name']}\n";
    $text .= "Fechas: {$data['period_formatted']}\n";
    $text .= "Fecha de Pago: {$data['payment_date_formatted']}\n\n";
    
    $text .= "EMPLEADO:\n";
    $text .= "---------\n";
    $text .= "Nombre: {$data['employee_name']}\n";
    $text .= "Código: {$data['employee_code']}\n";
    $text .= "Cédula: {$data['identification_number']}\n";
    $text .= "Posición: {$data['position']}\n";
    $text .= "Departamento: " . ($data['department_name'] ?: 'N/A') . "\n";
    $text .= "Horas Trabajadas: " . number_format($data['total_hours'], 1) . "\n";
    
    // Add salary information for transparency
    if (!empty($data['hourly_rate']) && $data['hourly_rate'] > 0) {
        $text .= "Tarifa por Hora: " . formatDOP($data['hourly_rate']) . "\n";
    }
    if (!empty($data['monthly_salary']) && $data['monthly_salary'] > 0) {
        $text .= "Salario Mensual: " . formatDOP($data['monthly_salary']) . "\n";
    }
    if (!empty($data['overtime_multiplier']) && $data['overtime_multiplier'] > 1) {
        $text .= "Multiplicador H. Extra: " . number_format($data['overtime_multiplier'], 2) . "x\n";
    }
    $text .= "\n";
    
    $text .= "DETALLE DE NÓMINA:\n";
    $text .= "------------------\n";
    $text .= "Salario Base: " . formatDOP($data['base_salary']) . "\n";
    
    if ($data['overtime_amount'] > 0) {
        $text .= "Horas Extra (" . number_format($data['overtime_hours'], 1) . " hrs): " . formatDOP($data['overtime_amount']) . "\n";
    }
    if ($data['bonuses'] > 0) {
        $text .= "Bonificaciones: " . formatDOP($data['bonuses']) . "\n";
    }
    if ($data['commissions'] > 0) {
        $text .= "Comisiones: " . formatDOP($data['commissions']) . "\n";
    }
    if ($data['other_income'] > 0) {
        $text .= "Otros Ingresos: " . formatDOP($data['other_income']) . "\n";
    }
    
    $text .= "SALARIO BRUTO: " . formatDOP($data['gross_salary']) . "\n\n";
    
    $text .= "DEDUCCIONES:\n";
    $text .= "------------\n";
    $text .= "AFP (Empleado): -" . formatDOP($data['afp_employee']) . "\n";
    $text .= "SFS (Empleado): -" . formatDOP($data['sfs_employee']) . "\n";
    
    if ($data['isr'] > 0) {
        $text .= "ISR (Impuesto Sobre la Renta): -" . formatDOP($data['isr']) . "\n";
        
        // Add ISR calculation details for transparency
        $annualSalary = $data['gross_salary'] * 12;
        $text .= "  Salario anual proyectado: " . formatDOP($annualSalary) . "\n";
        $text .= "  Escala aplicada: ";
        if ($annualSalary <= 416220.00) {
            $text .= "Exento (hasta RD$ 416,220 anuales)\n";
        } elseif ($annualSalary <= 624329.00) {
            $text .= "15% sobre excedente de RD$ 416,220\n";
        } elseif ($annualSalary <= 867123.00) {
            $text .= "RD$ 31,216 + 20% sobre excedente de RD$ 624,329\n";
        } else {
            $text .= "RD$ 79,775 + 25% sobre excedente de RD$ 867,123\n";
        }
    }
    if ($data['other_deductions'] > 0) {
        $text .= "Otras Deducciones: -" . formatDOP($data['other_deductions']) . "\n";
    }
    
    $text .= "TOTAL DEDUCCIONES: -" . formatDOP($data['total_deductions']) . "\n\n";
    $text .= "SALARIO NETO: " . formatDOP($data['net_salary']) . "\n\n";
    
    $text .= "---\n";
    $text .= "Documento generado automáticamente\n";
    $text .= "Generado el " . date('d/m/Y H:i:s') . "\n";
    
    return $text;
}

/**
 * Check if payroll email was already sent
 * 
 * @param PDO $pdo Database connection
 * @param int $periodId Payroll period ID
 * @param int $employeeId Employee ID
 * @return bool True if email was sent
 */
function checkPayrollEmailSent(PDO $pdo, int $periodId, int $employeeId): bool {
    $stmt = $pdo->prepare("
        SELECT id FROM payroll_email_log 
        WHERE payroll_period_id = ? AND employee_id = ?
    ");
    $stmt->execute([$periodId, $employeeId]);
    return $stmt->fetch() !== false;
}

/**
 * Log payroll email sent
 * 
 * @param PDO $pdo Database connection
 * @param int $periodId Payroll period ID
 * @param int $employeeId Employee ID
 * @param string $email Email address
 */
function logPayrollEmailSent(PDO $pdo, int $periodId, int $employeeId, string $email): void {
    $stmt = $pdo->prepare("
        INSERT INTO payroll_email_log (payroll_period_id, employee_id, email_address, sent_at, sent_by)
        VALUES (?, ?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE sent_at = NOW(), sent_by = ?
    ");
    $stmt->execute([$periodId, $employeeId, $email, $_SESSION['user_id'], $_SESSION['user_id']]);
}

/**
 * Get payroll email statistics
 * 
 * @param PDO $pdo Database connection
 * @param int $periodId Payroll period ID
 * @return array Statistics
 */
function getPayrollEmailStats(PDO $pdo, int $periodId): array {
    // Total employees with payroll records
    $totalStmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM payroll_records pr
        JOIN employees e ON e.id = pr.employee_id
        WHERE pr.payroll_period_id = ?
    ");
    $totalStmt->execute([$periodId]);
    $total = $totalStmt->fetchColumn();
    
    // Employees with emails sent
    $sentStmt = $pdo->prepare("
        SELECT COUNT(*) as sent
        FROM payroll_email_log pel
        WHERE pel.payroll_period_id = ?
    ");
    $sentStmt->execute([$periodId]);
    $sent = $sentStmt->fetchColumn();
    
    // Employees without email addresses
    $noEmailStmt = $pdo->prepare("
        SELECT COUNT(*) as no_email
        FROM payroll_records pr
        JOIN employees e ON e.id = pr.employee_id
        WHERE pr.payroll_period_id = ? AND (e.email IS NULL OR e.email = '')
    ");
    $noEmailStmt->execute([$periodId]);
    $noEmail = $noEmailStmt->fetchColumn();
    
    $pending = $total - $sent - $noEmail;
    
    return [
        'total' => $total,
        'sent' => $sent,
        'pending' => max(0, $pending),
        'no_email' => $noEmail
    ];
}
?>
