# AST Team Performance - Reporte de Análisis para WFM

## Fecha: 11 de Marzo, 2026

## ⚠️ IMPORTANTE: Solo para Análisis

Los reportes AST Team Performance **NO crean campañas** en el sistema. Son únicamente para análisis en WFM Report.

### ¿Por qué no auto-crear campañas?

Las campañas en la tabla `campaigns` son parte de la estructura organizacional del sistema de ponche:
- Gestionan asignación de supervisores
- Controlan acceso de agentes
- Son parte de la configuración operativa del sistema

Los reportes de Vicidial (AST Team Performance) son **solo para análisis de métricas** y no deben modificar la estructura de la aplicación.

## Cambios Implementados

### 1. Sistema de Carga con Campaña Requerida

El sistema ahora requiere que selecciones una **campaña existente** a la cual asociar el reporte para análisis:

#### Funcionalidad:

- **Campaña Requerida**: Debes seleccionar una campaña existente antes de subir
- **Todos los Equipos a Una Campaña**: Todos los teams del archivo se asocian a la campaña seleccionada
- **Solo para Análisis**: Los datos solo se usan en WFM Report para visualización
- **Columnas Flexibles**: Detecta columnas faltantes y usa valores por defecto (0) cuando sea necesario

#### Equipos Detectados (ejemplo del archivo AST_team_performance_detail_20260306-180107.csv):

```
- ADMIN (VICIDIAL ADMINISTRATORS)
- DC_SPANISH (DC_SPANISH)
- DELIVERY (DELIVERY TEAM)
- JOSE (JOSE MENDEZ CO)
- KMPR2 (KMPR2)
- LATINOADVISORS (LATINOADVISORS)
- TOTAL (CALL CENTER TOTAL)
```

**Todos estos equipos se guardan con sus nombres originales** (`team_name`, `team_id`) pero asociados a la campaña que seleccionaste.

## Flujo de Trabajo

### Carga de Reporte

1. **Usuario**: Crea o selecciona una campaña existente (ej: "WFM Analysis" o "Vicidial Reports")
2. **Usuario**: Sube el archivo CSV completo 
3. **Sistema**: 
   - Detecta todos los TEAM headers en el archivo
   - Guarda cada team con su nombre original (`team_name`, `team_id`)
   - Asocia TODOS los datos a la campaña seleccionada
   - Procesa todas las columnas disponibles
4. **Resultado**: 
   - Datos importados correctamente
   - Listos para análisis en WFM Report

### Visualización en WFM Report

1. **WFM Report** usa los campos `team_name` y `team_id` para mostrar los equipos
2. Los datos se agrupan por equipo, no por campaña
3. Puedes ver métricas individuales por:
   - Equipo (ADMIN, DC_SPANISH, etc.)
   - Agente individual
   - Totales del Call Center

## Beneficios

1. ✅ **No Modifica Estructura**: No crea campañas innecesarias en el sistema
2. ✅ **Flexibilidad de Columnas**: Detecta y adapta columnas faltantes
3. ✅ **Análisis Detallado**: Mantiene información de cada equipo para análisis
4. ✅ **Un Solo Upload**: Procesa todos los equipos en una sola carga
5. ✅ **Datos Históricos**: Mantiene historial por fecha de reporte

## Tabla: campaign_ast_performance

Estructura para análisis:
- `campaign_id`: FK a campaigns (campaña seleccionada manualmente - usada solo para organización)
- `team_name`: Nombre del equipo (usado en WFM Report) ✅
- `team_id`: Código del equipo (usado en WFM Report) ✅
- `agent_name`: Nombre del agente
- `agent_id`: ID del agente
- Todas las métricas de performance (calls, sales, talk_time, etc.)

**Nota**: `team_name` y `team_id` son los campos importantes para análisis, no `campaign_id`.

## Modificaciones en campaign_sales.php

#### Cambios Clave:

1. **campaign_id es REQUERIDO**
   ```php
   $campaignId = isset($_POST['campaign_id']) ? (int) $_POST['campaign_id'] : 0;
   if ($campaignId <= 0) {
       jsonError('Debe seleccionar una campaña. Este reporte solo se usa para análisis en WFM Report.');
   }
   ```

2. **NO crea campañas automáticamente**
   - Removida la función `findOrCreateCampaign()`
   - Solo usa campañas existentes

3. **Tracking de Teams**
   ```php
   $teamsProcessed = []; // track teams found in file
   ```

