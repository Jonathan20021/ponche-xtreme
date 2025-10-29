# Módulo de Recursos Humanos - Ponche Xtreme

## 📋 Descripción General

El Módulo de Recursos Humanos es un sistema completo de gestión de personal integrado con el sistema de ponche. Proporciona herramientas avanzadas para la administración de empleados, nómina, permisos, vacaciones y más.

## ✨ Características Principales

### 1. **Gestión de Empleados**
- ✅ Registro completo de empleados con información detallada
- ✅ Creación automática de empleado al registrar usuario
- ✅ Perfiles completos con datos personales y laborales
- ✅ Gestión de departamentos y posiciones
- ✅ Estados de empleo (Activo, Prueba, Suspendido, Terminado)
- ✅ Búsqueda y filtrado avanzado

### 2. **Período de Prueba (90 días)**
- ✅ Seguimiento automático de empleados en período de prueba
- ✅ Cálculo de días transcurridos y restantes
- ✅ Alertas para períodos próximos a vencer
- ✅ Barra de progreso visual
- ✅ Aprobación o terminación de empleados

### 3. **Control de Nómina**
- ✅ Creación de períodos de nómina personalizados
- ✅ Cálculo automático basado en datos de ponche
- ✅ Horas regulares y horas extras
- ✅ Multiplicadores de horas extras configurables
- ✅ Reportes detallados por empleado
- ✅ Totales y resúmenes por período

### 4. **Cumpleaños de Empleados**
- ✅ Calendario de cumpleaños por mes
- ✅ Alertas de cumpleaños del día
- ✅ Vista de cumpleaños de la semana
- ✅ Cálculo automático de edad
- ✅ Interfaz visual atractiva

### 5. **Solicitudes de Permisos**
- ✅ Formulario de solicitud de permisos
- ✅ Tipos: Permiso, Licencia Médica, Personal, Médico, Otro
- ✅ Fechas y horas configurables
- ✅ Flujo de aprobación/rechazo
- ✅ Notas y comentarios
- ✅ Historial completo

### 6. **Solicitudes de Vacaciones**
- ✅ Gestión de solicitudes de vacaciones
- ✅ Balance de días por empleado
- ✅ Tipos: Anuales, No Remuneradas, Compensatorias
- ✅ Cálculo automático de días
- ✅ Actualización automática de balance al aprobar
- ✅ Reportes de uso de vacaciones

### 7. **Calendario Integrado**
- ✅ Vista mensual con todos los eventos
- ✅ Cumpleaños, permisos y vacaciones en un solo lugar
- ✅ Código de colores por tipo de evento
- ✅ Navegación por meses
- ✅ Listas detalladas por categoría

## 🗄️ Estructura de Base de Datos

### Tablas Principales

#### `employees`
Información completa de empleados
- Datos personales (nombre, email, teléfono, fecha de nacimiento)
- Datos laborales (posición, departamento, fecha de ingreso)
- Estado de empleo y tipo de contrato
- Contactos de emergencia
- Documentos de identificación

#### `payroll_periods`
Períodos de nómina
- Nombre del período
- Fechas de inicio y fin
- Fecha de pago
- Estado (Abierto, Procesando, Pagado, Cerrado)

#### `payroll_records`
Registros de nómina por empleado
- Horas regulares y extras
- Tarifas y multiplicadores
- Pagos calculados
- Bonos y deducciones
- Total bruto y neto

#### `permission_requests`
Solicitudes de permisos
- Tipo de permiso
- Fechas y horas
- Motivo
- Estado (Pendiente, Aprobado, Rechazado)
- Revisor y notas

#### `vacation_requests`
Solicitudes de vacaciones
- Fechas de inicio y fin
- Tipo de vacaciones
- Días totales
- Estado y aprobación

#### `vacation_balances`
Balance de vacaciones por empleado
- Año
- Días totales, usados y disponibles

## 📁 Estructura de Archivos

```
ponche-xtreme/
├── hr/
│   ├── index.php              # Dashboard principal de HR
│   ├── employees.php          # Gestión de empleados
│   ├── trial_period.php       # Empleados en período de prueba
│   ├── payroll.php            # Control de nómina
│   ├── birthdays.php          # Cumpleaños de empleados
│   ├── permissions.php        # Solicitudes de permisos
│   ├── vacations.php          # Solicitudes de vacaciones
│   └── calendar.php           # Calendario integrado
├── migrations/
│   └── create_hr_module.sql   # Script de creación de tablas
├── register.php               # Registro de empleados (modificado)
└── HR_MODULE_README.md        # Esta documentación
```

## 🚀 Instalación

### Paso 1: Ejecutar Script de Base de Datos

```sql
-- Ejecutar en phpMyAdmin o línea de comandos MySQL
SOURCE migrations/create_hr_module.sql;
```

O importar el archivo `migrations/create_hr_module.sql` desde phpMyAdmin.

### Paso 2: Verificar Permisos

El script automáticamente agrega los permisos necesarios a la tabla `section_permissions`. Los roles con acceso son:
- Admin
- HR
- IT

### Paso 3: Acceder al Módulo

