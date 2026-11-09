# Selección múltiple y exportación XLSX

Implementación para Trámites, Clientes, Agentes, Pólizas y Cobranza. No requiere migraciones, seeders ni permisos nuevos. Las consultas se implementaron fuera de los controladores/servicios operativos existentes.

## Uso

- Checkbox de fila: añade/quita ese ID.
- Checkbox de encabezado: selecciona/deselecciona únicamente las filas autorizadas de la página visible; admite estado parcial.
- No hay barra, contador, selección masiva de filtros ni selector de modalidades en la interfaz.
- La selección se conserva al paginar/ordenar. Cambiar workspace, filtros o perder permiso limpia su alcance; también cancela las descargas anteriores. Volver al filtro anterior no restaura su selección.
- El único botón «Exportar» descarga los IDs seleccionados si existen; si no hay selección, exporta todos los resultados filtrados del backend, incluidas las demás páginas. Sin filtros activos exporta todos los registros accesibles del workspace. No abre menús ni pide una segunda selección.
- Se conservó el número original de columnas: las casillas ocupan el espacio lateral de la primera celda. No se cambiaron archivos CSS, fuentes o colores. Trámites/Cobranza reutilizan la ubicación de Exportar; Clientes/Pólizas lo integran junto a sus acciones y Agentes en el encabezado de la lista sin mover los filtros.
- Las acciones existentes por fila y los formularios no se modificaron.

## Backend y seguridad

Rutas bajo los middleware existentes de autenticación y suscripción:

- `POST /api/exports/{module}/count`
- `POST /api/exports/{module}`

`module`: `tramites`, `clientes`, `agentes`, `polizas`, `cobranza`. Se utiliza POST para enviar listas de IDs y filtros sin límites de longitud de URL. **Estos controladores no realizan escrituras en base de datos.** No se probaron mediante peticiones a la base habitual.

Ambas rutas verifican el permiso `.view` del módulo y el workspace activo; `workspace_id` es obligatorio y debe coincidir. Los IDs seleccionados/excluidos se vuelven a verificar en las consultas autorizadas. Un ID ajeno no se exporta. Las cuotas también exigen que su póliza pertenezca al workspace.

La interfaz usa `count` con `ids`: devuelve `accessible_ids` y el total accesible de ese subconjunto; se usa para no habilitar checkboxes de filas antiguas de caché pertenecientes a otro workspace o que ya no coincidan con filtros. Se eliminó el conteo global de filtros de la interfaz. La caché existente no se modificó. El API conserva sus modalidades anteriores por compatibilidad, aunque la interfaz ya no muestra sus controles.

### Corrección de búsqueda vacía

Se reprodujo `The filters.q field must be a string.` en una prueba aislada. El frontend enviaba `q: ""`; el middleware estándar de Laravel `ConvertEmptyStringsToNull` lo convertía a NULL y `sometimes|string` lo rechazaba. Ahora el frontend omite búsquedas vacías y valida que una búsqueda real sea texto antes de serializar; el backend acepta q nullable como búsqueda ausente, conservando `string|max:2000` para valores presentes. Objetos, arreglos y números siguen rechazándose. No se modificó el middleware ni la autenticación.

Ejemplo de cuerpo, solo como documentación:

```json
{
  "workspace_id": 1,
  "mode": "selected",
  "filters": {"q": "Aprobación", "semaforo": "verde"},
  "ids": [12, 15],
  "selection_all": false,
  "excluded_ids": []
}
```

Las consultas reproducen los predicados actuales de los cinco componentes sin modificar sus filtros ni paginación. La búsqueda trata `%` y `_` como texto literal. En pólizas se transmite la fecha local y el desfase horario del navegador para reproducir `computeEstatus`; no se corrige ni sustituye ese cálculo existente. En trámites se conserva el criterio visual actual de filtrado del semáforo, pero Excel contiene el valor almacenado, no un color inventado.

La salida tiene una fila por registro y columnas cerradas por módulo. No se serializan modelos completos, claves de agente, credenciales, contraseñas o tokens. Los importes son numéricos y llevan una columna de moneda; no se realizan sumas ni conversiones. Fechas: celdas de fecha Excel con formato `dd/mm/yyyy`.

Todos los textos y encabezados usan `StringCell` explícita. No se usa la detección automática de fórmulas de `Cell::fromValue` para textos, ni se generan FormulaCell: textos como `=HYPERLINK(...)`, `+...`, `-...`, `@...` o con tabuladores siguen siendo texto.

## Dependencia y límites

