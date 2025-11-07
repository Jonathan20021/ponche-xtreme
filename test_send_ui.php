<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enviar Reporte de Ausencias - Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #1a202c;
            color: white;
        }
        .container {
            background: #2d3748;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
        }
        h1 {
            color: #667eea;
            margin-bottom: 20px;
        }
        .info {
            background: #4a5568;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            font-size: 16px;
            border-radius: 5px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        button:hover {
            transform: translateY(-2px);
        }
        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 5px;
            display: none;
        }
        .success {
            background: #48bb78;
            display: block;
        }
        .error {
            background: #f56565;
            display: block;
        }
        .loading {
            display: inline-block;
            margin-left: 10px;
        }
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: inline-block;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .log {
            background: #1a202c;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            max-height: 300px;
            overflow-y: auto;
        }
        .log-entry {
            margin: 5px 0;
            padding: 5px;
            border-left: 3px solid #667eea;
            padding-left: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📧 Test de Envío de Reporte de Ausencias</h1>
        
        <div class="info">
            <strong>Destinatario:</strong> jonathansandovalferreira@gmail.com<br>
            <strong>Servidor SMTP:</strong> mail.evallishbpo.com:465<br>
            <strong>Estado:</strong> <span id="status">Listo para enviar</span>
        </div>
        
        <button id="sendBtn" onclick="sendReport()">
            📊 Enviar Reporte Ahora
        </button>
        
        <div id="result" class="result"></div>
        
        <div class="log" id="log">
            <strong>Log de Envío:</strong>
            <div id="logEntries"></div>
        </div>
    </div>

    <script>
        function addLog(message, type = 'info') {
            const logEntries = document.getElementById('logEntries');
            const entry = document.createElement('div');
            entry.className = 'log-entry';
            const timestamp = new Date().toLocaleTimeString();
            entry.innerHTML = `[${timestamp}] ${message}`;
            logEntries.appendChild(entry);
            logEntries.scrollTop = logEntries.scrollHeight;
        }

        async function sendReport() {
            const btn = document.getElementById('sendBtn');
            const result = document.getElementById('result');
            const status = document.getElementById('status');
            
            // Reset
            result.style.display = 'none';
            result.className = 'result';
            
            // Disable button
            btn.disabled = true;
            btn.innerHTML = '⏳ Enviando... <div class="spinner"></div>';
            status.textContent = 'Enviando email...';
            
            addLog('Iniciando envío de reporte...');
            
            try {
                addLog('Conectando a send_absence_report.php...');
                
                const response = await fetch('send_absence_report.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
                
                addLog(`Respuesta recibida: HTTP ${response.status}`);
                
                const data = await response.json();
                
                addLog('JSON parseado correctamente');
                
                if (data.success) {
                    result.className = 'result success';
                    result.innerHTML = `
                        <strong>✅ Email Enviado Exitosamente!</strong><br>
                        ${data.message}<br><br>
                        <strong>Estadísticas:</strong><br>
                        • Total Empleados: ${data.data.total_employees}<br>
                        • Total Ausencias: ${data.data.total_absences}<br>
                        • Sin Justificar: ${data.data.absences_without_justification}<br>
                        • Justificadas: ${data.data.absences_with_justification}<br><br>
                        <strong>⚠️ IMPORTANTE:</strong> Revisa tu bandeja de entrada en Gmail<br>
                        Si no aparece, revisa la carpeta de SPAM
                    `;
                    status.textContent = 'Email enviado correctamente';
                    addLog('✅ Email enviado exitosamente');
                    addLog(`Destinatarios: ${data.data.recipients_count}`);
                } else {
                    result.className = 'result error';
                    result.innerHTML = `<strong>❌ Error:</strong> ${data.error}`;
                    status.textContent = 'Error al enviar';
                    addLog('❌ Error: ' + data.error);
                }
                
                result.style.display = 'block';
                
            } catch (error) {
                result.className = 'result error';
                result.innerHTML = `<strong>❌ Error de Red:</strong> ${error.message}`;
                result.style.display = 'block';
                status.textContent = 'Error de conexión';
                addLog('❌ Excepción: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '📊 Enviar Reporte Ahora';
                addLog('Proceso finalizado');
            }
        }
        
        // Log inicial
        addLog('Sistema iniciado y listo');
        addLog('Click en el botón para enviar el reporte');
    </script>
</body>
</html>
