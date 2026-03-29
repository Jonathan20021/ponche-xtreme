# GHL Communications Reporting

## Alcance implementado

La seccion `voice_ai_reports.php` consume la API publica de GoHighLevel sobre estos endpoints:

- `GET /voice-ai/dashboard/call-logs`
- `GET /voice-ai/dashboard/call-logs/:callId`
- `GET /voice-ai/agents`
- `GET /conversations/messages/export`
- `GET /conversations/search`
- `GET /users/`
- `GET /contacts/:contactId`
- `GET /phone-system/numbers/location/:locationId`

Con eso el modulo entrega:

- KPIs de interacciones, llamadas inbox, SMS, emails, usuarios activos, numeros activos, conversaciones y Voice AI.
- Tendencia diaria de interacciones.
- Distribucion por canal, estado y origen operativo.
- Actividad por usuario con horas activas estimadas por eventos reales de API.
- Cola actual de conversaciones asignadas por usuario.
- Ranking de contactos.
- Tabla combinada de actividad reciente.
- Exportacion CSV del conjunto filtrado.
- Inventario de agentes Voice AI configurados en el location.
- Snapshot de conversaciones recientes del inbox.

## Configuracion necesaria

El modulo ahora trabaja en modo multicuenta:

- `voice_ai_integrations`: tabla con una fila por cuenta/location.
- `voice_ai_default_integration_id`: define la cuenta por defecto del dashboard.
- Cada integracion guarda:
  - nombre visible
  - Private Integration Token
  - `location_id`
  - timezone
  - limites de paginacion para Voice AI e interacciones

La configuracion legacy en `system_settings` se conserva solo como compatibilidad y se sincroniza desde la cuenta por defecto.

## Fuentes oficiales revisadas

- `https://marketplace.gohighlevel.com/docs/ghl/voice-ai/dashboard/index.html`
- `https://marketplace.gohighlevel.com/docs/ghl/voice-ai/get-call-logs`
- `https://marketplace.gohighlevel.com/docs/ghl/voice-ai/get-call-log`
- `https://marketplace.gohighlevel.com/docs/ghl/conversations/export-messages-by-location`
- `https://marketplace.gohighlevel.com/docs/ghl/conversations/search-conversation`
- `https://marketplace.gohighlevel.com/docs/ghl/users/search-users`
- `https://marketplace.gohighlevel.com/docs/ghl/phone-system/active-numbers`
- `https://help.gohighlevel.com/support/solutions/articles/155000006379-voice-ai-public-apis`

## Notas tecnicas

- La version usada para los requests es `2021-07-28`.
- El dashboard permite elegir la cuenta activa desde filtros y desde el formulario de configuracion.
- `messages/export` usa `endDate` exclusivo; el modulo lo normaliza para que el filtro visual sea inclusivo.
- El dashboard usa cache corto en disco para evitar recalcular historicos pesados en cada refresh.
- Si el volumen supera `voice_ai_interaction_max_pages`, el dashboard marca el resultado como parcial.
- Las horas de uso por usuario son estimadas a partir de actividad operacional, no son logs de login de navegador.
