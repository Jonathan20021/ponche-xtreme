<div class="flex gap-2 justify-end">
    <a href="inventory.php?employee_id=<?= (int) $emp['id'] ?>"
        class="p-2 rounded bg-indigo-500/20 text-indigo-400 hover:bg-indigo-500/30 transition-colors"
        title="Ver Inventario">
        <i class="fas fa-boxes"></i>
    </a>
    <button type="button" onclick='editEmployee(this)'
        class="p-2 rounded bg-blue-500/20 text-blue-400 hover:bg-blue-500/30 transition-colors" title="Editar"
        data-employee='<?= json_encode($emp) ?>'>
