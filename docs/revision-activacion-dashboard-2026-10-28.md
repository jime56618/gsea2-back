# Revisión de activación del Dashboard — 28 de octubre de 2026

## Dictamen

No se recomienda aplicar las tres migraciones juntas todavía. La migración de estado de trámites es compatible por inspección; metas y renovaciones tienen decisiones de eliminación pendientes. No se modificó código de aplicación ni migraciones durante esta revisión.

| Migración | Compatibilidad comprobada | Estado de activación |
| --- | --- | --- |
| `2026_10_28_120000_add_estado_to_tramites_table.php` | ENUM nullable; índice `(workspace_id, estado)` sin colisión actual | Lista para ensayo MySQL aislado; no ensayada en MySQL |
| `2026_10_28_120100_create_metas_comerciales_table.php` | Tipos/FK compatibles; índice único correcto | Condicionada: `ON DELETE RESTRICT` cambia la eliminación de workspaces con metas |
| `2026_10_28_120200_add_poliza_anterior_id_to_polizas_table.php` | BIGINT UNSIGNED nullable y FK autorreferenciada compatibles | No activar aún: `SET NULL` pierde el vínculo sin advertencia específica en el flujo de eliminación |

La compilación SQL offline y `php -l` pasaron. No equivalen a ejecutar `up/down` en MySQL ni a una prueba autenticada del frontend.

## Evidencia obtenida sin escrituras

- Conexión Laravel habitual: `mysql`, `127.0.0.1:3309`, base `gsea`; configuración no cacheada.
- Servidor real: MySQL **8.4.9**, puerto interno 3306; contenedor activo `mysql-gsea` (`mysql:8.4`).
- Único esquema visible cuyo nombre contiene GSEA/test/sandbox/staging: `gsea`. No existe `.env.testing` ni conexión nombrada de sandbox configurada en este proyecto. PHPUnit utiliza SQLite `:memory:`, no MySQL.
- Docker también muestra `mysql-84` detenido. No hay evidencia de su contenido o aislamiento; no se inició ni se utilizó.
- `polizas`, `tramites` y `workspaces` son InnoDB. Sus IDs y los `workspace_id` relevantes son `BIGINT UNSIGNED`. El `workspace_id` de trámites admite NULL, como en las migraciones existentes.
- No existen las columnas `tramites.estado` o `polizas.poliza_anterior_id`, ni la tabla `metas_comerciales`. Las tres migraciones no figuran aplicadas en `migrations`.
- Conteos SELECT al revisar: 12 pólizas, 1 trámite y 3 workspaces. Son una referencia de este momento; no sustituyen un respaldo ni detectan cambios concurrentes.
- No están registrados los permisos nuevos de metas. Hay dos roles globales `admin`/`Administrador`, IDs 1 y 4; los administradores con membresías actuales usan el rol 1 en los workspaces 1, 2 y 3. No se modificaron ni consolidaron roles.
- Se consultaron metadatos, conteos, roles, membresías y privilegios, sin extraer datos personales ni credenciales. La cuenta habitual tiene alcance global: no es una cuenta apropiada para demostrar aislamiento de pruebas.

## Conservación de históricos e índices

### Estado de trámites

`up()` solo añade `estado` nullable y el índice compuesto. No contiene UPDATE, DELETE, backfill ni renombrado. Los registros existentes conservan etapa/semáforo y reciben NULL, sin clasificarse arbitrariamente. El controlador asigna `en_atencion` únicamente a altas posteriores cuando existe la columna.

El índice `tramites_workspace_id_estado_index` no existe actualmente y no sustituye el índice/FK de workspace. El ENUM admite los tres valores requeridos y NULL.

`down()` elimina primero el índice y después la nueva columna. Conserva filas y campos anteriores, pero **pierde las clasificaciones nuevas** si ya se capturaron. Respaldarlas antes de revertir.

### Metas comerciales

La tabla es independiente, con `DECIMAL(16,2)`, año/mes unsigned, moneda de tres caracteres y timestamps. La unicidad por `(workspace_id, anio, mes, moneda)` es correcta y la FK coincide en tipo con `workspaces.id`.

La API valida importe positivo, mes 1–12 y moneda de tres letras; la tabla por sí sola no impone esas reglas mediante CHECK. No deben introducirse metas por escrituras directas que omitan las validaciones.

