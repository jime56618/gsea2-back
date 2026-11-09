# Dashboard Laravel

Todos los endpoints requieren autenticación Sanctum y workspace activo autorizado y no bloqueado. No se agregaron rutas. Los IDs permiten abrir los detalles existentes. Ejemplos ilustrativos, no datos reales.

## Resumen

`GET /api/dashboard/summary`

Requiere `dashboard.view`. Las secciones de pólizas requieren además `polizas.view`; trámites requiere `tramites.view`. Una sección sin permiso devuelve `null`.

| Parámetro | Uso |
| --- | --- |
| `from`, `to` | Fechas inclusivas `YYYY-MM-DD`, ambas juntas. Por defecto: inicio del mes a hoy. Solo afectan al resumen del periodo. |
| `aseguradora_id` | ID del catálogo existente. Filtra vencimientos y periodo. |
| `agente_id` | ID de **agente_workspaces**, no de agentes. Debe pertenecer al workspace activo. Filtra vencimientos y periodo. |
| `rango` | `0_7`, `8_15` o `16_30`. Filtra el listado; las cantidades incluyen los tres rangos. Sin parámetro: listado completo de hoy a 30 días. |
| `per_page` | 1 a 100, por defecto 15. |
| `polizas_page`, `tramites_page` | Páginas independientes, desde 1. |
| `etapas_pendientes[]` | Valores exactos de etapas existentes en el workspace. Selecciona qué trámites considerar pendientes. |
| `semaforo` | Valor exacto existente en el workspace; filtra trámites sin recalcular su semáforo. |
| `workspace_id` | Opcional; debe coincidir con el workspace activo. No permite cambiar de workspace. |

Ejemplo: `/api/dashboard/summary?from=2026-10-01&to=2026-10-17&rango=0_7&per_page=15`

Fragmento de respuesta:

```json
{
  "workspace_id": 1,
  "fecha_corte": "2026-10-17",
  "timezone": "America/Merida",
  "polizas_por_vencer": {
    "cantidades": {"0_7": 1, "8_15": 0, "16_30": 0},
    "total": 1,
    "listado": {
      "current_page": 1,
      "total": 1,
      "per_page": 15,
      "data": [{
        "id": 42,
        "numero_poliza": "POL-42",
        "contratante": {"id": 5, "nombre": "Cliente de ejemplo"},
        "aseguradora": {"id": 2, "nombre": "Aseguradora de ejemplo"},
        "fin_vigencia": "2026-10-24"
      }]
    }
  },
  "resumen_periodo": {
    "from": "2026-10-01",
    "to": "2026-10-17",
    "campo_fecha": "fecha_emision",
    "aseguradora_id": null,
    "agente_id": null,
    "polizas_emitidas": 2,
    "por_moneda": [
      {"moneda": "MXN", "polizas_emitidas": 1, "prima_total": "1000.00"},
      {"moneda": "USD", "polizas_emitidas": 1, "prima_total": "50.00"}
    ]
  },
  "tramites": {
    "etapas_disponibles": [{"etapa": "Revisión", "total": 1}],
    "pendientes_identificados": false,
    "etapas_pendientes": null,
    "totales_por_etapa": [{"etapa": "Revisión", "total": 1}],
    "total": 1,
    "orden": "fecha_ultima_modificacion_desc,id_desc",
    "criterio_prioridad": null,
    "listado": {
      "current_page": 1,
      "total": 1,
      "per_page": 15,
      "data": [{
        "id": 8, "folio": "TR-8", "tipo": "Ejemplo", "tipo_tramite": null,
        "etapa": "Revisión", "semaforo": "rojo",
        "fecha_ultima_modificacion": "2026-10-17T12:00:00.000000Z"
      }]
    }
  }
}
```

Los paginadores incluyen también los enlaces y metadatos estándar de Laravel. Las cantidades se calculan antes de paginar. Los rangos incluyen ambos extremos y no comparten fechas. `fecha_emision` nula queda fuera del periodo; no se sustituye por creación o vigencia. Las monedas nulas o vacías se conservan como grupos independientes, sin asignarles MXN ni convertirlas. La prima se devuelve como cadena decimal.

**Trámites pendiente de definición de negocio:** no existe catálogo de etapas terminales ni jerarquía de semáforos en el backend. Sin `etapas_pendientes[]` se muestran todos los trámites, identificados expresamente con `pendientes_identificados: false`. Con el filtro, los totales corresponden a esas etapas y al semáforo solicitado. El listado usa última modificación descendente; no se presenta ese orden como urgencia ni se calcula tiempo en etapa. Falta confirmar etapas pendientes/cerradas y criterio de prioridad para automatizarlos.

**Contrato del resumen:** sustituye los antiguos bloques `kpis/charts/operativa/objetivo` por los bloques documentados. Se eliminaron del resumen los importes que mezclaban monedas, la meta predeterminada y la urgencia inferida. Consumir cobranza desde su endpoint existente. No se modificó el frontend.

## Agenda existente

`GET /api/calendar/events?agenda=hoy&per_page=15&page=1`

`GET /api/calendar/events?agenda=proximos_7_dias&per_page=15&page=1`

Requiere `calendar.view`, con la misma visibilidad por workspace del calendario existente. Hoy comprende medianoche a medianoche; próximos siete días comprende mañana a siete días después de hoy, inclusive. Se incluyen eventos que se superponen al intervalo; un evento de varios días puede aparecer en ambas consultas. La zona `America/Merida` corresponde a la interpretación de fechas locales que ya usa CalendarController.

También admite `from` y `to` juntos para fechas inclusivas, sin combinarlos con `agenda`, además de `workspace_id`, `page` y `per_page` (1 a 100). Los filtros nuevos activan la respuesta paginada. Sin filtros conserva la respuesta anterior del calendario.

```json
{
  "workspace_id": 1,
  "timezone": "America/Merida",
  "from": "2026-10-17T00:00:00-06:00",
  "to_exclusive": "2026-10-18T00:00:00-06:00",
  "total": 1,
  "listado": {
    "current_page": 1,
    "total": 1,
    "per_page": 15,
    "data": [{
      "id": 11,
      "title": "Reunión de ejemplo",
      "starts_at": "2026-10-17T10:00:00-06:00",
      "ends_at": "2026-10-17T11:00:00-06:00",
      "type": "meeting",
      "meet_link": null
    }]
  }
}
```

No se inventa fin para eventos sin `ends_at`; devuelve `null`. Se usa el enlace almacenado, sin llamadas a Google ni nuevas integraciones.

## Cobranza existente

`GET /api/dashboard/cobranza` y su alias `/api/dashboard/cobranza-resumen` mantienen su implementación previa. No se cambian sus filtros ni contrato.

## Comprobaciones

`php artisan test --filter=Dashboard`: pruebas con SQLite en memoria para aislamiento de workspace, permisos, agente por asignación, aseguradora, emisión, monedas, límites 0/7/8/15/16/30, paginación, fechas inválidas, agenda local y eventos que cruzan medianoche; incluye regresión de cobranza. Sin migraciones ni escrituras sobre la base existente.
