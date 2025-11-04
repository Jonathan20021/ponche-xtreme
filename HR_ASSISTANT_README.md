# Asistente Virtual de Recursos Humanos

## 📋 Descripción

Sistema de chatbot inteligente para Recursos Humanos que utiliza la API de Google Gemini para responder preguntas frecuentes de los empleados sobre vacaciones, permisos, horarios, evaluaciones y políticas de la empresa.

## ✨ Características

- **Chat en tiempo real** con IA Gemini conectada DIRECTAMENTE a la base de datos
- **Datos 100% REALES** - Extrae información en tiempo real de las tablas de la BD
- **Respuestas personalizadas** basadas en datos actuales del empleado
- **Interfaz moderna y responsive** con animaciones fluidas
- **Preguntas rápidas** para consultas comunes
- **Historial de conversación** mantenido durante la sesión
- **Contexto completo** del empleado (vacaciones, horarios, asistencia, permisos, etc.)

## 🚀 Funcionalidades

El asistente extrae datos REALES de estas tablas:

- ✅ **users** - Información básica del empleado
- ✅ **employees** - Datos completos de empleado (puesto, departamento, fechas)
- ✅ **vacation_balances** - Balance real de días de vacaciones
- ✅ **vacation_requests** - Solicitudes de vacaciones del empleado
- ✅ **permission_requests** - Permisos solicitados y su estado
- ✅ **medical_leaves** - Licencias médicas
- ✅ **attendance** - Registros de asistencia (últimos 30 días)
- ✅ **employee_schedules** - Horarios personalizados
- ✅ **schedule_config** - Configuración global de horarios
- ✅ **calendar_events** - Eventos próximos de la empresa
- ✅ **employee_documents** - Documentos del empleado
- ✅ **departments** - Información de departamentos
- ✅ **banks** - Datos bancarios

## 📁 Archivos Creados

### Backend
- `lib/hr_assistant_functions.php` - Funciones para obtener datos de empleados
- `lib/gemini_api.php` - Integración con la API de Google Gemini
- `hr/hr_assistant_api.php` - Endpoint API para procesar mensajes del chat

### Frontend
- `hr/hr_assistant.php` - Página principal del asistente con interfaz de chat

### Base de Datos
- `migrations/add_hr_knowledge_base.sql` - Tablas para base de conocimientos y historial

## 🔧 Instalación

### 1. Ejecutar la migración de base de datos

```bash
mysql -u hhempeos_ponche -p hhempeos_ponche < migrations/add_hr_knowledge_base.sql
```

O ejecutar manualmente el archivo SQL en phpMyAdmin.

### 2. Verificar permisos

El asistente está disponible para usuarios con permiso `hr_dashboard`. Ya está agregado al menú de Recursos Humanos.

### 3. Acceder al asistente

Navega a: **Recursos Humanos > Asistente Virtual**

## 🔑 API Key de Gemini

La API key de Google Gemini ya está configurada en el código:
- **API Key**: `AIzaSyBsNFvo5gaMsHcQTKRsYQ5ElSQBVN5ulZ0`
- **Modelo**: `gemini-2.0-flash-exp`
- **Endpoint**: `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent`

## 💡 Uso

1. **Accede** al módulo de Asistente Virtual desde el menú de RH
2. **Escribe** tu pregunta en el campo de texto o usa los botones de preguntas rápidas
3. **Recibe** respuestas personalizadas basadas en tus datos de empleado
4. **Continúa** la conversación - el asistente mantiene el contexto

### Ejemplos de preguntas:

- "¿Cuántos días de vacaciones me quedan?"
- "¿Cómo solicito un permiso?"
- "¿Cuál es mi horario de trabajo?"
- "¿Cuándo es mi próxima evaluación?"
- "¿Qué documentos necesito presentar?"

## 🎨 Interfaz

- **Diseño moderno** con gradientes y animaciones
- **Modo oscuro/claro** compatible con el tema del sistema
- **Responsive** - funciona en móviles y tablets
- **Indicador de escritura** mientras la IA procesa
- **Auto-scroll** para seguir la conversación
- **Textarea expandible** para mensajes largos

## 🔒 Seguridad

- ✅ Autenticación requerida
- ✅ Verificación de permisos
- ✅ Datos personalizados por usuario
- ✅ Validación de entrada
- ✅ Manejo de errores robusto

## 📊 Base de Conocimientos

La tabla `hr_knowledge_base` incluye 15 preguntas frecuentes pre-cargadas sobre:
- Vacaciones
- Permisos
- Horarios
- Evaluaciones
- Beneficios
- Documentos
- Políticas

Puedes agregar más entradas directamente en la base de datos.

## 🔄 Historial de Chat

Opcionalmente, los chats se pueden guardar en `hr_assistant_chat_history` para:
- Análisis de preguntas frecuentes
- Mejora continua del asistente
- Auditoría de consultas

## 🛠️ Personalización

### Modificar el contexto del sistema
Edita `GeminiAPI::buildSystemContext()` en `lib/gemini_api.php`

### Agregar nuevas funciones de datos
Agrega funciones en `lib/hr_assistant_functions.php`

### Cambiar el estilo
Modifica los estilos CSS en `hr/hr_assistant.php`

## 📝 Notas Técnicas

- **Temperatura**: 0.8 para respuestas naturales pero consistentes
- **Max tokens**: 2048 para respuestas completas
- **Timeout**: 30 segundos para la API
- **Encoding**: UTF-8 para soporte completo de español

## 🐛 Solución de Problemas

### Error de conexión a la API
- Verifica que la API key sea válida
- Comprueba la conexión a internet del servidor
- Revisa los logs de PHP para errores de cURL

### No se muestran datos del empleado
- Verifica que las tablas necesarias existan
- Comprueba que el usuario tenga datos completos
- Revisa los permisos de base de datos

### Respuestas lentas
- La API de Gemini puede tardar 2-5 segundos
- Considera implementar caché para preguntas frecuentes
- Optimiza las consultas a la base de datos

## 📈 Mejoras Futuras

- [ ] Guardar historial de conversaciones
- [ ] Análisis de sentimiento
- [ ] Sugerencias automáticas
- [ ] Integración con notificaciones
- [ ] Exportar conversaciones
- [ ] Modo de voz
- [ ] Soporte multiidioma

## 👥 Soporte

Para problemas o sugerencias, contacta al equipo de desarrollo.

---

**Versión**: 1.0.0  
**Fecha**: Noviembre 2025  
**Desarrollado para**: Evallish BPO Control