**Riesgo confirmado:** `restrictOnDelete()` impide eliminar un workspace que tenga metas. El controlador actual elimina el workspace directamente, sin resolver ni explicar este nuevo impedimento. También puede afectar eliminaciones en cascada que alcancen ese workspace. Por ello no puede afirmarse que la migración preserve todo el comportamiento actual.

Decisión necesaria: conservar metas y rechazar la eliminación con un mensaje explícito, o aprobar borrado en cascada de metas al eliminar el workspace. La segunda opción preserva el comportamiento de eliminación, pero elimina las metas asociadas y requiere autorización. No se cambió ninguna de las dos cosas.

`down()` elimina únicamente la nueva tabla; **pierde todas las metas capturadas**. Su reversibilidad estructural no implica recuperación de datos.

### Vínculo de renovación

La columna nullable mantiene intactas las pólizas actuales: todas siguen inicialmente sin vínculo. La FK referencia la PK de la misma tabla. No se modifican número, primas, vigencias, coberturas ni cuotas.

El SQL compilado añade columna y FK. MySQL/InnoDB proporciona el índice requerido de la columna hija al añadir la FK si no existe uno compatible; verificar su presencia mediante SHOW INDEX en el ensayo real.

**Riesgo confirmado:** `nullOnDelete()` evita IDs colgantes, pero al eliminar la anterior borra silenciosamente la relación de las renovaciones. El modal actual solo dice que la eliminación no se puede deshacer; ni el frontend ni `PolizaController::destroy()` consultan las renovaciones dependientes o advierten de esa pérdida. Las eliminaciones por cascada desde contratantes/workspaces también requieren consideración; una advertencia únicamente en el modal de pólizas no cubre esos caminos.

Decisión necesaria antes de activar: aprobar `SET NULL` con avisos y política explícita de pérdida de historial en todos los flujos relevantes, o proteger/retener el historial mediante una solución de conservación aprobada. Cambiar simplemente a RESTRICT bloquearía eliminaciones existentes y tampoco debe hacerse sin autorización.

El aislamiento por workspace y la ausencia de ciclos son validaciones de aplicación (`PolizaAnteriorValida`), no garantías de esta FK simple. El vínculo se admite solo en altas; su edición general está prohibida. No ejecutar escrituras SQL directas que eludan estas reglas.

`down()` elimina primero la FK y después la columna; no elimina pólizas, pero **pierde todos los vínculos nuevos**. En el ensayo up/down verificar que desaparezcan la columna, su FK y el índice asociado, conservando los demás índices de pólizas.

## Permisos

El catálogo contiene `metas_comerciales.create` y `metas_comerciales.update`. `MetaComercialPermissionSeeder` crea solo esos permisos y usa `syncWithoutDetaching`: conserva los demás permisos.

El seeder reconoce slug `admin` o nombre `admin`/`Administrador`; en la base actual alcanzaría a los roles globales 1 y 4. El controlador exige además rol administrador y el permiso específico. La lectura del avance requiere `dashboard.view`.

Antes de ejecutar el seeder, aprobar esa selección de roles y revisar roles personalizados homónimos: el criterio por nombre no distingue por sí solo una plantilla del sistema de un rol personalizado llamado Administrador. No ejecutar seeders generales como sustituto: `SystemRoleSeeder` puede resincronizar permisos de roles existentes. La provisión específica no se ejecutó.

El rollback de las migraciones no revierte los permisos del seeder; cualquier retirada posterior debe ser específica y autorizada, sin borrar otros permisos.

## Efectos operativos y reversión

No se encontraron referencias a los campos nuevos/metas en los servicios o comandos de cobranza, WhatsApp y recordatorios inspeccionados. No se modificaron estos archivos. El alta normal de pólizas conserva sus llamadas operativas existentes; las pruebas de integración de esa alta deben bloquear cualquier envío externo.

Las migraciones no contienen actualizaciones de filas existentes. Sin embargo, ALTER TABLE/índices/FK pueden bloquear o esperar bloqueos: medir tiempos y concurrencia en una copia aislada representativa. Laravel no ofrece una transacción conjunta de esquema MySQL para estas migraciones. Un fallo tras añadir una columna o crear una tabla puede dejar DDL parcial sin registro final en `migrations`; inspeccionar antes de reintentar, no ejecutar nuevamente a ciegas.

Antes de producción: respaldo verificado, ventana de mantenimiento adecuada, captura de esquema/índices/filas previas, lista de migraciones pendientes y plan de recuperación. Una vez capturados estados, metas o vínculos, preferir corrección hacia delante a rollback destructivo. No usar `migrate:fresh`, `refresh` ni rollbacks generales sobre la base habitual.

