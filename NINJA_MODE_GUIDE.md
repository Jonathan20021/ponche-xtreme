# 🥷 Modo Ninja - Guía Rápida de Uso

## ¿Qué es el Modo Ninja?

El **Modo Ninja** permite a los supervisores agregar y editar punches de forma manual para cualquier agente, directamente desde el dashboard en tiempo real. Esto es útil para:

- Corregir errores de registro
- Agregar punches que el agente olvidó hacer
- Cambiar el tipo de punch sin necesidad de borrar y recrear

---

## 🎯 Cómo Usar

### 1️⃣ Abrir el Modal de un Agente

1. En el **Supervisor Dashboard**, haz click en cualquier tarjeta de agente
2. Se abrirá un modal con los detalles completos del agente

![Modal de Agente](docs/modal-agente.png)

---

### 2️⃣ Agregar un Nuevo Punch (Modo Ninja)

En el modal, en la sección **"Historial del Día"**, encontrarás:

```
┌──────────────────────────────────────────────┐
│ 🥷 Modo Ninja        [+ Agregar Punch]      │
└──────────────────────────────────────────────┘
```

**Pasos:**

1. Click en el botón **"+ Agregar Punch"**
2. Aparecerá un formulario con un selector desplegable
3. Selecciona el tipo de punch (Entry, Break, Lunch, etc.)
4. Click en **"Registrar"**
5. ¡Listo! Verás un mensaje de confirmación: **"✓ Punch registrado"**
6. El formulario se cierra automáticamente después de 1 segundo

**Nota**: Los tipos de punch marcados como "únicos" (como Entry y Exit) solo pueden registrarse una vez por día. Si ya existe uno, aparecerá como "(registrado)" y estará deshabilitado.

---

### 3️⃣ Editar un Punch Existente (Modo Ninja)

Cada punch en el historial tiene un botón **"🥷 Ninja"** a la derecha.

**Pasos:**

1. Click en el botón **"🥷 Ninja"** del punch que quieres editar
2. Aparecerá un formulario debajo del punch
3. Selecciona el nuevo tipo de punch
4. Click en **"Aplicar"**
5. Verás un mensaje: **"✓ Punch actualizado"**
6. El formulario se cierra automáticamente

**Ejemplo visual:**

```
┌─────────────────────────────────────────────────┐
│ 🚪 Entry                                        │
│ 🕐 08:30 AM  💰 Pagado          [🥷 Ninja]      │
├─────────────────────────────────────────────────┤
│   [Selecciona un tipo ▼]  [Aplicar]  [Cancelar]│
│   Estado: Actualizando...                       │
└─────────────────────────────────────────────────┘
```

---

### 4️⃣ Cancelar una Operación

Si cambias de opinión:

1. Click en el botón **"Cancelar"** (rojo)
2. El formulario se cierra sin hacer cambios

---

## 🔄 Actualización Automática

El modal se actualiza automáticamente cada **3 segundos** para mostrar los datos más recientes.

**¿Qué pasa con el formulario abierto?**

✅ **No te preocupes**: Si estás editando o agregando un punch, el formulario **NO se cerrará** durante las actualizaciones automáticas. Solo se cierra cuando:
- Completas la operación exitosamente
- Haces click en "Cancelar"
- Cierras el modal

---

## 🎨 Colores y Estados

### Tipos de Punch

Cada tipo de punch tiene su propio color:

- 🟦 **Entry/Exit** - Azul
- 🟧 **Break** - Naranja
- 🟨 **Lunch** - Amarillo
- 🟪 **Meeting** - Morado
- 🟩 **Otros** - Verde

### Estados de Pago

- 💰 **Pagado** - Badge verde
- ⏸️ **No Pagado** - Badge naranja

### Mensajes de Estado

- ✅ **"✓ Punch registrado"** - Verde (éxito)
- ✅ **"✓ Punch actualizado"** - Verde (éxito)
- ⚙️ **"Actualizando..."** - Gris (procesando)
- ❌ **Mensajes de error** - Rojo

---

## 🚨 Validaciones y Restricciones

### Punches Únicos

Algunos tipos de punch están marcados como **"únicos por día"**:

- **Entry (Entrada)** - Solo una vez al día
- **Exit (Salida)** - Solo una vez al día
- **Disponible** - Solo una vez al día

Si intentas agregar un segundo punch de estos tipos, verás un error:

```
❌ Ya existe un punch de este tipo registrado hoy.
```

### Seguridad

- Solo usuarios con permiso `supervisor_dashboard` pueden usar el Modo Ninja
- Todas las acciones se registran en los logs del sistema
- Se guarda el IP del supervisor que hizo el cambio

---

## 💡 Consejos y Buenas Prácticas

### ✅ Hacer

1. **Verifica antes de aplicar** - Asegúrate de seleccionar el tipo correcto
2. **Usa el modo ninja para correcciones** - Es ideal para ajustes rápidos
3. **Revisa el historial** - Antes de agregar, verifica que no exista ya

### ❌ Evitar

1. **No agregues punches duplicados** - El sistema valida tipos únicos
2. **No cierres el modal durante una operación** - Espera el mensaje de confirmación
3. **No uses para registros regulares** - Los agentes deben hacer sus propios punches

---

## 🔍 Resolución de Problemas

### El botón "Agregar Punch" no aparece

✅ **Solución**: Verifica que tienes permisos de supervisor

### El formulario desaparece al actualizar

✅ **Solución**: Este problema ha sido corregido. El formulario ahora se mantiene abierto durante las actualizaciones automáticas.

### Error: "Tipo de punch inválido"

✅ **Solución**: El tipo de punch puede estar inactivo. Contacta al administrador.

### No puedo seleccionar un tipo

✅ **Solución**: Si aparece "(registrado)", ese tipo ya fue usado hoy y es único.

---

## 📊 Estadísticas

Después de agregar o editar un punch, las estadísticas se actualizan automáticamente:

- **Total Punches** - Cuenta total del día
- **Tiempo Pagado** - Suma de todos los punches pagados
- **Tiempo No Pagado** - Suma de breaks y pausas
- **Gráfica de Distribución** - Visualización por tipo

---

## 🎓 Ejemplo Completo

**Escenario**: Un agente olvidó hacer su punch de salida (Exit)

1. Abre el dashboard de supervisores
2. Busca al agente y haz click en su tarjeta
3. En el modal, click en **"+ Agregar Punch"**
4. Selecciona **"Exit (Salida)"** del menú
5. Click en **"Registrar"**
6. Verás: **"✓ Punch registrado"**
7. El nuevo punch aparece en el historial
8. Las estadísticas se actualizan

**Tiempo total**: ~5 segundos ⚡

---

## 📞 Soporte

Si encuentras algún problema:

1. Revisa los logs del sistema
2. Verifica tus permisos
3. Contacta al administrador del sistema

---

**Última actualización**: 2025-11-05  
**Versión**: 1.0  
**Autor**: Sistema Ponche Xtreme
