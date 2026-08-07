<?php
/**
 * hr/employee_profile_actions.php
 *
 * Acciones que se disparan desde el perfil del colaborador:
 *   - add_warning        : registrar una amonestación
 *   - add_medical_leave  : registrar una licencia médica
 *   - assign_campaign    : asignar una campaña (puede tener varias a la vez), con
 *                          cambio de salario opcional y su fecha efectiva
 *   - end_campaign       : finalizar una asignación de campaña
 *   - change_compensation: cambiar el salario indicando desde qué día aplica
 *   - cancel_compensation_change : anular un cambio de salario aún pendiente
 *   - request_signature  : generar el enlace de firma electrónica de un documento
 *   - terminate          : registrar la salida con motivo y elegibilidad de recontratación
 *
 * Todo vuelve al perfil con un mensaje; no hay pantallas intermedias.
 */

session_start();
require_once '../db.php';
require_once '../lib/employee_record.php';
require_once '../lib/notifications.php';
require_once '../lib/compensation_history.php';

ensurePermission('hr_employees', '../unauthorized.php');

$employeeId = (int) ($_POST['employee_id'] ?? 0);
$action     = (string) ($_POST['action'] ?? '');
$userId     = (int) ($_SESSION['user_id'] ?? 0);

function profileBack(int $employeeId, string $message = '', bool $ok = true): void
{
    if ($message !== '') {
        $_SESSION[$ok ? 'profile_success' : 'profile_error'] = $message;
    }
    header('Location: employee_profile.php?id=' . $employeeId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $employeeId <= 0) {
    header('Location: employees.php');
    exit;
}

// El empleado tiene que existir; también se necesita su user_id para notificar.
$empStmt = $pdo->prepare("
    SELECT e.id, e.user_id, e.first_name, e.last_name, e.employee_code, e.campaign_id
    FROM employees e WHERE e.id = ?
");
$empStmt->execute([$employeeId]);
$employee = $empStmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) {
    header('Location: employees.php');
    exit;
}
$employeeName = trim($employee['first_name'] . ' ' . $employee['last_name']);

/**
 * Guarda un archivo subido dentro de uploads/ y devuelve la ruta relativa.
 */
function profileStoreUpload(string $field, string $subdir, int $employeeId): ?string
{
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return null;
    }
    if ((int) $_FILES[$field]['size'] > 10 * 1024 * 1024) {
        return null;
    }

    $dir = __DIR__ . '/../uploads/' . $subdir;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return null;
    }

    $name = $subdir . '_' . $employeeId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $name)) {
        return null;
    }

    return 'uploads/' . $subdir . '/' . $name;
}

/**
 * Arma la compensación NUEVA a partir del formulario, partiendo de la vigente:
 * lo que el formulario no manda, se queda como está. Los campos que no aplican al
 * tipo elegido se limpian para que la nómina no lea un monto viejo por error.
 *
 * @param array<string,mixed> $post
 * @param array<string,mixed> $current
 * @return array<string,mixed>
 */
function compensationFromPost(array $post, array $current): array
{
    $comp = $current;

    if (isset($post['compensation_type'])) {
        $comp['compensation_type'] = (string) $post['compensation_type'];
    }
    if (isset($post['preferred_currency'])) {
        $comp['preferred_currency'] = (string) $post['preferred_currency'];
    }
    foreach (['hourly_rate', 'hourly_rate_dop', 'monthly_salary', 'monthly_salary_dop', 'daily_salary_usd', 'daily_salary_dop'] as $field) {
        if (isset($post[$field]) && trim((string) $post[$field]) !== '') {
            $comp[$field] = (float) $post[$field];
        } elseif (isset($post[$field])) {
            $comp[$field] = 0.0;
        }
    }

    $comp = normalizeCompensation($comp);

    // Limpiar lo que no corresponde al tipo elegido.
    if ($comp['compensation_type'] === 'hourly') {
        $comp['monthly_salary'] = 0.0;
        $comp['monthly_salary_dop'] = 0.0;
        $comp['daily_salary_usd'] = 0.0;
        $comp['daily_salary_dop'] = 0.0;
    } elseif ($comp['compensation_type'] === 'fixed') {
        $comp['daily_salary_usd'] = 0.0;
        $comp['daily_salary_dop'] = 0.0;
    } elseif ($comp['compensation_type'] === 'daily') {
        $comp['monthly_salary'] = 0.0;
        $comp['monthly_salary_dop'] = 0.0;
    }

    return $comp;
}

/**
 * Mensaje humano para confirmar un cambio de salario: si la fecha ya pasó, dice
 * desde cuándo cuenta; si es futura, avisa que queda programado.
 */