4. **Todos los datos a una campaña**
   ```php
   $stmtAst->execute([
       $campaignId,  // Mismo campaign_id para todos los teams
       $reportDate,
       $currentTeamName,  // Team name preserved for analysis
       $currentTeamId,    // Team ID preserved for analysis
       // ... resto de datos
   ]);
   ```

5. **Respuesta Mejorada**
   ```json
   {
     "success": true,
     "teams_found": {
       "ADMIN": "VICIDIAL ADMINISTRATORS",
       "DC_SPANISH": "DC_SPANISH",
       "DELIVERY": "DELIVERY TEAM"
     },
     "teams_count": 7,
     "columns_found": [...],
     "columns_missing": [...]
   }
   ```

## Modificaciones en campaigns.php (Frontend)

#### Cambios en UI:

1. **Selector de Campaña Requerido**
   ```html
   <label>Campaña <span class="text-rose-400">*</span></label>
   <select id="salesCampaignSelect" required>
     <option value="">Selecciona una campaña...</option>
   </select>
   <p>📊 Todos los equipos del reporte se asociarán a esta campaña para análisis</p>
   ```

2. **Validación Actualizada**
   ```javascript
   if (!campaignId) {
     showSalesReportMessage('Selecciona una campaña para asociar el reporte de análisis.', 'error');
     return;
   }
   ```

3. **Feedback Mejorado**
   ```javascript
   if (data.teams_found && data.teams_count > 0) {
     message += `\n\n📊 Equipos encontrados en el archivo (${data.teams_count}):`;
     for (const [teamId, teamName] of Object.entries(data.teams_found)) {
       message += `\n  • ${teamId}: ${teamName}`;
     }
     message += `\n\nTodos asociados a la campaña seleccionada para análisis en WFM Report.`;
   }
   ```

## Compatibilidad con WFM Report

El WFM Report funciona perfectamente porque usa los campos de team:

#### Queries Usan Team Fields:

1. **Agrupación por Team**
   ```sql
   SELECT team_name, team_id, ...
   FROM campaign_ast_performance
   WHERE report_date BETWEEN ? AND ?
   GROUP BY team_name, team_id
   ```

2. **Totales del Call Center**
   ```sql
   WHERE team_name = 'CALL CENTER TOTAL'
   ```

3. **Visualización**
   - Tabla de equipos muestra `team_name` y `team_id`
   - No depende de `campaign_id` para mostrar datos
   - Cada equipo se muestra con su nombre original de Vicidial

## Recomendación de Uso

### Crear una campaña "WFM Analysis" o "Reportes Vicidial"

Sugerimos crear una campaña genérica para todos los reportes de análisis:

```sql
INSERT INTO campaigns (name, code, description, is_active) 
VALUES ('Reportes WFM', 'WFM_ANALYSIS', 'Reportes de Vicidial para análisis en WFM Report', 1);
```

Luego, siempre selecciona esta campaña al subir reportes AST Team Performance.

**Ventajas**:
- No mezclas datos de análisis con campañas operativas
- Fácil identificar qué datos son solo para WFM
- Puedes eliminar todos los reportes de análisis de una vez si es necesario

## Resultado Esperado

```
✅ Carga completada. Insertados: 150, Actualizados: 0, Omitidos: 0

📊 Equipos encontrados en el archivo (7):
  • ADMIN: VICIDIAL ADMINISTRATORS
  • DC_SPANISH: DC_SPANISH
  • DELIVERY: DELIVERY TEAM
  • JOSE: JOSE MENDEZ CO
  • KMPR2: KMPR2
  • LATINOADVISORS: LATINOADVISORS
  • TOTAL: CALL CENTER TOTAL

Todos asociados a la campaña seleccionada para análisis en WFM Report.

Columnas encontradas: 20
```

## Archivos Modificados

1. `api/campaign_sales.php` - Removida auto-creación de campañas
2. `hr/campaigns.php` - Campaña requerida con explicación clara
3. `assets/css/theme.css` - Estilos para warnings (sin cambios)

## Archivos Sin Cambios (Compatibles)

1. `wfm_report.php` - Funciona perfectamente usando team_name/team_id
2. Base de datos - Estructura existente sin cambios
3. Otros reportes - No afectados

#### Funcionalidad:

