<?php
/**
 * Gemini API Integration
 * Handles communication with Google's Gemini AI API
 */

class GeminiAPI {
    private $apiKey;
    private $apiUrl;
    
    public function __construct($apiKey = null) {
        $this->apiKey = $apiKey ?? 'AIzaSyBsNFvo5gaMsHcQTKRsYQ5ElSQBVN5ulZ0';
        $this->apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent';
    }
    
    /**
     * Generate content using Gemini API
     */
    public function generateContent($prompt, $context = []) {
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 2048,
            ]
        ];
        
        return $this->makeRequest($data);
    }
    
    /**
     * Chat with context
     */
    public function chat($userMessage, $conversationHistory = [], $systemContext = '') {
        $contents = [];
        
        // Add conversation history
        foreach ($conversationHistory as $message) {
            $contents[] = [
                'role' => $message['role'] ?? 'user',
                'parts' => [
                    ['text' => $message['content']]
                ]
            ];
        }
        
        // Add current user message with system context
        $fullMessage = $systemContext ? $systemContext . "\n\nUsuario: " . $userMessage : $userMessage;
        $contents[] = [
            'role' => 'user',
            'parts' => [
                ['text' => $fullMessage]
            ]
        ];
        
        $data = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.8,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 2048,
            ],
            'safetySettings' => [
                [
                    'category' => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_HATE_SPEECH',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ]
            ]
        ];
        
        return $this->makeRequest($data);
    }
    
    /**
     * Make HTTP request to Gemini API
     */
    private function makeRequest($data) {
        $url = $this->apiUrl . '?key=' . $this->apiKey;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => 'Error de conexión: ' . $error
            ];
        }
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'error' => 'Error de API (código ' . $httpCode . '): ' . $response
            ];
        }
        
        $result = json_decode($response, true);
        
        if (!$result || !isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return [
                'success' => false,
                'error' => 'Respuesta inválida de la API'
            ];
        }
        
        return [
            'success' => true,
            'text' => $result['candidates'][0]['content']['parts'][0]['text'],
            'raw' => $result
        ];
    }
    
    /**
     * Build system context from employee data
     */
    public static function buildSystemContext($employeeContext) {
        $context = "Eres un asistente virtual de Recursos Humanos para Evallish BPO. Tu nombre es 'Asistente RH'.\n\n";
        $context .= "INFORMACIÓN DEL EMPLEADO:\n";
        
        if (isset($employeeContext['employee_info']) && $employeeContext['employee_info']) {
            $info = $employeeContext['employee_info'];
            $context .= "- Nombre: " . ($info['full_name'] ?? 'N/A') . "\n";
            $context .= "- Código: " . ($info['employee_code'] ?? 'N/A') . "\n";
            $context .= "- Puesto: " . ($info['position'] ?? $info['role'] ?? 'N/A') . "\n";
            $context .= "- Departamento: " . ($info['department_name'] ?? 'N/A') . "\n";
            $context .= "- Estado: " . ($info['employment_status'] ?? 'N/A') . "\n";
            $context .= "- Tipo: " . ($info['employment_type'] ?? 'N/A') . "\n";
            $context .= "- Fecha de contratación: " . ($info['hire_date'] ?? 'N/A') . "\n";
            if (isset($info['months_employed'])) {
                $context .= "- Tiempo en la empresa: " . $info['months_employed'] . " meses\n";
            }
        }
        
        if (isset($employeeContext['vacation_balance']) && $employeeContext['vacation_balance']) {
            $vac = $employeeContext['vacation_balance'];
            $context .= "\nVACACIONES (año actual):\n";
            $context .= "- Días totales asignados: " . ($vac['total_days'] ?? 14) . "\n";
            $context .= "- Días usados: " . ($vac['used_days'] ?? 0) . "\n";
            $context .= "- Días disponibles: " . ($vac['remaining_days'] ?? 14) . "\n";
            if (isset($vac['pending_requests']) && $vac['pending_requests'] > 0) {
                $context .= "- Solicitudes pendientes: " . $vac['pending_requests'] . "\n";
            }
        }
        
        if (isset($employeeContext['vacation_requests']) && !empty($employeeContext['vacation_requests'])) {
            $context .= "\nSOLICITUDES DE VACACIONES RECIENTES:\n";
            foreach (array_slice($employeeContext['vacation_requests'], 0, 3) as $req) {
                $context .= "- Del " . $req['start_date'] . " al " . $req['end_date'];
                $context .= " (" . $req['total_days'] . " días) - Estado: " . $req['status'] . "\n";
            }
        }
        
        if (isset($employeeContext['schedule']) && $employeeContext['schedule']) {
            $sched = $employeeContext['schedule'];
            $context .= "\nHORARIO DE TRABAJO:\n";
            $context .= "- Entrada: " . ($sched['entry_time'] ?? '10:00:00') . "\n";
            $context .= "- Salida: " . ($sched['exit_time'] ?? '19:00:00') . "\n";
            $context .= "- Almuerzo: " . ($sched['lunch_time'] ?? '14:00:00') . " (" . ($sched['lunch_minutes'] ?? 45) . " min)\n";
            $context .= "- Break: " . ($sched['break_time'] ?? '17:00:00') . " (" . ($sched['break_minutes'] ?? 15) . " min)\n";
            $context .= "- Horas programadas: " . ($sched['scheduled_hours'] ?? 8) . " horas/día\n";
        }
        
        if (isset($employeeContext['attendance_summary']) && $employeeContext['attendance_summary']) {
            $att = $employeeContext['attendance_summary'];
            $context .= "\nASISTENCIA (últimos 30 días):\n";
            $context .= "- Días con registros: " . ($att['days_with_records'] ?? 0) . "\n";
            $context .= "- Entradas registradas: " . ($att['entry_count'] ?? 0) . "\n";
            $context .= "- Salidas registradas: " . ($att['exit_count'] ?? 0) . "\n";
        }
        
        if (isset($employeeContext['recent_permissions']) && !empty($employeeContext['recent_permissions'])) {
            $context .= "\nPERMISOS RECIENTES:\n";
            foreach (array_slice($employeeContext['recent_permissions'], 0, 3) as $perm) {
                $context .= "- " . $perm['request_type'] . ": " . $perm['start_date'];
                $context .= " - Estado: " . $perm['status'] . "\n";
            }
        }
        
        if (isset($employeeContext['evaluations']) && !empty($employeeContext['evaluations'])) {
            $context .= "\nEVALUACIONES PRÓXIMAS:\n";
            foreach ($employeeContext['evaluations'] as $eval) {
                $context .= "- " . $eval['evaluation_type'] . ": " . $eval['evaluation_date'] . "\n";
            }
        }
        
        if (isset($employeeContext['upcoming_events']) && !empty($employeeContext['upcoming_events'])) {
            $context .= "\nEVENTOS PRÓXIMOS:\n";
            foreach ($employeeContext['upcoming_events'] as $event) {
                $context .= "- " . $event['title'] . " - " . $event['event_date'];
                if ($event['event_type']) {
                    $context .= " (" . $event['event_type'] . ")";
                }
                $context .= "\n";
            }
        }
        
        if (isset($employeeContext['policies'])) {
            $context .= "\nPOLÍTICAS DE LA EMPRESA:\n";
            foreach ($employeeContext['policies'] as $key => $policy) {
                $context .= "- " . ucfirst(str_replace('_', ' ', $key)) . ": " . $policy . "\n";
            }
        }
        
        $context .= "\nINSTRUCCIONES:\n";
        $context .= "- Responde de manera amigable, profesional y concisa en español.\n";
        $context .= "- Usa SOLO la información real del empleado que te he proporcionado arriba.\n";
        $context .= "- Si no tienes información específica sobre algo, dile al empleado que contacte a RH.\n";
        $context .= "- Para solicitudes que requieren acción (permisos, vacaciones), explica el proceso paso a paso.\n";
        $context .= "- Si el empleado pregunta por datos que no tienes, NO inventes información.\n";
        $context .= "- Mantén un tono cordial, servicial y profesional.\n";
        $context .= "- Formatea tus respuestas de manera clara y organizada.\n";
        
        return $context;
    }
}