function compensationChangeMessage(string $effectiveDate, array $newComp): string
{
    $label = formatCompensationLabel($newComp);
    $human = date('d/m/Y', strtotime($effectiveDate));

    if ($effectiveDate > date('Y-m-d')) {
        return "Salario programado: {$label} a partir del {$human}. Hasta ese día se sigue pagando el salario actual.";
    }
    return "Salario actualizado a {$label}, vigente desde el {$human}. Los días anteriores de la quincena se pagan con el salario anterior.";
}

try {
    switch ($action) {

        // ------------------------------------------------------------------
        case 'add_warning':
            $subject = trim((string) ($_POST['subject'] ?? ''));
            $incidentDate = (string) ($_POST['incident_date'] ?? '');
            if ($subject === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $incidentDate)) {
                profileBack($employeeId, 'Falta el asunto o la fecha del hecho.', false);
            }

            $labels = employeeWarningLabels();
            $type     = array_key_exists($_POST['warning_type'] ?? '', $labels['types']) ? $_POST['warning_type'] : 'VERBAL';
            $severity = array_key_exists($_POST['severity'] ?? '', $labels['severities']) ? $_POST['severity'] : 'LEVE';
            $suspension = ($_POST['suspension_days'] ?? '') !== '' ? (float) $_POST['suspension_days'] : null;

            $attachment = profileStoreUpload('attachment', 'warnings', $employeeId);

            $stmt = $pdo->prepare("
                INSERT INTO employee_warnings
                    (employee_id, warning_type, severity, subject, description, incident_date,
                     corrective_action, suspension_days, attachment, issued_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $employeeId, $type, $severity, $subject,
                trim((string) ($_POST['description'] ?? '')) ?: null,
                $incidentDate,
                trim((string) ($_POST['corrective_action'] ?? '')) ?: null,
                $suspension, $attachment, $userId ?: null,
            ]);

            profileBack($employeeId, 'Amonestación registrada en el expediente de ' . $employeeName . '.');
            break;

        // ------------------------------------------------------------------
        case 'add_medical_leave':
            $start = (string) ($_POST['start_date'] ?? '');
            $end   = (string) ($_POST['end_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                profileBack($employeeId, 'Las fechas de la licencia no son válidas.', false);
            }
            if ($end < $start) {
                profileBack($employeeId, 'La fecha final no puede ser anterior a la inicial.', false);
            }

            $totalDays = (new DateTime($start))->diff(new DateTime($end))->days + 1;
            $certificate = profileStoreUpload('medical_certificate_file', 'medical_leaves', $employeeId);

            $stmt = $pdo->prepare("
                INSERT INTO medical_leaves
                    (employee_id, user_id, leave_type, diagnosis, start_date, end_date, total_days,
                     is_paid, doctor_name, medical_center, medical_certificate_number,
                     medical_certificate_file, status, reviewed_by, reviewed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'APPROVED', ?, NOW())
            ");
            $stmt->execute([
                $employeeId,
                $employee['user_id'] ?: null,
                (string) ($_POST['leave_type'] ?? 'ENFERMEDAD'),
                trim((string) ($_POST['diagnosis'] ?? '')) ?: null,
                $start, $end, $totalDays,
                isset($_POST['is_paid']) ? 1 : 0,
                trim((string) ($_POST['doctor_name'] ?? '')) ?: null,
                trim((string) ($_POST['medical_center'] ?? '')) ?: null,
                trim((string) ($_POST['medical_certificate_number'] ?? '')) ?: null,
                $certificate,
                $userId ?: null,
            ]);

            // La licencia justifica las ausencias de esos días: el historial del
            // perfil y el reporte diario las tomarán como justificadas.
            profileBack($employeeId, "Licencia médica registrada ($totalDays día(s)). Las ausencias de ese período quedan justificadas.");
            break;

        // ------------------------------------------------------------------
        case 'assign_campaign':
            $campaignId = (int) ($_POST['campaign_id'] ?? 0);
            if ($campaignId <= 0) {
                profileBack($employeeId, 'Selecciona una campaña.', false);
            }

            // No duplicar una asignación vigente a la misma campaña
            $dup = $pdo->prepare("
                SELECT COUNT(*) FROM employee_campaigns
                WHERE employee_id = ? AND campaign_id = ? AND end_date IS NULL
            ");
            $dup->execute([$employeeId, $campaignId]);
            if ((int) $dup->fetchColumn() > 0) {
                profileBack($employeeId, 'El colaborador ya está asignado a esa campaña.', false);
            }

            $isPrimary = isset($_POST['is_primary']) ? 1 : 0;
            $startDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['start_date'] ?? '') ? $_POST['start_date'] : date('Y-m-d');

            $pdo->beginTransaction();
            try {
                if ($isPrimary) {
                    // Solo puede haber una principal; el resto queda como secundaria.
                    $pdo->prepare("UPDATE employee_campaigns SET is_primary = 0 WHERE employee_id = ?")
                        ->execute([$employeeId]);
                }

                $ins = $pdo->prepare("
                    INSERT INTO employee_campaigns (employee_id, campaign_id, is_primary, start_date, assigned_by)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $ins->execute([$employeeId, $campaignId, $isPrimary, $startDate, $userId ?: null]);

                // employees.campaign_id sigue siendo la campaña PRINCIPAL: los
                // monitores, la nómina y los reportes leen esa columna y no deben
                // cambiar de comportamiento por esta función.
                if ($isPrimary || empty($employee['campaign_id'])) {
                    $pdo->prepare("UPDATE employees SET campaign_id = ? WHERE id = ?")
                        ->execute([$campaignId, $employeeId]);
                    if (!$isPrimary) {
                        $pdo->prepare("UPDATE employee_campaigns SET is_primary = 1 WHERE employee_id = ? AND campaign_id = ? AND end_date IS NULL")
                            ->execute([$employeeId, $campaignId]);
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            // Cambio de salario que viene con la campaña. Se registra APARTE de la
            // transacción de arriba: si el salario falla, la campaña ya quedó
            // asignada y el mensaje lo dice, en vez de deshacer todo en silencio.
            $message = 'Campaña asignada.';
            if (!empty($_POST['change_salary']) && !empty($employee['user_id'])) {
                $effectiveDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['salary_effective_date'] ?? '')
                    ? $_POST['salary_effective_date']
                    : $startDate;

                $current = getCurrentCompensation($pdo, (int) $employee['user_id']);
                $newComp = compensationFromPost($_POST, $current);

                $campaignName = '';
                try {
                    $cst = $pdo->prepare("SELECT name FROM campaigns WHERE id = ?");
                    $cst->execute([$campaignId]);
                    $campaignName = (string) ($cst->fetchColumn() ?: '');
                } catch (Throwable $e) { /* el nombre es solo para el motivo */ }

                $changeId = recordCompensationChange(
                    $pdo,
                    (int) $employee['user_id'],
                    $newComp,
                    $effectiveDate,
                    [
                        'employee_id'          => $employeeId,
                        'campaign_id'          => $campaignId,
                        'previous_campaign_id' => $employee['campaign_id'] ? (int) $employee['campaign_id'] : null,
                        'source'               => 'campaign_change',
                        'reason'               => trim('Cambio de campaña' . ($campaignName !== '' ? ' a ' . $campaignName : '')),
                        'created_by'           => $userId ?: null,
                    ]
                );

                $message .= ' ' . ($changeId
                    ? compensationChangeMessage($effectiveDate, $newComp)
                    : 'El salario indicado es igual al vigente, no se registró cambio.');
            }

            profileBack($employeeId, $message);
            break;

        // ------------------------------------------------------------------
        case 'change_compensation':
            if (empty($employee['user_id'])) {
                profileBack($employeeId, 'Este colaborador no tiene usuario asociado; no se le puede fijar salario.', false);
            }

            $effectiveDate = (string) ($_POST['effective_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
                profileBack($employeeId, 'Indica desde qué fecha aplica el nuevo salario.', false);
            }

            $current = getCurrentCompensation($pdo, (int) $employee['user_id']);
            $newComp = compensationFromPost($_POST, $current);

            $changeId = recordCompensationChange(
                $pdo,
                (int) $employee['user_id'],
                $newComp,
                $effectiveDate,
                [
                    'employee_id' => $employeeId,
                    'campaign_id' => $employee['campaign_id'] ? (int) $employee['campaign_id'] : null,
                    'source'      => 'profile',
                    'reason'      => trim((string) ($_POST['reason'] ?? '')) ?: 'Cambio de salario',
                    'created_by'  => $userId ?: null,
                ]
            );

            if (!$changeId) {
                profileBack($employeeId, 'No se registró nada: el salario indicado es igual al que ya tenía en esa fecha.', false);
            }

            profileBack($employeeId, compensationChangeMessage($effectiveDate, $newComp));
            break;

        // ------------------------------------------------------------------
        case 'cancel_compensation_change':
            $changeId = (int) ($_POST['change_id'] ?? 0);
            if ($changeId <= 0 || !cancelCompensationChange($pdo, $changeId)) {
                profileBack($employeeId, 'Solo se pueden anular cambios de salario que aún no han entrado en vigencia.', false);
            }
            profileBack($employeeId, 'Cambio de salario programado anulado.');
            break;

        // ------------------------------------------------------------------
        case 'end_campaign':
            $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
            if ($assignmentId <= 0) {
                profileBack($employeeId, 'Asignación no válida.', false);
            }

            $rowStmt = $pdo->prepare("SELECT campaign_id, is_primary FROM employee_campaigns WHERE id = ? AND employee_id = ?");
            $rowStmt->execute([$assignmentId, $employeeId]);
            $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                profileBack($employeeId, 'Asignación no encontrada.', false);
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE employee_campaigns SET end_date = CURDATE() WHERE id = ?")
                    ->execute([$assignmentId]);

                // Si se cerró la principal, otra vigente toma el relevo para que
                // employees.campaign_id nunca quede apuntando a una campaña cerrada.
                if ((int) $row['is_primary'] === 1) {
                    $next = $pdo->prepare("
                        SELECT id, campaign_id FROM employee_campaigns
                        WHERE employee_id = ? AND end_date IS NULL
                        ORDER BY start_date DESC, id DESC LIMIT 1
                    ");
                    $next->execute([$employeeId]);
                    $nextRow = $next->fetch(PDO::FETCH_ASSOC);

                    if ($nextRow) {
                        $pdo->prepare("UPDATE employee_campaigns SET is_primary = 1 WHERE id = ?")->execute([$nextRow['id']]);
                        $pdo->prepare("UPDATE employees SET campaign_id = ? WHERE id = ?")->execute([$nextRow['campaign_id'], $employeeId]);
                    } else {
                        $pdo->prepare("UPDATE employees SET campaign_id = NULL WHERE id = ?")->execute([$employeeId]);
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            profileBack($employeeId, 'Asignación de campaña finalizada.');
            break;

        // ------------------------------------------------------------------
        case 'request_signature':
            $docKey = trim((string) ($_POST['doc_key'] ?? ''));
            if ($docKey === '') {
                profileBack($employeeId, 'Documento no válido.', false);
            }

            $docStmt = $pdo->prepare("SELECT label FROM required_document_types WHERE doc_key = ? AND is_active = 1");
            $docStmt->execute([$docKey]);
            $docLabel = $docStmt->fetchColumn();
            if ($docLabel === false) {
                profileBack($employeeId, 'Ese documento no está en el catálogo.', false);
            }

            // Si ya hay una solicitud pendiente se reutiliza su enlace en vez de
            // generar otra: dos enlaces vivos para el mismo documento confunden.
            $existing = $pdo->prepare("
                SELECT token FROM employee_document_signatures
                WHERE employee_id = ? AND doc_key = ? AND status = 'PENDIENTE'
                ORDER BY id DESC LIMIT 1
            ");
            $existing->execute([$employeeId, $docKey]);
            $token = $existing->fetchColumn();

            if ($token === false) {
                $token = bin2hex(random_bytes(24));
                $ins = $pdo->prepare("
                    INSERT INTO employee_document_signatures
                        (employee_id, doc_key, status, token, requested_by, expires_at)
                    VALUES (?, ?, 'PENDIENTE', ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))
                ");
                $ins->execute([$employeeId, $docKey, $token, $userId ?: null]);
            }

            $_SESSION['profile_signature_link'] = [
                'doc_key' => $docKey,
                'label'   => $docLabel,
                'token'   => $token,
            ];
            profileBack($employeeId, 'Enlace de firma listo para "' . $docLabel . '". Compártelo con el colaborador.');
            break;

        // ------------------------------------------------------------------
        case 'terminate':
            $labels = employeeTerminationLabels();
            $reason = (string) ($_POST['termination_reason'] ?? '');
            $rehire = (string) ($_POST['rehire_eligibility'] ?? '');
            $date   = (string) ($_POST['termination_date'] ?? date('Y-m-d'));

            if (!array_key_exists($reason, $labels['reasons'])) {
                profileBack($employeeId, 'Selecciona un motivo de terminación válido.', false);
            }
            if (!array_key_exists($rehire, $labels['rehire'])) {
                profileBack($employeeId, 'Selecciona la elegibilidad para recontratación.', false);
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = date('Y-m-d');
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    UPDATE employees
                    SET employment_status = 'TERMINATED',
                        termination_date = ?, termination_reason = ?, termination_notes = ?,
                        rehire_eligibility = ?, rehire_notes = ?,
                        terminated_by = ?, terminated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $date, $reason,
                    trim((string) ($_POST['termination_notes'] ?? '')) ?: null,
                    $rehire,
                    trim((string) ($_POST['rehire_notes'] ?? '')) ?: null,
                    $userId ?: null,
                    $employeeId,
                ]);

                // Se cierran las campañas vigentes y se desactiva el acceso: si no,
                // el colaborador seguiría contando como activo en los monitores.
                $pdo->prepare("UPDATE employee_campaigns SET end_date = ? WHERE employee_id = ? AND end_date IS NULL")
                    ->execute([$date, $employeeId]);

                if (!empty($employee['user_id'])) {
                    $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$employee['user_id']]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            profileBack($employeeId, 'Salida registrada: ' . $labels['reasons'][$reason] . ' · ' . $labels['rehire'][$rehire] . '.');
            break;

        // ------------------------------------------------------------------
        // Exención permanente de ISR. Se guarda quién y cuándo: dejar de
        // retenerle un impuesto a alguien hay que poder justificarlo después.
        case 'set_isr_exempt':
            require_once __DIR__ . '/payroll_functions.php';
            ensureEmployeeIsrExemptColumns($pdo);

            $exento = !empty($_POST['isr_exempt']) ? 1 : 0;
            $motivo = trim((string) ($_POST['isr_exempt_reason'] ?? ''));
            if ($exento === 1 && $motivo === '') {
                profileBack($employeeId, 'Escribe el motivo de la exención de ISR.', false);
            }

            $pdo->prepare("
                UPDATE employees
                SET isr_exempt = ?, isr_exempt_reason = ?, isr_exempt_by = ?, isr_exempt_at = ?
                WHERE id = ?
            ")->execute([
                $exento,
                $exento ? mb_substr($motivo, 0, 255) : null,
                $exento ? ($userId ?: null) : null,
                $exento ? date('Y-m-d H:i:s') : null,
                $employeeId,
            ]);

            profileBack(
                $employeeId,
                $exento
                    ? 'Exención de ISR activada para ' . $employeeName . '. Recalcula las quincenas donde deba aplicar.'
                    : 'Exención de ISR retirada. Recalcula las quincenas donde deba volver a retenerse.'
            );
            break;

        // ------------------------------------------------------------------
        // Exención permanente de seguridad social (AFP / SFS). Van por separado
        // porque no siempre coinciden: un pensionado no cotiza AFP pero sí SFS.
        case 'set_tss_exempt':
            require_once __DIR__ . '/payroll_functions.php';
            ensureEmployeeTssExemptColumns($pdo);

            $afpExento = !empty($_POST['afp_exempt']) ? 1 : 0;
            $sfsExento = !empty($_POST['sfs_exempt']) ? 1 : 0;
            $motivo = trim((string) ($_POST['tss_exempt_reason'] ?? ''));
            if (($afpExento === 1 || $sfsExento === 1) && $motivo === '') {
                profileBack($employeeId, 'Escribe el motivo de la exención de AFP/SFS.', false);
            }

            $algunaExencion = ($afpExento === 1 || $sfsExento === 1);

            $pdo->prepare("
                UPDATE employees
                SET afp_exempt = ?, sfs_exempt = ?, tss_exempt_reason = ?, tss_exempt_by = ?, tss_exempt_at = ?
                WHERE id = ?
            ")->execute([
                $afpExento,
                $sfsExento,
                $algunaExencion ? mb_substr($motivo, 0, 255) : null,
                $algunaExencion ? ($userId ?: null) : null,
                $algunaExencion ? date('Y-m-d H:i:s') : null,
                $employeeId,
            ]);

            if ($algunaExencion) {
                $conceptos = [];
                if ($afpExento) { $conceptos[] = 'AFP'; }
                if ($sfsExento) { $conceptos[] = 'SFS'; }
                profileBack(
                    $employeeId,
                    'A ' . $employeeName . ' ya no se le descuenta ' . implode(' ni ', $conceptos)
                        . '. Recalcula las quincenas donde deba aplicar.'
                );
            }

            profileBack($employeeId, 'Exención de AFP/SFS retirada. Recalcula las quincenas donde deba volver a descontarse.');
            break;

        // ------------------------------------------------------------------
        default:
            profileBack($employeeId, 'Acción no reconocida.', false);
    }
} catch (Throwable $e) {
    error_log('employee_profile_actions (' . $action . '): ' . $e->getMessage());
    profileBack($employeeId, 'No se pudo completar la acción: ' . $e->getMessage(), false);
}