## Cómo preparar MySQL de pruebas de forma segura

No se creó una base nueva: la solicitud pide explicar su creación cuando no hay una aislada confirmada.

1. Crear **otro contenedor/instancia** MySQL 8.4.9, con nombre y volumen exclusivos, puerto de host libre (por ejemplo `127.0.0.1:3310`) y base `gsea_dashboard_sandbox`. No reutilizar el contenedor, volumen ni puerto 3309 de `mysql-gsea`.
2. Usar contraseñas nuevas y un usuario `gsea_sandbox` con permisos solo en `gsea_dashboard_sandbox.*`; no usar root de la base habitual. No montar directorios o volúmenes de datos originales. Guardar las credenciales fuera del repositorio.
3. Preparar un directorio separado del backend con el código actual y configuración exclusiva. No copiar `.env`, cachés de configuración/rutas, tokens, claves de WhatsApp/Google ni credenciales de servicios reales. No arrancar scheduler/workers. Falsificar los transportes o bloquear salida de red para las pruebas que crean pólizas; `QUEUE_CONNECTION=sync` por sí solo no impide envíos directos.
4. Comprobar desde **la conexión que usará el ejecutor de migraciones**:

```sql
SELECT DATABASE(), CURRENT_USER(), VERSION(), @@hostname, @@port;
SHOW GRANTS FOR CURRENT_USER();
```

Debe resultar `gsea_dashboard_sandbox`, usuario limitado y servidor distinto del habitual. Verificar además el puerto de host en la configuración efectiva de Laravel, que `DB_URL` no lo sustituya y que no se haya cargado una configuración cacheada de producción. El puerto interno puede ser 3306 en ambos contenedores: no es prueba de que sean el mismo servidor. La verificación necesita nombre del servidor/contenedor, conexión efectiva y volumen separados.

5. Solo tras confirmar lo anterior, importar un esquema sin datos exportado por lectura de la habitual o preparar un esquema representativo con fixtures sintéticos. No copiar datos personales reales. No restaurar rutinas/eventos que ejecuten automatizaciones. Preservar tipos, motores, índices y FK al preparar el esquema.
6. Aplicar **solo estas tres rutas de migración**, no todas las migraciones pendientes del repositorio. El proyecto contiene otros cambios locales/migraciones que no pertenecen a esta activación. No usar `--force` como sustituto de comprobar conexión.
7. Comparar antes/después todas las columnas anteriores de filas sintéticas, no solo sus conteos. Comprobar NULL históricos, nuevos estados, unicidad de metas y datos de otras monedas/workspaces. Probar permisos con admin, operador y usuario sin permiso.
8. Probar la eliminación de una anterior con renovaciones y de un workspace con metas; confirmar los riesgos descritos hasta que se apruebe una solución. Probar también ciclos/cadenas ajenas por la API.
9. Ensayar `down/up` de cada migración y restauración del respaldo **solo en el sandbox**, comprobando PK, FK e índices. Las pruebas previas SQLite no verifican ENUM, DDL, FK y bloqueos de MySQL.

Las instrucciones son un plan; no se ejecutó ningún paso de creación, importación, migración, seeding o escritura MySQL durante esta revisión.

## Orden de activación propuesto

1. Resolver y aprobar las dos políticas de eliminación y confirmar roles administradores destinatarios.
2. Crear y acreditar el sandbox; ejecutar el ensayo de esquema y de comportamiento, corregir los bloqueos y repetir únicamente las verificaciones afectadas.
3. Obtener autorización específica para actuar en la base habitual; preparar respaldo recuperable y revisar migraciones pendientes.
4. Aplicar las tres migraciones aprobadas por ruta, con comprobación de conexión inmediatamente antes. Provisionar únicamente los dos permisos nuevos mediante el seeder específico aprobado.
5. Recargar sesiones para actualizar permisos; validar Dashboard y formularios autenticados, comprobando que históricos sin estado no se cuentan como abiertos.
6. Mantener tasa de renovación en N/D hasta aprobar elegibilidad, exclusiones/cancelaciones y corte temporal. La existencia de un vínculo por sí sola no completa ese cálculo.

## Archivos modificados en esta revisión

Únicamente este reporte. No se modificaron migraciones, seeders, controladores, frontend, lógica operativa ni configuración de conexión.
