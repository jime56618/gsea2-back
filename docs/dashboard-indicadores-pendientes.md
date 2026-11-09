# Indicadores pendientes: alcance y activación

El código está preparado, pero requiere aprobación y aplicación de las tres migraciones nuevas. No se ejecutaron migraciones ni seeders en la base real. Hasta entonces los formularios anteriores siguen disponibles; las funciones nuevas se anuncian como no disponibles.

## Trámites

- `estado`: `en_atencion`, `finalizado`, `cancelado`. Independiente de `etapa` y `semaforo`.
- La migración deja `NULL` en históricos; no hay backfill. El alta por API asigna `en_atencion` cuando la columna existe y el cliente omite el campo.
- Editar un histórico sin elegir estado mantiene `NULL`. Una clasificación explícita no puede sustituirse por `NULL` mediante la API.
- `/dashboard/summary`: `tramites.estados.en_atencion` y `tramites.estados.sin_estado` cuentan todo el workspace autorizado, sin inferencias por etapa o color. `estados: null` significa migración pendiente, no cero.
- `/tramites` añade `estado_disponible` y acepta `estado` como filtro. La navegación desde el Dashboard filtra por `en_atencion`, ya no por amarillo.
- El semáforo y su agregado conservan su comportamiento.

## Metas comerciales

- Tabla independiente `metas_comerciales`; unicidad por workspace, año, mes y moneda.
- `GET /dashboard/metas-comerciales?anio=2026&mes=10`: requiere `dashboard.view`, devuelve `disponible`, permisos efectivos y `metas`.
- Cada meta devuelve `importe_objetivo`, `prima_emitida`, `cumplimiento_pct`, `polizas_emitidas`, `moneda`, mes y año. Sin meta, la lista está vacía: no representa 0%.
- Real = suma de `polizas.prima_total` por `fecha_emision` del mes completo y moneda exacta en el workspace activo. No usa cuotas ni cambia indicadores actuales. Los filtros de gráfica no intervienen.
- `POST /dashboard/metas-comerciales`: requiere rol administrador y `metas_comerciales.create`. Datos: `anio`, `mes`, `moneda` (tres letras mayúsculas), `importe_objetivo` positivo con hasta dos decimales.
- `PUT /dashboard/metas-comerciales/{id}`: requiere rol administrador y `metas_comerciales.update`. Solo cambia el importe; periodo/moneda son inmutables.
- Ambos aceptan `workspace_id` solo si coincide con el activo. El ID de una meta ajena no es accesible.
- La tarjeta permite seleccionar MXN, USD y las monedas adicionales ya configuradas devueltas por el endpoint. El API admite códigos de tres letras; no hace conversión cambiaria.
- El porcentaje puede exceder 100%; solo la barra se limita a 100%.
- Provisión pendiente: ejecutar, **solo con autorización**, el seeder específico `MetaComercialPermissionSeeder`. Crea únicamente los dos permisos nuevos y los añade a roles administradores sin reemplazar permisos existentes. No es necesario ejecutar los seeders generales de roles/workspaces.

## Renovaciones

- `polizas.poliza_anterior_id` nullable; históricos sin cambios. El vínculo solo se admite al crear mediante `POST /polizas`, con los permisos existentes.
- La regla recorre la cadena y exige que todos los ancestros existan en el workspace activo; rechaza autoreferencia y ciclos. El guardado general de edición rechaza cambios de vínculo. No se agregaron hooks ni procesos automáticos.
- El formulario pide el ID de la póliza anterior; no copia datos, primas, coberturas ni vigencias.
- La llave foránea usa `SET NULL` al eliminar la anterior para no bloquear la eliminación existente ni borrar la renovada en cascada. Por tanto, un vínculo puede perderse al eliminar la anterior. La retención histórica requiere una decisión de negocio futura.
- No se modificaron las llamadas operativas ya existentes del alta: crear una póliza sigue ejecutando su flujo habitual de cuotas y recordatorios.
- La tasa permanece N/D: falta un criterio verificable de elegibilidad, exclusiones/cancelaciones y fecha de corte para considerar una renovación. Tampoco se definió si se permiten varias renovaciones vinculadas a la misma anterior; no se impuso una regla nueva.
- Cuando se autorice el cálculo: numerador de **anteriores distintas** elegibles con vínculo válido; denominador de elegibles que vencen en el periodo aprobado. Denominador desconocido o cero => N/D. No inferir vínculos ni porcentajes históricos.