- Nueva dependencia: [OpenSpout 4.28.5](https://github.com/openspout/openspout/tree/v4.28.5), compatible con el requisito PHP 8.2 del proyecto y PHP 8.3 local. Se fijó esa versión para no elevar la versión mínima de PHP al resolver versiones posteriores.
- Las extensiones requeridas se comprobaron en el PHP local mediante `composer check-platform-reqs --no-dev`; comprobarlas también en el servidor de despliegue.
- Composer añadió solo este paquete; no actualizó los paquetes anteriores. La instalación no ejecutó scripts ni plugins. Se completó el autoload sin optimización; `config.optimize-autoloader` conserva su valor previo.
- Lectura por PK en bloques de 500, con un ID máximo capturado al comenzar. No se cargan todos los resultados en memoria ni todos los IDs al seleccionar un filtro completo.
- Máximo 200 000 filas por archivo; si se supera, se elimina el temporal y se devuelve un mensaje para reducir filtros. IDs explícitos y exclusiones: máximo 5 000 por solicitud.
- El archivo se termina en un temporal antes de responder; los errores/vacíos se devuelven como JSON en lugar de descargar un Excel parcial. El temporal exitoso se elimina después de enviar la descarga; errores controlados también limpian su temporal.
- La interfaz descarga de forma asíncrona y muestra estado de generación. El blob de descarga ocupa memoria del navegador; no es una tarea en segundo plano. Volúmenes grandes siguen sujetos al espacio temporal, memoria del navegador y timeout del servidor/proxy. Se requiere ensayo de volumen en el entorno de despliegue.
- El conteo y la descarga consultan los datos disponibles en momentos distintos. Los datos pueden variar entre ambos por acciones concurrentes. Las selecciones manuales que ya no existen o no cumplen el filtro se rechazan; la modalidad «todos los resultados filtrados» se resuelve nuevamente al descargar y no es una instantánea histórica.
- Se truncarán textos a 32 767 caracteres por el límite de celda de Excel. Los números se escriben con la precisión numérica de Excel; los identificadores como RFC, teléfono y número de póliza se conservan como texto.

## Pruebas

- Primero se integró y compiló Trámites; después se extendió a los otros cuatro módulos.
- `tests/Feature/RecordExportTest.php`: SQLite `:memory:` exclusivamente, con comprobaciones de conexión antes de los fixtures. Sin RefreshDatabase, migraciones ni seeders. Se deshabilita solo el middleware de suscripción para aislar estos casos; autenticación de sesión, permisos y workspace se verifican.
- Las pruebas usan 521 trámites para atravesar más de un bloque de lectura; verifican las tres modalidades, exclusiones, filtros y rechazo de IDs ajenos. Cubren también los otros cuatro módulos, cuotas inconsistentes con póliza ajena y que las consultas emitidas por los endpoints sean únicamente SELECT/PRAGMA.
- Los XLSX se abren como ZIP y se verifica su estructura OOXML/XML, número de filas, formatos de fecha, importes numéricos y ausencia de etiquetas de fórmula. No se abrieron con Microsoft Excel durante estas pruebas.
- `gseafront/tests/recordSelection.test.js` y `tests/exportFilters.test.js`: pruebas Node de selección individual/página, limpieza de alcance, filtros vacíos/tipados y elección automática de descarga. La prueba antigua de selección completa queda como cobertura del reducer compatible, no como función visible.
- `gseafront/tests/exportBrowserSmoke.mjs`: Edge headless, perfil temporal, sesión sintética y todas las APIs interceptadas. Verifica los cinco módulos, 15 descargas simuladas con cero/uno/dos seleccionados, checkbox general y ausencia de barras/contadores/selectores. Genera un XLSX de prueba offline, sin conexión a la base. Guarda capturas; si se proporciona `GSEA_ORIGINAL_COMPONENTS` o existe el snapshot temporal previo a las exportaciones, compara el renderizado original y corregido. No usa la sesión del usuario ni envía solicitudes al backend real.
- Compilación React, sintaxis PHP y ESLint de los archivos reutilizables nuevos.

Comandos para repetir las pruebas aisladas, no contra la aplicación real:

```powershell
# En gseaback, exclusivamente como proceso de pruebas:
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
$env:DB_URL=''
$env:CACHE_STORE='array'
$env:SESSION_DRIVER='array'
php vendor/bin/phpunit tests/Feature/RecordExportTest.php --do-not-cache-result

# En gseafront:
node --test tests/recordSelection.test.js tests/exportFilters.test.js
npm.cmd run build
```

La auditoría realizada en la implementación inicial no informó avisos para OpenSpout. Sí detectó avisos en versiones **preexistentes** de Guzzle, Laravel, CommonMark, Flysystem y varios componentes Symfony. En esta corrección no se modificaron dependencias. No debe interpretarse la auditoría completa como aprobada.

## Validación autenticada pendiente

Se compararon visualmente capturas de los cinco módulos contra el snapshot anterior a las exportaciones, con datos simulados. Queda por verificar la sesión real, cambios de permisos/workspace del entorno de despliegue y abrir las descargas reales en Excel/LibreOffice. Confirmar CORS/headers si frontend/backend se sirven en dominios distintos y probar volúmenes grandes en un entorno aislado.

No se modificaron controladores operativos, modelos, pagos, generación de cuotas, WhatsApp, recordatorios, autenticación, workspaces, Google Calendar, Dashboard o CSS. Se conservaron los cambios previos presentes en ambos proyectos.

## Archivos de esta tarea

Frontend:

- `src/components/Tramites.jsx`
- `src/components/Clientes.jsx`
- `src/components/Agentes.jsx`
- `src/components/SeguimientoPolizas.jsx`
- `src/components/SeguimientoCobranza.jsx`
- `src/components/RecordExportTools.jsx` (nuevo)
- `src/utils/useRecordExport.js` (nuevo)
- `src/utils/recordSelection.js` (nuevo)
- `src/utils/exportFilters.js` (nuevo en la corrección)
- `tests/recordSelection.test.js` (nuevo)
- `tests/exportFilters.test.js` (nuevo en la corrección)
- `tests/exportBrowserSmoke.mjs` (nuevo en la corrección)

Backend:

- `composer.json`, `composer.lock`
- `routes/api.php`
- `app/Http/Controllers/Api/ExportController.php` (nuevo)
- `app/Services/Exports/ExportDataset.php` (nuevo)
- `app/Services/Exports/XlsxExporter.php` (nuevo)
- `tests/Feature/RecordExportTest.php` (nuevo)
- Este documento (nuevo).
