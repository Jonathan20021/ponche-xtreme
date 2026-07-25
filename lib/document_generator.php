<?php
/**
 * lib/document_generator.php
 *
 * Genera los documentos del colaborador a partir de las plantillas de
 * `document_templates`, produce el PDF y lo archiva SOLO en el expediente.
 *
 * Las plantillas son HTML con marcadores {{campo}}. Los formatos definitivos los
 * suministra el cliente, así que se editan desde hr/document_templates.php sin
 * tocar código.
 *
 * Tres modos de plantilla:
 *   template -> se arma con body_html + los datos del colaborador
 *   builtin  -> usa un generador propio ya aprobado (contrato de trabajo y de
 *               confidencialidad, que tienen texto legal y no se editan a mano)
 *   upload   -> no se genera (la cédula se escanea), solo se carga
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/contract_documents.php';

if (!function_exists('documentTemplates')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function documentTemplates(PDO $pdo, bool $onlyActive = true, bool $onlyGeneratable = false): array
    {
        try {
            $sql = "SELECT * FROM document_templates";
            $w = [];
            if ($onlyActive)      { $w[] = 'is_active = 1'; }
            if ($onlyGeneratable) { $w[] = "render_mode <> 'upload'"; }
            if ($w) { $sql .= ' WHERE ' . implode(' AND ', $w); }
            $sql .= ' ORDER BY sort_order, name';
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('documentTemplates: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('documentTemplateByKey')) {
    function documentTemplateByKey(PDO $pdo, string $docKey): ?array
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM document_templates WHERE doc_key = ?");
            $stmt->execute([$docKey]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log('documentTemplateByKey: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('documentPlaceholderValues')) {
    /**
     * Datos del colaborador disponibles como marcadores en las plantillas.
     *
     * @return array<string,string>
     */
    function documentPlaceholderValues(PDO $pdo, int $employeeId, array $extra = []): array
    {
        $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
                  'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        $stmt = $pdo->prepare("
            SELECT e.*, u.username, u.role,
                   u.hourly_rate, u.hourly_rate_dop, u.monthly_salary, u.monthly_salary_dop,
                   u.preferred_currency, u.compensation_type,
                   d.name AS department_name,
                   c.name AS campaign_name,
                   s.full_name AS supervisor_name
            FROM employees e
            LEFT JOIN users u ON u.id = e.user_id
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN campaigns c ON c.id = e.campaign_id
            LEFT JOIN users s ON s.id = e.supervisor_id
            WHERE e.id = ?
        ");
        $stmt->execute([$employeeId]);
        $e = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$e) {
            return [];
        }

        $hoy = new DateTime();
        $ingreso = !empty($e['hire_date']) ? new DateTime($e['hire_date']) : null;

        // Sueldo a mostrar, en la moneda en la que de verdad cobra.
        $prefersDop = strtoupper((string) ($e['preferred_currency'] ?? 'DOP')) === 'DOP';
        $paymentType = function_exists('resolvePaymentType')
            ? resolvePaymentType($e['compensation_type'] ?? '', $e['role'] ?? '',
                max((float) $e['monthly_salary'], (float) $e['monthly_salary_dop']))
            : 'hourly';

        if ($paymentType === 'fixed') {
            $monto = $prefersDop ? (float) $e['monthly_salary_dop'] : (float) $e['monthly_salary'];
            if ($monto <= 0) { $monto = $prefersDop ? (float) $e['monthly_salary'] : (float) $e['monthly_salary_dop']; }
            $salario = ($prefersDop ? 'RD$' : '$') . number_format($monto, 2) . ' mensuales';
            $tipoPago = 'mensual';
        } else {
            $monto = $prefersDop ? (float) $e['hourly_rate_dop'] : (float) $e['hourly_rate'];
            if ($monto <= 0) { $monto = $prefersDop ? (float) $e['hourly_rate'] : (float) $e['hourly_rate_dop']; }
            $salario = ($prefersDop ? 'RD$' : '$') . number_format($monto, 2) . ' por hora';
            $tipoPago = 'por_hora';
        }

        // Horario, para los documentos que lo mencionan (el contrato de trabajo).
        $horario = '';
        try {
            if (function_exists('getScheduleConfigForUser') && !empty($e['user_id'])) {
                $sc = getScheduleConfigForUser($pdo, (int) $e['user_id']);
                if (!empty($sc['entry_time']) && !empty($sc['exit_time'])) {
                    $horario = substr((string) $sc['entry_time'], 0, 5) . ' a ' . substr((string) $sc['exit_time'], 0, 5);
                }
            }
        } catch (Throwable $ex) { /* opcional */ }

        $values = [
            'nombre'              => trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? '')),
            'primer_nombre'       => (string) ($e['first_name'] ?? ''),
            'apellido'            => (string) ($e['last_name'] ?? ''),
            'codigo'              => (string) ($e['employee_code'] ?? ''),
            'cedula'              => (string) ($e['id_card_number'] ?: $e['identification_number'] ?? ''),
            'posicion'            => (string) ($e['position'] ?: 'Colaborador'),
            'departamento'        => (string) ($e['department_name'] ?: 'Sin departamento'),
            'campana'             => (string) ($e['campaign_name'] ?: '—'),
            'supervisor'          => (string) ($e['supervisor_name'] ?: '—'),
            'correo'              => (string) ($e['email'] ?? ''),
            'telefono'            => (string) ($e['phone'] ?: $e['mobile'] ?? ''),
            'direccion'           => (string) ($e['address'] ?? ''),
            'provincia'           => (string) ($e['state'] ?: $e['city'] ?? ''),
            'salario'             => $salario,
            // Valores CRUDOS para los generadores con texto legal fijo: esos
            // arman su propia frase ("la suma de RD$ X Pesos Dominicanos"), así
            // que si se les pasa el texto ya formateado sale "RD$ RD$250.00 por
            // hora Pesos Dominicanos". No se usan como marcador de plantilla.
            '_salario_monto'      => number_format($monto, 2, '.', ''),
            '_tipo_pago'          => $tipoPago,
            'horario'             => $horario,
            'fecha'               => $hoy->format('d/m/Y'),
            'dia'                 => $hoy->format('d'),
            'mes'                 => $meses[(int) $hoy->format('n')] ?? '',
            'anio'                => $hoy->format('Y'),
            'fecha_larga'         => $hoy->format('d') . ' de ' . ($meses[(int) $hoy->format('n')] ?? '') . ' de ' . $hoy->format('Y'),
            'fecha_ingreso'       => $ingreso ? $ingreso->format('d/m/Y') : '',
            'fecha_ingreso_larga' => $ingreso ? $ingreso->format('d') . ' de ' . ($meses[(int) $ingreso->format('n')] ?? '') . ' de ' . $ingreso->format('Y') : '',
            'empresa'             => 'EVALLISH SRL',
            'rnc'                 => '1-3263745-3',
            'representante'       => 'Hugo Antonio Hidalgo Núñez',
            'ciudad'              => 'Santiago de los Caballeros',
        ];

        // Los campos extra que se piden al generar (motivo, medida, etc.)
        foreach ($extra as $k => $v) {
            $clean = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $k));
            if ($clean !== '') {
                $values[$clean] = (string) $v;
            }
        }

        return $values;
    }
}