## Migraciones creadas y no ejecutadas

1. `2026_10_28_120000_add_estado_to_tramites_table.php`
2. `2026_10_28_120100_create_metas_comerciales_table.php`
3. `2026_10_28_120200_add_poliza_anterior_id_to_polizas_table.php`

Todas tienen `down()`. Revertirlas después de clasificar o configurar datos elimina esos campos/tablas nuevos; no realizar rollback sin respaldo y revisión.

## Pruebas aisladas realizadas

`tests/Feature/DashboardPendingIndicatorsTest.php` usa únicamente SQLite `:memory:` con comprobaciones previas de entorno, conexión y URL. Crea un esquema mínimo de prueba; no usa RefreshDatabase, migraciones ni seeders. Los POST/PUT del test se ejecutan mediante el kernel de pruebas, sin tráfico a la aplicación real. Se omite solo el middleware de suscripción; se conservan autenticación, permisos y workspace en los casos comprobados.

Ejecutar desde PowerShell en gseaback, en un proceso de prueba:

```powershell
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
$env:DB_URL=''
$env:CACHE_STORE='array'
$env:SESSION_DRIVER='array'
php vendor/bin/phpunit tests/Feature/DashboardPendingIndicatorsTest.php --do-not-cache-result
```

Para comprobar las migraciones y el flujo integral antes de producción: preparar una base MySQL desechable y confirmar explícitamente que no es la base real; solicitar autorización para ejecutar las migraciones allí, revisar `up/down`, provisionar solo los permisos nuevos y validar con sesión autenticada. No usar pólizas reales ni permitir envío de WhatsApp o recordatorios en ese entorno. Las pruebas actuales validan la regla de renovación; no ejecutan un alta completa de póliza con sus servicios operativos.

Pendiente de comprobación autenticada: aspecto de selectores y tarjeta, permisos de administradores reales, clasificación manual de históricos, metas por moneda/mes y alta de renovación en entorno aislado. Revisar también planes de despliegue: primero esquema aprobado, después habilitar funcionalidades y provisionar permisos; recargar sesión para reflejar permisos nuevos.

## Archivos de esta implementación

Frontend:

- `src/components/Dashboard.jsx`
- `src/components/Tramites.jsx`
- `src/components/SeguimientoPolizas.jsx`
- `src/components/MetaComercial.jsx` (nuevo)
- `src/utils/dashboardService.js`

Backend:

- `app/Http/Controllers/Api/DashboardController.php`
- `app/Http/Controllers/Api/TramiteController.php`
- `app/Http/Controllers/Api/PolizaController.php` (solo contrato de capacidad, validación y dato opcional de alta)
- `app/Http/Controllers/Api/MetaComercialController.php` (nuevo)
- `app/Models/Tramite.php`
- `app/Models/Poliza.php` (campo y relaciones de renovación)
- `app/Models/MetaComercial.php` (nuevo)
- `app/Rules/PolizaAnteriorValida.php` (nuevo)
- `app/Support/WorkspacePermissions.php`
- `routes/api.php`
- Las tres migraciones indicadas arriba (nuevas).
- `database/seeders/MetaComercialPermissionSeeder.php` (nuevo, no ejecutado).
- `tests/Feature/DashboardPendingIndicatorsTest.php` (nuevo).
- Este documento (nuevo).

No se modificaron los servicios de primas, detalles, cuotas, pagos, WhatsApp o recordatorios, la autenticación, administración de workspaces, Google Calendar ni CSS. Los cambios previos de otros módulos se conservaron.