- **Detección Automática**: El sistema detecta automáticamente todos los equipos (TEAMS) del archivo CSV
- **Creación Automática**: Si un equipo no existe como campaña, se crea automáticamente usando el Team ID como código
- **Asociación Individual**: Cada equipo se asocia con su propia campaña en la base de datos
- **Columnas Flexibles**: Detecta columnas faltantes y usa valores por defecto (0) cuando sea necesario

#### Equipos Detectados (ejemplo del archivo AST_team_performance_detail_20260306-180107.csv):

```
- ADMIN (VICIDIAL ADMINISTRATORS)
- DC_SPANISH (DC_SPANISH)
- DELIVERY (DELIVERY TEAM)
- JOSE (JOSE MENDEZ CO)
- KMPR2 (KMPR2)
- LATINOADVISORS (LATINOADVISORS)
- TOTAL (CALL CENTER TOTAL)
```

### 2. Modificaciones en campaign_sales.php

#### Cambios Clave:

1. **campaign_id ahora es opcional**
   ```php
   $manualCampaignId = isset($_POST['campaign_id']) ? (int) $_POST['campaign_id'] : 0;
   $autoDetectCampaigns = ($manualCampaignId <= 0);
   ```

2. **Función findOrCreateCampaign()**
   - Busca campañas existentes por código (team_id)
   - Crea nuevas campañas automáticamente si no existen
   - Retorna el campaign_id correspondiente

3. **Mapeo de Teams a Campañas**
   ```php
   $campaignMap = []; // team_id -> campaign_id
   $processedCampaigns = []; // track processed campaigns
   ```

4. **Eliminación Selectiva**
   - Si se auto-detectan: elimina datos por campaña individual + fecha
   - Si es manual: elimina todos los datos de esa campaña + fecha

5. **Respuesta Mejorada**
   ```json
   {
     "success": true,
     "auto_detected": true,
     "campaigns_processed": {
       "ADMIN": "VICIDIAL ADMINISTRATORS",
       "DC_SPANISH": "DC_SPANISH",
       "DELIVERY": "DELIVERY TEAM"
     },
     "campaigns_count": 7,
     "columns_found": [...],
     "columns_missing": [...]
   }
   ```

### 3. Modificaciones en campaigns.php (Frontend)

#### Cambios en UI:

1. **Selector de Campaña Opcional**
   ```html
   <label>Campaña <span>(opcional)</span></label>
   <select id="salesCampaignSelect">
     <option value="">Auto-detectar del archivo...</option>
   </select>
   <p>🪄 Deja vacío para detectar automáticamente todas las campañas del archivo</p>
   ```

2. **Validación Actualizada**
   - Ya no requiere campaña seleccionada
   - Solo requiere el archivo CSV

3. **Feedback Mejorado**
   ```javascript
   if (data.auto_detected && data.campaigns_processed) {
     message += `\n\n🎯 Campañas auto-detectadas (${data.campaigns_count}):`;
     for (const [teamId, teamName] of Object.entries(data.campaigns_processed)) {
       message += `\n  • ${teamId}: ${teamName}`;
     }
   }
   ```

4. **Recarga Automática**
   - Después de auto-detectar y crear campañas, recarga la lista de campañas

### 4. Compatibilidad con WFM Report

El WFM Report funciona perfectamente con el nuevo sistema porque:

#### Queries Mantienen Compatibilidad:

1. **Agrupación por Team**
   ```sql
   GROUP BY team_name, team_id
   ```
   - No depende del campaign_id para agrupar
   - Usa team_name y team_id directamente

2. **Totales del Call Center**
   ```sql
   WHERE team_name = 'CALL CENTER TOTAL'
   ```
   - El registro TOTAL se guarda correctamente
   - Los KPI cards muestran los totales correctos

3. **Visualización**
   - Tabla de equipos muestra todos los teams correctamente
   - Top 20 agentes funciona sin cambios
   - Gráficos y métricas siguen funcionando

#### Mejora en Opciones de Eliminación:

Ahora el dropdown de eliminación muestra cada campaña individualmente:
```
- ADMIN - 2026-03-06
- DC_SPANISH - 2026-03-06
- DELIVERY - 2026-03-06
- JOSE - 2026-03-06
- etc.
```

Esto da más control granular para eliminar datos específicos.

## Flujo de Trabajo

### Modo Auto-detección (Recomendado)

1. **Usuario**: Sube el archivo CSV completo sin seleccionar campaña
2. **Sistema**: 
   - Detecta todos los TEAM headers en el archivo
   - Crea o encuentra campañas para cada team
   - Asocia los datos de cada team con su campaña
   - Procesa todas las columnas disponibles
