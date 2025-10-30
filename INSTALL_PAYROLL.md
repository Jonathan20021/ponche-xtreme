# 📋 INSTALACIÓN DEL SISTEMA DE NÓMINA RD

## ✅ Paso 1: Instalar Dependencias PHP

```bash
composer update
```

Esto instalará:
- **DomPDF** (exportación a PDF)
- **PhpSpreadsheet** (exportación a Excel)

## ✅ Paso 2: Ejecutar Migración SQL

Ejecuta el archivo SQL en tu base de datos:

```bash
mysql -u root -p ponche < migrations/create_payroll_system.sql
```

O desde phpMyAdmin:
1. Abre phpMyAdmin
2. Selecciona la base de datos `ponche`
3. Ve a la pestaña "SQL"
4. Copia y pega el contenido de `migrations/create_payroll_system.sql`
5. Haz clic en "Continuar"

## 📊 Tablas Creadas

La migración creará las siguientes tablas:

1. **`payroll_deduction_config`** - Configuración de descuentos legales (AFP, SFS, ISR, etc.)
2. **`payroll_isr_scales`** - Escalas de ISR 2025
3. **`employee_deductions`** - Descuentos personalizados por empleado
4. **`payroll_periods`** - Períodos de nómina
5. **`payroll_records`** - Registros detallados de nómina por empleado
6. **`salary_history`** - Historial de cambios de salario

## 🎯 Datos Iniciales

Se insertarán automáticamente:

### Descuentos Legales RD 2025:
- **AFP**: 2.87% (empleado) + 7.10% (patronal)
- **SFS**: 3.04% (empleado) + 7.09% (patronal)
- **SRL**: 1.20% (solo patronal)
- **INFOTEP**: 1.00% (solo patronal)
- **ISR**: Escala progresiva

### Escala ISR 2025 (Anual):
- Hasta RD$416,220.00: **Exento**
- RD$416,220.01 - RD$624,329.00: **15%** sobre excedente
- RD$624,329.01 - RD$867,123.00: RD$31,216 + **20%** sobre excedente
- Más de RD$867,123.01: RD$79,775 + **25%** sobre excedente

## 🚀 Uso del Sistema

### Acceder al Módulo:
1. Ve a **Recursos Humanos** en el menú
2. Haz clic en **Nómina RD**
3. O accede directamente: `http://localhost/ponche-xtreme/hr/payroll.php`

### Crear un Período de Nómina:
1. Haz clic en **"Nuevo Período"**
2. Completa:
   - Nombre (ej: "Quincena 1 - Enero 2025")
   - Tipo (Quincenal, Mensual, Semanal)
   - Fechas de inicio y fin
   - Fecha de pago
3. Guarda

### Calcular Nómina:
1. Selecciona un período
2. Haz clic en **"Calcular"**
3. El sistema:
   - Obtiene las horas trabajadas desde la asistencia
   - Calcula horas extras
   - Aplica descuentos legales (AFP, SFS, ISR)
   - Calcula aportes patronales
   - Genera el salario neto

### Exportar Reportes:
- **PDF**: Reporte completo con firmas
- **Excel**: Formato editable con fórmulas
- **TSS**: Reporte para Seguridad Social
- **DGII**: Reporte de retenciones ISR

## 📁 Archivos del Sistema

```
hr/
├── payroll.php                  # Interfaz principal
├── payroll_functions.php        # Funciones de cálculo
├── payroll_export_pdf.php       # Exportación PDF
├── payroll_export_excel.php     # Exportación Excel
├── payroll_tss.php              # Reporte TSS
└── payroll_dgii.php             # Reporte DGII

migrations/
└── create_payroll_system.sql    # Migración de BD
```

## ⚙️ Configuración Adicional

### Agregar Descuentos Personalizados:
Los descuentos personalizados (préstamos, seguros privados, etc.) se pueden agregar directamente en la base de datos:

```sql
INSERT INTO employee_deductions (employee_id, name, type, amount, is_active) 
VALUES (1, 'Préstamo Personal', 'FIXED', 500.00, 1);
```

### Ajustar Tasas:
Si cambian las tasas legales, actualiza en `payroll_deduction_config`:

```sql
UPDATE payroll_deduction_config 
SET employee_percentage = 3.00 
WHERE code = 'AFP';
```

## 🔒 Permisos Requeridos

Asegúrate de que el usuario tenga el permiso:
- `hr_payroll` - Para acceder al módulo de nómina

## 📞 Soporte

Si encuentras algún error:
1. Verifica que las tablas se crearon correctamente
2. Revisa que composer instaló DomPDF y PhpSpreadsheet
3. Verifica que los empleados tengan `user_id` válido
4. Asegúrate de que hay registros de asistencia para el período

## ✨ Características

✅ Cálculo automático desde asistencia
✅ Descuentos legales RD 2025
✅ Escala ISR progresiva
✅ Aportes patronales completos
✅ Exportación PDF profesional
✅ Exportación Excel con formato
✅ Reportes TSS y DGII
✅ Historial de salarios
✅ Descuentos personalizables

---

**Sistema de Nómina RD v1.0**
Compatible con normativas TSS y DGII 2025
