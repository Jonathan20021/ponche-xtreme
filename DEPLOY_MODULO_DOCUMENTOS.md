# Despliegue — Documentos de Empleados

Cubre los 3 puntos del documento del cliente. **Un paso de base de datos
obligatorio.** No requiere tareas programadas.

---

## 0. Instalación

1. Subir los archivos (lista al final).
2. Correr **una vez**: `php run_documents_module_migration.php` (idempotente).

---

## 1. Bug del contrato de confidencialidad — corregido

### Causa raíz

`hr/view_contract.php` **nunca miraba `contract_type`**: siempre armaba el
contrato de trabajo. El listado sí leía el tipo de la base, por eso el título
decía "Confidencialidad" mientras el contenido era el contrato laboral.

No era un problema de la generación simultánea: **los 208 registros de
confidencialidad guardados se veían mal**, sin importar cómo se hubieran creado.

### Cómo se corrigió

Los cuerpos de ambos documentos se extrajeron a `lib/contract_documents.php`, una
sola fuente compartida:

| Archivo | Antes | Ahora |
|---|---|---|
| `view_contract.php` | armaba siempre el contrato laboral | elige por `contract_type` |
| `generate_confidentiality_contract.php` | tenía su propia copia del texto | usa la librería (155 líneas duplicadas eliminadas) |

Con los cuerpos centralizados, la pantalla que los muestra no puede volver a
equivocarse de documento.

**Verificado** contra el registro real #387: el título es "CONTRATO DE
CONFIDENCIALIDAD", contiene las cláusulas propias de confidencialidad
(*secretos de fabricación*, *Ley No. 53-07*) y **no** contiene el texto del
contrato laboral (*prestar sus servicios a*).

> Los 208 registros existentes quedan corregidos automáticamente: el contenido se
> arma al abrirlos, no estaba guardado mal en la base.

---

## 2. Generación de documentos — los 12 tipos

### Cómo está resuelto

En vez de 12 archivos PHP con el texto quemado, hay un **motor de plantillas**:
cada documento es HTML con marcadores `{{campo}}` guardado en `document_templates`
y **editable desde la interfaz**. Cuando lleguen los formatos definitivos, RRHH
los pega y quedan funcionando — sin tocar código ni pedir un despliegue.

| Modo | Cuántos | Qué significa |
|---|---|---|
| `template` | 9 | Editable desde **RRHH → Formatos de Documentos** |
| `builtin` | 2 | Contrato de trabajo y de confidencialidad: texto legal aprobado, bloqueado para que nadie altere las cláusulas por accidente |
| `upload` | 1 | Cédula de identidad: no se genera, se escanea |

Los 9 editables ya vienen con **estructura, encabezado de la empresa, tabla de
datos y bloque de firmas**; solo falta el cuerpo definitivo. Tres de ellos
(amonestación, retroalimentación, descargo laboral) traen además el texto
completo, porque su estructura es estándar.

### Campos que se piden al generar

Algunos documentos necesitan datos que no están en el expediente. Se declaran por
plantilla y el sistema los pide en el formulario:

- **Amonestación** → motivo, tipo de falta, medida aplicada
- **Retroalimentación** → aspectos positivos, áreas de mejora, compromisos
- **Descargo laboral** → hechos atribuidos, descargo del colaborador
- **Acta de descargo** → artículos devueltos, observaciones
- **Oferta laboral** → fecha de inicio, beneficios

### Marcadores disponibles

24 marcadores con los datos del colaborador: `{{nombre}}`, `{{cedula}}`,
`{{posicion}}`, `{{departamento}}`, `{{salario}}`, `{{fecha_larga}}`,
`{{fecha_ingreso_larga}}`, `{{supervisor}}`, etc. En el editor se insertan con un
clic, en la posición del cursor.

Los valores se **escapan** al insertarse: nadie puede romper el documento ni
inyectar marcado desde un campo de texto. Un marcador mal escrito no rompe el
PDF — sale resaltado en amarillo para que se note.

### Dónde se usa

- **Perfil del colaborador → Generar documento** (botón nuevo)
- **RRHH → Formatos de Documentos** para editar los formatos

Al generar, el PDF se **archiva solo en el expediente** con su `doc_key`, así que
cuenta de inmediato en el checklist de documentación. Verificado: al generar la
oferta laboral de una colaboradora, su expediente pasó de **9/14 (82%) a 10/14 (91%)**.

Todo queda en `generated_documents`: qué se generó, para quién, con qué datos y
quién lo emitió.

---

## 3. Documento de respaldo en licencias médicas

La columna `medical_certificate_file` **existía desde el inicio pero el formulario
nunca la llenaba** — las licencias quedaban sin el certificado que las justifica
(0 de las registradas tenía uno).

Ahora el formulario de alta acepta dos adjuntos:

- **Certificado médico** (PDF o imagen, hasta 10 MB)
- **Récipe o indicación médica** (opcional)

En el listado, cada licencia muestra botones para abrirlos; las que no tienen
respaldo salen marcadas con **"Sin documento de respaldo"** en ámbar, para que se
note cuáles hay que completar.

> El modal del perfil del colaborador ya aceptaba el certificado desde la tanda
> anterior; esto cierra el módulo principal, que era donde faltaba.

---

## 4. Verificación

```bash
php tests/work_hours_calculator_test.php    # -> All tests passed.
```

En la interfaz:
1. **Contratos** → abre uno de tipo Confidencialidad: ahora sale el texto correcto.
2. **RRHH → Formatos de Documentos** → elige "Amonestación", revisa el formato.
3. **Perfil → Generar documento** → Amonestación → llena los campos → PDF listo y
   archivado en el expediente.
4. **Licencias Médicas** → crea una con certificado adjunto → aparece el botón para verlo.

---

## 5. Archivos

### Nuevos (5)

```
lib/contract_documents.php            Cuerpos del contrato laboral y de confidencialidad (fuente única)
lib/document_generator.php            Motor de plantillas, render y archivado
hr/generate_document.php              Generar un documento para un colaborador
hr/document_templates.php             Editar los formatos (aquí se pegan los definitivos)
run_documents_module_migration.php    Instalador idempotente (MySQL 5.7)
```

### Modificados (6)

```
hr/view_contract.php                    BUG: ahora elige el cuerpo según contract_type
hr/generate_confidentiality_contract.php Usa la librería compartida
hr/medical_leaves.php                   Guarda los adjuntos de la licencia
hr/medical_leaves_modals.php            Campos de certificado y récipe
hr/medical_leaves_view.php              Muestra los adjuntos / avisa si faltan
hr/employee_profile.php                 Botón "Generar documento"
header.php                              Acceso a Formatos de Documentos
```

> Los archivos van al **servidor de oficina y a HostGator**; la migración se corre
> **una sola vez**.