3. **Resultado**: 
   - Todas las campañas creadas/actualizadas
   - Datos importados correctamente
   - Notificación de campañas procesadas

### Modo Manual (Opcional)

1. **Usuario**: Selecciona una campaña específica y sube el archivo
2. **Sistema**:
   - Asocia TODOS los teams del archivo con esa campaña única
   - Útil para casos especiales o reportes consolidados
3. **Resultado**:
   - Todos los datos asociados a una sola campaña

## Beneficios

1. ✅ **Sin Configuración Previa**: No necesita crear campañas manualmente antes de subir
2. ✅ **Proceso Único**: Un solo upload procesa todas las campañas
3. ✅ **Flexibilidad de Columnas**: Detecta y adapta columnas faltantes
4. ✅ **Historial Completo**: Mantiene datos históricos por campaña
5. ✅ **Control Granular**: Puede eliminar datos por campaña individual
6. ✅ **Compatibilidad Total**: Funciona con WFM Report sin cambios

## Tabla: campaign_ast_performance

Estructura sin cambios:
- `campaign_id`: FK a campaigns (ahora una por team)
- `team_name`: Nombre del equipo
- `team_id`: Código del equipo
- `agent_name`: Nombre del agente
- `agent_id`: ID del agente
- Todas las métricas de performance

## Tabla: campaigns

Campos usados para auto-creación:
- `name`: Team Name (ej: "VICIDIAL ADMINISTRATORS")
- `code`: Team ID (ej: "ADMIN")
- `description`: "Auto-created from AST Team Performance Report"
- `status`: 'active'

## Notas Técnicas

### Detección de Team Headers

```php
if (preg_match('/^TEAM:\s*(\S+)\s*-\s*(.+)$/i', trim($row[1]), $teamMatch)) {
    $currentTeamId = trim($teamMatch[1]);    // ej: "ADMIN"
    $currentTeamName = trim($teamMatch[2]);  // ej: "VICIDIAL ADMINISTRATORS"
    
    if ($autoDetectCampaigns) {
        $campaignMap[$currentTeamId] = findOrCreateCampaign($pdo, $currentTeamId, $currentTeamName);
    }
}
```

### Manejo de CALL CENTER TOTAL

```php
if (trim($row[1]) === 'CALL CENTER TOTAL') {
    $currentTeamId = 'TOTAL';
    $currentTeamName = 'CALL CENTER TOTAL';
    
    if ($autoDetectCampaigns) {
        $campaignMap[$currentTeamId] = findOrCreateCampaign($pdo, $currentTeamId, $currentTeamName);
    }
}
```

### Inserción con Campaign ID Dinámico

```php
$stmtAst->execute([
    $currentCampaignId,  // Cambia dinámicamente según el team
    $reportDate,
    $currentTeamName,
    $currentTeamId,
    // ... resto de datos
]);
```

## Testing

Para probar:

1. Ir a: `/hr/campaigns.php`
2. Sección: "Carga de Reporte de Ventas (AST Team Performance)"
3. **No seleccionar campaña** (dejar en "Auto-detectar del archivo...")
4. Subir archivo: `AST_team_performance_detail_20260306-180107.csv`
5. Verificar mensaje de éxito con campañas detectadas
6. Ir a: `/wfm_report.php`
7. Pestaña: "AST Team Performance"
8. Verificar que se muestren todos los equipos correctamente

## Resultado Esperado

```
✅ Carga completada. Insertados: 150, Actualizados: 0, Omitidos: 0

🎯 Campañas auto-detectadas (7):
  • ADMIN: VICIDIAL ADMINISTRATORS
  • DC_SPANISH: DC_SPANISH
  • DELIVERY: DELIVERY TEAM
  • JOSE: JOSE MENDEZ CO
  • KMPR2: KMPR2
  • LATINOADVISORS: LATINOADVISORS
  • TOTAL: CALL CENTER TOTAL

Columnas encontradas: 20
```

## Archivos Modificados

1. `api/campaign_sales.php` - Lógica de backend
2. `hr/campaigns.php` - Interfaz de usuario
3. `assets/css/theme.css` - Estilos para warnings (agregado anteriormente)

## Archivos Sin Cambios (Compatibles)

1. `wfm_report.php` - Funciona sin modificaciones
2. Base de datos - Estructura existente sin cambios
3. Otros reportes - No afectados
