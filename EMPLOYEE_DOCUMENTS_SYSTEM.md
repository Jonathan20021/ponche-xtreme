# Sistema de Documentos de Empleados - Record Digital de HR

## Descripción General

Sistema completo de gestión documental para mantener el record de Recursos Humanos totalmente digitalizado. Permite cargar, organizar y gestionar todos los documentos de los empleados como cédulas, títulos, certificados, contratos y cualquier otro documento del record de HR.

## Características Principales

### 1. Gestión Completa de Documentos
- **Subida de Archivos**: Soporte para múltiples formatos (PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, ZIP, etc.)
- **Categorización**: Documentos organizados por tipo y categoría
- **Búsqueda y Filtrado**: Búsqueda rápida por nombre o tipo de documento
- **Descarga Segura**: Descarga de documentos con control de permisos
- **Eliminación Controlada**: Eliminación con confirmación y registro en logs

### 2. Categorías de Documentos

#### Identificación
- Cédula
- Pasaporte
- Licencia de Conducir
- Otros documentos de identidad

#### Educación
- Diploma de Bachiller
- Título Universitario
- Certificado Técnico
- Maestría
- Doctorado
- Certificaciones profesionales

#### Laboral
- Contrato de Trabajo
- Carta de Oferta
- Evaluación de Desempeño
- Carta de Recomendación
- Certificado Laboral

#### Médico
- Certificado Médico
- Examen Pre-empleo
- Seguro Médico
- Registro de Vacunas
- Información de Alergias

#### Financiero
- Información Bancaria
- Comprobante de Cuenta
- TSS (Seguridad Social)
- AFP (Pensiones)

#### Legal
- Acuerdo de Confidencialidad
- Política de Empresa
- Código de Conducta

#### Personal
- Fotografía
- CV/Resume
- Referencias
- Acta de Nacimiento
- Certificado de Matrimonio

#### Otros
- Cualquier otro documento relevante

### 3. Características Técnicas

#### Seguridad
- Control de permisos mediante `hr_employee_documents`
- Validación de tipos de archivo permitidos
- Límite de tamaño de archivo (10MB)
- Almacenamiento organizado por código de empleado
- Registro de todas las acciones en activity logs

#### Almacenamiento
- Directorio: `uploads/employee_documents/[EMPLOYEE_CODE]/`
- Nombres únicos con timestamp para evitar conflictos
- Organización automática por empleado

#### Base de Datos
Tabla: `employee_documents`
- `id`: ID único del documento
- `employee_id`: Referencia al empleado
- `document_type`: Tipo/categoría del documento
- `document_name`: Nombre original del archivo
- `file_path`: Ruta relativa del archivo
- `file_size`: Tamaño en bytes
- `file_extension`: Extensión del archivo
- `description`: Descripción opcional
- `uploaded_by`: Usuario que subió el documento
- `uploaded_at`: Fecha de subida
- `updated_at`: Última actualización

## Archivos del Sistema

### Archivos PHP
1. **employee_documents.php**: Página principal de gestión de documentos
2. **upload_employee_document.php**: API para subir documentos
3. **get_employee_documents.php**: API para obtener lista de documentos
4. **download_employee_document.php**: Descarga segura de documentos
5. **delete_employee_document.php**: Eliminación de documentos

### Migración
- **add_employee_documents.sql**: Crea tabla y permisos necesarios

### Integración
- **employee_profile.php**: Integrado con sección de documentos y contador

## Uso del Sistema

### Para Acceder
1. Ir a **HR** → **Empleados**
2. Seleccionar un empleado y hacer clic en **Ver**
3. En el perfil del empleado, hacer clic en **Ver Todos los Documentos**

### Para Subir Documentos
1. Hacer clic en **Subir Documento**
2. Seleccionar el tipo de documento de la lista
3. Agregar descripción opcional
4. Arrastrar el archivo o hacer clic para seleccionar
5. Hacer clic en **Subir Documento**