if (!function_exists('documentRenderTemplate')) {
    /**
     * Reemplaza los marcadores {{campo}} por sus valores.
     *
     * Los valores se escapan: si alguien pega HTML en un campo de texto no puede
     * romper el documento ni inyectar marcado.
     */
    function documentRenderTemplate(string $body, array $values): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', static function ($m) use ($values) {
            $key = strtolower($m[1]);
            if (!array_key_exists($key, $values)) {
                // Marcador desconocido: se deja visible para que RRHH lo note.
                return '<span style="background:#fef3c7;color:#92400e;">[' . htmlspecialchars($m[1]) . ']</span>';
            }
            return nl2br(htmlspecialchars((string) $values[$key], ENT_QUOTES, 'UTF-8'));
        }, $body);
    }
}

if (!function_exists('documentWrapHtml')) {
    /** Envuelve el cuerpo con los estilos del documento impreso. */
    function documentWrapHtml(string $body, string $title): string
    {
        $logo = contractDocumentLogoData();
        $logoHtml = $logo
            ? '<div class="logo"><img src="data:image/png;base64,' . $logo . '" alt="Evallish"></div>'
            : '';

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>'
            . '@page { margin: 2cm; }'
            . 'body { font-family: "Times New Roman", Times, serif; font-size: 12pt; line-height: 1.6; color: #000; }'
            . '.logo { text-align: center; margin-bottom: 14px; }'
            . '.logo img { max-height: 70px; }'
            . 'h1 { font-size: 15pt; text-align: center; text-transform: uppercase; margin: 0 0 4px; letter-spacing: .5px; }'
            . '.empresa { text-align: center; font-size: 10pt; color: #444; margin: 0 0 22px; }'
            . '.titulo { font-weight: bold; text-transform: uppercase; margin: 18px 0 6px; font-size: 11pt; }'
            . 'p { text-align: justify; margin: 0 0 10px; }'
            . 'table.datos { width: 100%; border-collapse: collapse; margin: 0 0 18px; font-size: 11pt; }'
            . 'table.datos td { border: 1px solid #999; padding: 5px 8px; }'
            . 'table.datos td:first-child { background: #f2f2f2; width: 32%; font-weight: bold; }'
            . '.pendiente { background: #fef3c7; border: 1px dashed #d97706; padding: 10px; color: #92400e; font-size: 10pt; }'
            . '.firmas { margin-top: 55px; width: 100%; }'
            . '.firma { display: inline-block; width: 45%; text-align: center; vertical-align: top; margin-right: 4%; }'
            . '.firma .linea { border-top: 1px solid #000; margin-bottom: 6px; }'
            . '.firma p { text-align: center; font-size: 10pt; margin: 0; }'
            . 'ul { margin: 0 0 10px 18px; }'
            . '</style></head><body>' . $logoHtml . $body . '</body></html>';
    }
}