1. Iniciar sesión con un usuario que tenga rol Admin, HR o IT
2. Navegar a: `http://tu-servidor/ponche-xtreme/hr/`

## 📝 Uso del Sistema

### Registrar un Nuevo Empleado

1. Ir a `register.php` o hacer clic en "Nuevo Empleado" desde HR
2. Completar el formulario con:
   - Datos básicos (usuario, nombre completo)
   - Información personal (nombre, apellido, email, teléfono)
   - Fecha de nacimiento y fecha de ingreso
   - Posición y departamento
   - Tarifa por hora
3. El sistema automáticamente:
   - Genera un código de empleado (EMP-YYYY-XXXX)
   - Crea el usuario en la tabla `users`
   - Crea el registro en la tabla `employees`
   - Establece el estado como "TRIAL" (período de prueba)

### Gestionar Período de Prueba

1. Ir a `hr/trial_period.php`
2. Ver empleados en período de prueba con:
   - Días transcurridos y restantes
   - Barra de progreso
   - Fecha de finalización
3. Aprobar o terminar empleados según evaluación

### Calcular Nómina

1. Ir a `hr/payroll.php`
2. Crear un nuevo período de nómina:
   - Nombre (ej: "Quincena 1 - Enero 2025")
   - Fecha de inicio y fin
   - Fecha de pago (opcional)
3. Hacer clic en "Calcular" para generar la nómina
4. El sistema automáticamente:
   - Obtiene datos de asistencia del período
   - Calcula horas regulares y extras
   - Aplica tarifas y multiplicadores
   - Genera registro por cada empleado

### Gestionar Permisos y Vacaciones

1. **Crear Solicitud:**
   - Ir a `hr/permissions.php` o `hr/vacations.php`
   - Hacer clic en "Nueva Solicitud"
   - Completar formulario
   - Enviar

2. **Revisar Solicitud:**
   - Ver solicitudes pendientes
   - Hacer clic en "Aprobar" o "Rechazar"
   - Agregar notas (opcional)
   - Confirmar

3. **Balance de Vacaciones:**
   - Se actualiza automáticamente al aprobar vacaciones
   - Ver balance en `hr/vacations.php`

### Ver Calendario

1. Ir a `hr/calendar.php`
2. Navegar por meses usando las flechas
3. Ver eventos codificados por color:
   - 🎂 Rosa: Cumpleaños
   - 📋 Morado: Permisos
   - 🏖️ Cyan: Vacaciones

## 🎨 Características de Diseño

- **Tema Oscuro/Claro:** Compatible con el sistema de temas existente
- **Responsive:** Funciona en dispositivos móviles y tablets
- **Glassmorphism:** Efectos de vidrio modernos
- **Gradientes:** Colores vibrantes y atractivos
- **Iconos Font Awesome:** Interfaz visual intuitiva
- **Animaciones:** Transiciones suaves

## 🔐 Seguridad

- ✅ Verificación de permisos en cada página
- ✅ Validación de datos en servidor
- ✅ Protección contra SQL injection (PDO prepared statements)
- ✅ Sesiones seguras
- ✅ Control de acceso basado en roles

## 📊 Reportes y Estadísticas

Cada módulo incluye estadísticas en tiempo real:
- Total de empleados por estado
- Permisos y vacaciones pendientes
- Cumpleaños próximos
- Empleados finalizando período de prueba
- Totales de nómina por período

## 🔄 Integración con Sistema de Ponche

El módulo de nómina está completamente integrado con el sistema de ponche:
- Lee datos de la tabla `attendance`
- Calcula horas trabajadas automáticamente
- Resta tiempo de breaks y lunch
- Calcula horas extras según configuración
- Usa tarifas de la tabla `users`

## 🛠️ Configuración Avanzada

### Multiplicadores de Horas Extras

1. **Global:** En `schedule_config` tabla
2. **Por Empleado:** En `users` tabla (campo `overtime_multiplier`)

### Balance de Vacaciones

- Por defecto: 14 días anuales
- Configurable por empleado en tabla `vacation_balances`

### Tipos de Permisos

Predefinidos:
- PERMISSION (Permiso)
- SICK_LEAVE (Licencia Médica)
- PERSONAL (Personal)
- MEDICAL (Médico)
- OTHER (Otro)

### Tipos de Vacaciones

Predefinidos:
- ANNUAL (Anuales)
- UNPAID (No Remuneradas)
- COMPENSATORY (Compensatorias)

## 📞 Soporte

Para soporte o preguntas sobre el módulo de HR, contactar al departamento de IT o al administrador del sistema.

## 🎯 Próximas Mejoras Sugeridas

- [ ] Exportación de nómina a Excel
- [ ] Reportes PDF personalizables
- [ ] Notificaciones por email
- [ ] Firma digital de documentos
- [ ] Portal de autoservicio para empleados
- [ ] Evaluaciones de desempeño
- [ ] Capacitaciones y certificaciones
- [ ] Gestión de beneficios

## 📄 Licencia

Este módulo es parte del sistema Ponche Xtreme y está sujeto a los mismos términos de licencia.

---

**Desarrollado con ❤️ para una mejor gestión de Recursos Humanos**
