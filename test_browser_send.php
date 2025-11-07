<?php
/**
 * Direct browser test with proper session
 * Open this file in your browser: http://localhost/ponche-xtreme/test_browser_send.php
 */

// Start session properly
session_start();

// Set up admin session
$_SESSION['user_id'] = 1;
$_SESSION['full_name'] = 'Test Admin';
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'administrator';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Test de Envío - Browser</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 900px;
            margin: 30px auto;
            padding: 20px;
            background: #f5f7fa;
        }
        .box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        h2 {
            color: #667eea;
            margin-top: 0;
        }
        button {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background: #5568d3;
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 5px;
            display: none;
        }
        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        pre {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="box">
        <h2>🧪 Test de Envío de Reporte</h2>
        
        <p><strong>Sesión Actual:</strong></p>
        <pre><?php print_r($_SESSION); ?></pre>
        
        <p><strong>Email Destino:</strong> jonathansandovalferreira@gmail.com</p>
        
        <button onclick="sendReport()">📧 Enviar Reporte Ahora</button>
        
        <div id="result" class="result"></div>
    </div>
    
    <div class="box">
        <h2>📋 Instrucciones</h2>
        <ol>
            <li>Haz clic en el botón "Enviar Reporte Ahora"</li>
            <li>Espera la respuesta (2-5 segundos)</li>
            <li>Ve a Gmail: <a href="https://gmail.com" target="_blank">gmail.com</a></li>
            <li>Busca en Bandeja de Entrada Y en Spam</li>
            <li>Busca por: "Reporte Diario de Ausencias"</li>
        </ol>
    </div>

    <script>
        async function sendReport() {
            const result = document.getElementById('result');
            result.style.display = 'block';
            result.className = 'result';
            result.innerHTML = '⏳ Enviando email...';
            
            try {
                const response = await fetch('send_absence_report.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
                
                const data = await response.json();
                
                if (data.success) {
                    result.className = 'result success';
                    result.innerHTML = `
                        <h3>✅ Email Enviado Exitosamente</h3>
                        <p><strong>Mensaje:</strong> ${data.message}</p>
                        ${data.data ? `
                            <p><strong>Estadísticas:</strong></p>
                            <ul>
                                <li>Total Empleados: ${data.data.total_employees}</li>
                                <li>Total Ausencias: ${data.data.total_absences}</li>
                                <li>Sin Justificación: ${data.data.absences_without_justification}</li>
                                <li>Con Justificación: ${data.data.absences_with_justification}</li>
                            </ul>
                        ` : ''}
                        <hr>
                        <p><strong>🔍 Ahora verifica tu email:</strong></p>
                        <ol>
                            <li>Abre <a href="https://gmail.com" target="_blank">Gmail</a></li>
                            <li>Revisa Bandeja de Entrada</li>
                            <li>Revisa carpeta de SPAM</li>
                            <li>Busca: "Reporte Diario de Ausencias"</li>
                        </ol>
                    `;
                } else {
                    result.className = 'result error';
                    result.innerHTML = `<h3>❌ Error</h3><p>${data.error}</p>`;
                }
            } catch (error) {
                result.className = 'result error';
                result.innerHTML = `<h3>❌ Error de Red</h3><p>${error.message}</p>`;
            }
        }
    </script>
</body>
</html>