if (!function_exists('documentBuildHtml')) {
    /**
     * Arma el HTML final del documento para un colaborador.
     *
     * @return array{html:string, name:string}|null
     */
    function documentBuildHtml(PDO $pdo, string $docKey, int $employeeId, array $extra = []): ?array
    {
        $tpl = documentTemplateByKey($pdo, $docKey);
        if (!$tpl || (int) $tpl['is_active'] !== 1) {
            return null;
        }

        if ($tpl['render_mode'] === 'upload') {
            return null; // no se genera, se carga
        }

        $values = documentPlaceholderValues($pdo, $employeeId, $extra);
        if (empty($values)) {
            return null;
        }

        if ($tpl['render_mode'] === 'builtin') {
            $handler = (string) $tpl['builtin_handler'];
            if ($handler === '' || !function_exists($handler)) {
                return null;
            }
            // Los generadores aprobados arman el documento completo (con estilos)
            // y su propia redacción del monto, así que reciben el salario CRUDO
            // y el tipo de pago real, no el texto ya formateado.
            $html = $handler([
                'employee_name' => $values['nombre'],
                'id_card'       => $values['cedula'],
                'id_type'       => 'CEDULA',
                'contract_date' => date('Y-m-d'),
                'province'      => $values['provincia'],
                'position'      => $values['posicion'],
                'salary'        => $values['_salario_monto'],
                'payment_type'  => $values['_tipo_pago'],
                'work_schedule' => $values['horario'],
                'city'          => $values['ciudad'],
            ]);
            return ['html' => $html, 'name' => $tpl['name']];
        }

        $body = documentRenderTemplate((string) $tpl['body_html'], $values);
        return ['html' => documentWrapHtml($body, $tpl['name']), 'name' => $tpl['name']];
    }
}

