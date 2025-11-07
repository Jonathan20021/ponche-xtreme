<?php
session_start();
require_once '../db.php';
ensurePermission('hr_employees', '../unauthorized.php');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Simple Form</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #1a1a1a; color: #fff; }
        input, button { display: block; margin: 10px 0; padding: 10px; width: 300px; }
        button { background: #4CAF50; color: white; border: none; cursor: pointer; }
        button:hover { background: #45a049; }
    </style>
</head>
<body>
    <h1>Test Form - Sin Includes</h1>
    <p>Este formulario NO tiene header.php ni footer.php</p>
    
    <form action="debug_full.php" method="POST">
        <input type="text" name="employee_name" value="TEST Usuario" required>
        <input type="text" name="id_card" value="001-1234567-8" required>
        <input type="text" name="province" value="Santiago" required>
        <input type="text" name="position" value="Test Position" required>
        <input type="number" name="salary" value="30000" required>
        <input type="text" name="work_schedule" value="44 horas" required>
        <input type="date" name="contract_date" value="<?= date('Y-m-d') ?>" required>
        <input type="text" name="city" value="Santiago" required>
        <input type="hidden" name="action" value="employment">
        <button type="submit">GENERAR CONTRATO</button>
    </form>
    
    <hr>
    <p>Cuando hagas clic, debería generar el PDF directamente.</p>
</body>
</html>