### Para Descargar Documentos
- Hacer clic en el botón **Descargar** en la tarjeta del documento

### Para Eliminar Documentos
- Hacer clic en el botón de **eliminar** (icono de basura)
- Confirmar la eliminación

### Para Buscar/Filtrar
- Usar el filtro de categoría para ver documentos específicos
- Usar el campo de búsqueda para encontrar por nombre

## Permisos

### Permiso Requerido
- `hr_employee_documents`: Gestionar documentos de empleados

### Roles con Acceso
- **Admin**: Acceso completo
- **HR**: Acceso completo

## Instalación

### 1. Ejecutar Migración
```sql
-- Ejecutar el archivo de migración
SOURCE migrations/add_employee_documents.sql;
```

O ejecutar manualmente en phpMyAdmin/MySQL:
```bash
mysql -u root -p ponche < migrations/add_employee_documents.sql
```

### 2. Verificar Permisos
Asegurarse de que el directorio `uploads/employee_documents/` tenga permisos de escritura:
```bash
chmod 755 uploads/employee_documents/
```

### 3. Verificar Configuración PHP
Asegurarse de que `php.ini` permita subidas de archivos:
```ini
file_uploads = On
upload_max_filesize = 10M
post_max_size = 10M
```

## Formatos de Archivo Soportados

### Documentos
- PDF (.pdf)
- Word (.doc, .docx)
- Excel (.xls, .xlsx)
- PowerPoint (.ppt, .pptx)
- OpenDocument (.odt, .ods)
- Texto (.txt, .rtf)

### Imágenes
- JPEG (.jpg, .jpeg)
- PNG (.png)
- GIF (.gif)
- BMP (.bmp)

### Archivos Comprimidos
- ZIP (.zip)
- RAR (.rar)
- 7-Zip (.7z)

## Estadísticas Disponibles

En la página de documentos se muestran:
- **Total de Documentos**: Cantidad total de documentos del empleado
- **Categorías**: Número de categorías diferentes
- **Tamaño Total**: Espacio ocupado por todos los documentos
- **Último Documento**: Fecha del último documento subido

## Logging y Auditoría

Todas las acciones se registran en `activity_logs`:
- Subida de documentos
- Eliminación de documentos
- Usuario que realizó la acción
- Fecha y hora de la acción

## Interfaz de Usuario

### Características de la UI
- **Drag & Drop**: Arrastra archivos para subirlos
- **Vista de Tarjetas**: Documentos mostrados en tarjetas con iconos según tipo
- **Colores por Tipo**: Cada tipo de archivo tiene su color distintivo
- **Responsive**: Funciona en móviles, tablets y escritorio
- **Búsqueda en Tiempo Real**: Filtrado instantáneo
- **Agrupación por Categoría**: Documentos agrupados automáticamente

### Iconos por Tipo de Archivo
- PDF: Icono rojo
- Word: Icono azul
- Excel: Icono verde
- Imágenes: Icono morado
- Archivos comprimidos: Icono naranja

## Mantenimiento

### Limpieza de Archivos Huérfanos
Si se eliminan empleados, sus documentos se eliminan automáticamente gracias a `ON DELETE CASCADE`.

### Backup
Incluir el directorio `uploads/employee_documents/` en los backups regulares del sistema.

## Beneficios del Sistema

1. **Digitalización Completa**: Elimina la necesidad de archivos físicos
2. **Acceso Rápido**: Encuentra documentos en segundos
3. **Organización**: Todos los documentos categorizados y organizados
4. **Seguridad**: Control de acceso y auditoría completa
5. **Espacio**: Ahorra espacio físico de almacenamiento
6. **Cumplimiento**: Facilita auditorías y cumplimiento normativo
7. **Respaldo**: Documentos protegidos con backups digitales

## Soporte

Para problemas o preguntas sobre el sistema de documentos:
1. Verificar permisos de usuario
2. Verificar permisos de directorio
3. Revisar logs de actividad
4. Verificar configuración de PHP para subida de archivos