if (!function_exists('documentGenerateAndFile')) {
    /**
     * Genera el PDF y lo archiva en el expediente del colaborador.
     *
     * @return array{ok:bool, error:?string, document_id:?int, file_path:?string, pdf:?string}
     */
    function documentGenerateAndFile(
        PDO $pdo,
        string $docKey,
        int $employeeId,
        array $extra = [],
        bool $fileToExpediente = true,
        ?int $generatedBy = null
    ): array {
        $fail = static fn(string $m) => ['ok' => false, 'error' => $m, 'document_id' => null, 'file_path' => null, 'pdf' => null];

        $built = documentBuildHtml($pdo, $docKey, $employeeId, $extra);
        if ($built === null) {
            return $fail('No se pudo armar el documento. Revisa que la plantilla esté activa y sea generable.');
        }

        try {
            require_once __DIR__ . '/../vendor/autoload.php';
            $options = new \Dompdf\Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'Times New Roman');

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($built['html'], 'UTF-8');
            $dompdf->setPaper('Letter', 'portrait');
            $dompdf->render();
            $pdf = $dompdf->output();
        } catch (Throwable $e) {
            error_log('documentGenerateAndFile PDF: ' . $e->getMessage());
            return $fail('Error al generar el PDF: ' . $e->getMessage());
        }

        $result = ['ok' => true, 'error' => null, 'document_id' => null, 'file_path' => null, 'pdf' => $pdf];

        if (!$fileToExpediente) {
            return $result;
        }

        try {
            $dir = __DIR__ . '/../uploads/generated_documents';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $fileName = $docKey . '_' . $employeeId . '_' . date('YmdHis') . '.pdf';
            $relPath  = 'uploads/generated_documents/' . $fileName;

            if (file_put_contents($dir . '/' . $fileName, $pdf) === false) {
                return $result; // el PDF se devuelve igual aunque no se pueda archivar
            }

            $tpl = documentTemplateByKey($pdo, $docKey);

            // Se archiva con doc_key para que cuente en el checklist del expediente.
            $ins = $pdo->prepare("
                INSERT INTO employee_documents
                    (employee_id, document_type, doc_key, document_name, file_path, file_size,
                     file_extension, mime_type, description, uploaded_by, uploaded_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pdf', 'application/pdf', ?, ?, NOW(), NOW())
            ");
            $ins->execute([
                $employeeId,
                $built['name'],
                $docKey,
                $built['name'] . '.pdf',
                $relPath,
                strlen($pdf),
                'Generado desde el sistema el ' . date('d/m/Y H:i'),
                $generatedBy ?: ($_SESSION['user_id'] ?? null),
            ]);
            $documentId = (int) $pdo->lastInsertId();

            $log = $pdo->prepare("
                INSERT INTO generated_documents
                    (employee_id, doc_key, document_name, employee_document_id, payload_json, generated_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $log->execute([
                $employeeId, $docKey, $built['name'], $documentId,
                json_encode($extra, JSON_UNESCAPED_UNICODE),
                $generatedBy ?: ($_SESSION['user_id'] ?? null),
            ]);

            $result['document_id'] = $documentId;
            $result['file_path']   = $relPath;
        } catch (Throwable $e) {
            error_log('documentGenerateAndFile archivar: ' . $e->getMessage());
        }

        return $result;
    }
}

if (!function_exists('documentExtraFields')) {
    /**
     * Campos adicionales que hay que pedir antes de generar (motivo, medida...).
     *
     * @return array<int,array{key:string,label:string}>
     */
    function documentExtraFields(?string $csv): array
    {
        if (empty($csv)) {
            return [];
        }
        $labels = [
            'motivo'             => 'Motivo / hechos',
            'tipo_falta'         => 'Tipo de falta',
            'medida'             => 'Medida aplicada',
            'aspectos_positivos' => 'Aspectos positivos',
            'areas_mejora'       => 'Áreas de mejora',
            'compromisos'        => 'Compromisos acordados',
            'hechos'             => 'Hechos atribuidos',
            'descargo_empleado'  => 'Descargo del colaborador',
            'articulos_devueltos'=> 'Artículos devueltos',
            'observaciones'      => 'Observaciones',
            'fecha_inicio'       => 'Fecha de inicio',
            'beneficios'         => 'Beneficios',
        ];

        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $csv))) as $k) {
            $out[] = ['key' => $k, 'label' => $labels[$k] ?? ucfirst(str_replace('_', ' ', $k))];
        }
        return $out;
    }
}
