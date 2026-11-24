# Loteo Facil - Fases 0 y 1

## Administración por lotes y acciones

Después de ejecutar `php artisan migrate`, **Comunidad** permite a `admin` y
`super_admin` configurar la instalación. No se agregó multitenancy: la caja,
los propietarios y los cierres siguen perteneciendo a una sola comunidad por instalación.

| Modalidad | Navegación | Nuevas distribuciones |
| --- | --- | --- |
| `lots` (predeterminada) | Lotes y mapas; conserva el flujo anterior | Igualitaria con asignaciones activas, hectáreas reales |
| `shares` | Acciones, sin lotes ni mapas en el menú | Igualitaria entre todos los propietarios registrados, acciones, superficie equivalente |
| `mixed` | Acciones, lotes y mapas | Igualitaria entre titulares de acciones vigentes o asignaciones activas, acciones, superficie equivalente, hectáreas reales |

El criterio manual se conserva, pero su generación sigue sin estar implementada.
Las asignaciones activas de lotes conservan exactamente la elegibilidad anterior.
Para acciones son elegibles tenencias `active` con cantidad positiva. Una base proporcional
vacía se rechaza sin emitir cobros. En modo mixto nunca se suman acciones y lotes como una sola base.

### Operación de acciones

1. Configurar modalidad, m² por acción, precisión (0–4 decimales) y total autorizado opcional.
2. Registrar propietarios sin necesidad de asociarles terrenos.
3. En **Acciones**, registrar adquisiciones con cantidad, fecha y motivo.
4. Usar **Ajustar** para rectificar cantidad o activar/inactivar; **Transferir** para ceder
   una cantidad a otro propietario. Cada operación tiene clave idempotente, administrador
   y movimientos auditables. No se permite eliminación desde la aplicación.
5. Crear y distribuir gastos mediante el recurso existente; cobrar y aplicar recaudaciones normalmente.
6. Consultar participaciones e historial en la ficha del propietario y en **Mis acciones**
   del portal, aislado al titular autenticado.

La superficie equivalente es `cantidad × m²_por_acción` de cada adquisición, sumada por titular.
La equivalencia queda congelada al adquirir y se conserva al transferir. Un cambio de
configuración rige para adquisiciones posteriores, con historial de valores anteriores,
nuevos, motivo y administrador. Por ello, si existen distintas equivalencias históricas,
el reparto por superficie equivalente puede diferir del reparto por cantidad de acciones.
Las tenencias inactivas no participan del reparto, pero siguen consumiendo el total autorizado.
Los ajustes a cero conservan el registro y sus movimientos.

La aritmética nueva usa decimales exactos (`Brick\Math`, ya instalado). Los centavos
residuales se asignan por mayor residuo y, en empate, por ID de propietario. No se emiten
cobros de importe cero. `PartnerCharge.distribution_snapshot` conserva base individual,
total elegible, equivalencias y adquisiciones utilizadas, criterio, redondeo e importe.
La base se toma al generar cobros; no se reconstruye retroactivamente según la fecha del gasto.
Los criterios antiguos mantienen sus cálculos existentes y también guardan un snapshot.
En estados de cuenta, consolidar es un traspaso: no suma otra emisión ni otro incremento
de deuda en el historial. El desglose de deuda excluye originales ya consolidados.
El snapshot contable conserva su métrica bruta de movimientos emitidos y traspasados.

### Transiciones y compatibilidad

Cambiar modalidad solo habilita criterios y modifica navegación: no borra participaciones,
lotes, deudas, recaudaciones ni cierres. Antes de deshabilitar un criterio deben distribuirse
o cancelarse los gastos registrados que lo utilicen, incluidas instancias recurrentes.
No puede reducirse la precisión si hay fracciones incompatibles ni el total autorizado por debajo del emitido.
La configuración no modifica períodos cerrados ni recalcula cobros.

Se pueden registrar terrenos posteriormente usando el módulo existente y pasar a `mixed`.
No se crean lotes ficticios, no hay correspondencia obligatoria entre acciones y terrenos
ni ajuste automático entre sus superficies. En modo `lots`, el historial de acciones
permanece almacenado y accesible administrativamente, aunque no aparezca en el menú.

### Rutas técnicas y verificación

- Dominio: `app/Domain/Owners/Services/{CommunitySettingsService,ShareHoldingService}.php`.
- Distribución: `app/Domain/Expenses/Services/{ExpenseDistributionService,ChargeGenerationService}.php`.
- Modelos: `CommunitySetting`, `ShareHolding`, `ShareMovement`; revisiones en `community_setting_revisions`.
- Migración aditiva: `database/migrations/2026_11_17_142641_create_community_share_administration_tables.php`.
- UI: recursos administrativos `CommunitySettingResource` y `ShareHoldingResource`,
  recurso propietario `ShareHoldingResource` y relación `ShareHoldingsRelationManager`.
- Pruebas: `ShareAdministrationTest`, `ShareFilamentTest`, `ShareFinancialIntegrationTest`.

```bash
php artisan test --compact tests/Feature/ShareAdministrationTest.php tests/Feature/ShareFilamentTest.php tests/Feature/ShareFinancialIntegrationTest.php tests/Feature/ChargeGenerationTest.php
```

Límites: no existía importador administrativo tabular; no se agregó importación CSV/Excel de
acciones ni se modificó el importador geográfico. Los reportes existentes de superficie real
siguen mostrando lotes; la superficie equivalente se consulta por separado. MySQL requiere
validación de migración y concurrencia en un entorno de ensayo; SQLite no reproduce bloqueos
de fila ni la implementación de CHECK de MySQL. La reversión MySQL se bloquea si hay gastos
o cobros con los nuevos criterios, para no invalidar su historial.

Verificación del 17-11-2026: suite completa SQLite con 512 MB de memoria PHP:
**327 pruebas pasan y 1 falla**. El fallo preexistente es
`LoteMapActionsTest::test_ver_en_mapa_builds_shared_map_panel`, por uso de `$this`
fuera de un componente Livewire en `resources/views/filament/maps/viewer.blade.php`.
Tras la corrección de estados de cuenta se ejecutaron además las 42 pruebas
enfocadas de integración de acciones, balances, estados y portal: todas pasan.
PHP no estaba disponible en el host de verificación; se utilizó la imagen local
`loteo-facil-dev` sin migrar la base de datos operativa.

Base inicial del proyecto Laravel para el MVP de Loteo Facil.

## Stack base

- Laravel 13
- PHP 8.3
- Filament 5 (panel administrativo)
- Spatie Laravel Permission (roles y permisos)
- SQLite por defecto para desarrollo rapido

## Preparacion local

1. Copiar variables de entorno:

   ```bash
   cp .env.example .env
   ```

2. Instalar dependencias PHP:

   ```bash
   composer install
   ```

3. Generar clave de aplicacion:

   ```bash
   php artisan key:generate
   ```

4. Ejecutar migraciones y seeders:

   ```bash
   php artisan migrate --seed
   ```

5. Levantar servidor local:

   ```bash
   php artisan serve
   ```

## Accesos iniciales

- Panel admin: `http://127.0.0.1:8000/admin`
- Panel propietario: `http://127.0.0.1:8000/propietario`
- Endpoint de salud: `http://127.0.0.1:8000/health`

Usuarios de demostración (solo desarrollo; no usar estos seeders en producción):

- Super Admin: `superadmin@loteofacil.cl` / `password`
- Admin: `admin@loteofacil.cl` / `password`
- Propietario (acceso solo a panel propietario): `propietario@loteofacil.cl` / `password`

## Producción en Coolify

El `Dockerfile` compila los assets e instala dependencias, pero **no ejecuta migraciones
ni seeders**. Un push a `main` actualiza el código; no es una instalación de datos.
Los siguientes comandos se ejecutan dentro del contenedor de la aplicación, en
`/var/www`, nunca durante el build de la imagen.

### Preparación (ambos casos)

- Usar una base MySQL persistente, externa al contenedor de la aplicación, con sus
  credenciales `DB_*` configuradas en Coolify. No crear una base nueva en cada deploy.
- Configurar `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` con el dominio HTTPS
  y `APP_KEY`. Para una instalación nueva generar una clave con
  `php artisan key:generate --show` y guardarla como secreto en Coolify.
  **En una instalación activa conservar exactamente la clave actual**.
- Persistir `/var/www/storage/app` mediante Persistent Storage para documentos,
  comprobantes y logos. Si ya existen archivos, respaldarlos y copiarlos al volumen
  antes de montarlo: un volumen vacío puede ocultar los archivos existentes.
- Respaldar base de datos y archivos antes de actualizar; verificar que el respaldo
  se pueda restaurar. El volumen persistente no sustituye un respaldo.

### Caso 1: instalación nueva, sin datos de demostración

Desde la terminal interactiva del contenedor de Coolify:

```bash
cd /var/www
php artisan migrate --force
php artisan db:seed --class=RegionSeeder --force
php artisan app:create-super-admin
php artisan storage:link
php artisan optimize
```

El comando solicita nombre, correo y contraseña oculta con confirmación (mínimo
12 caracteres, mayúsculas, minúsculas y números). Crea los tres roles base y
asigna `super_admin` al usuario nuevo. Rechaza correos ya registrados y no modifica
cuentas existentes. No acepta `--no-interaction` ni contraseña como argumento.
Ingresar en `/admin` y configurar la comunidad y los datos reales.

**No ejecutar `DatabaseSeeder` ni `CleanInstallSeeder` en producción**: crean
usuarios con contraseña de prueba; el primero además carga datos de demostración.

### Caso 2: actualización de una instalación activa

Mantener la misma base de datos, volúmenes y `APP_KEY`. Revisar las migraciones
pendientes con `php artisan migrate:status` y probarlas antes en una copia de la
base productiva. `migrate` aplica solo migraciones pendientes; no recrea la base,
pero cada migración puede transformar datos y debe revisarse.

Configurar en Coolify un comando post-despliegue que se ejecute sobre el
**contenedor nuevo** (o ejecutar manualmente tras desplegar):

```bash
cd /var/www && php artisan migrate --force && php artisan storage:link && php artisan optimize
```

No ejecutar seeders, creación de superadmin ni generación de clave en este hook.
No usar `migrate:fresh`, `migrate:refresh`, `db:wipe` ni importar una base local.
Para cambios incompatibles con el código anterior, programar una ventana de
mantenimiento; un hook posterior no garantiza despliegue sin interrupciones.

Comprobar el resultado del hook y `migrate:status`, `/health`, los dos logins,
datos históricos y archivos subidos. Si el hook falla, no asumir que la versión
quedó operativa. Volver al código anterior no revierte las migraciones: analizar
la compatibilidad y restaurar un respaldo solo como recuperación controlada.

## Roles base

- `admin`
- `super_admin`
- `propietario`

Matriz de acceso por panel:

- `/admin`: `super_admin`, `admin`
- `/propietario`: `propietario`

## Modulo de usuarios

- Ruta: `http://127.0.0.1:8000/admin/users`
- `super_admin` puede crear, editar y eliminar cualquier usuario.
- `admin` puede crear, editar y eliminar solo usuarios `propietario`.
- `admin` no puede crear ni gestionar usuarios `admin` o `super_admin`.
- `propietario` no tiene acceso al modulo de gestion de usuarios.

## Panel propietario (MVP)

- Ruta: `http://127.0.0.1:8000/propietario`
- Modulo inicial: `Mi perfil` en modo solo lectura.
- Scope de datos: cada propietario solo puede ver su propio registro.
- Sin acciones de crear/editar/eliminar en el panel propietario.

## Importaciones financieras historicas (etapas 0-6)

Modulo para incorporar historia financiera desde planillas o sistemas anteriores,
con integridad de caja, balances y estados de cuenta. Fuente de verdad del modulo:
`docs/IMPORTACION_FINANCIERA_PROGRESS.md`.

- Wizard en grupo «Importaciones» del panel admin; el modo financiero es
  exclusivo de `super_admin` (carga, mapeo, previsualizacion, correccion previa,
  confirmacion atomica por lote y resultados por fila con reintento de fallidas).
- Movimientos admitidos: saldos iniciales (deudor y acreedor), gastos historicos
  (solo registrar, distribucion con datos actuales confirmados, cobros explicitos),
  cobros (vinculado, con gasto de respaldo, apertura), recaudaciones
  (automatica FIFO, manual, sin aplicacion) y ajustes no monetarios.
- Idempotencia por identidad externa compartida: reimportar el mismo archivo no
  duplica; contenido distinto exige revision manual (`conflict`).
- Guard de periodos cerrados por fecha efectiva, revalidado dentro de la
  transaccion; no se reabren periodos ni se ejecutan cierres automaticos.
- Los ajustes no monetarios y las aperturas no tocan caja; los pagos reconocidos
  sin comprobante si la tocan y quedan distinguidos por evidencia.
- Conciliacion historica: pagina admin «Conciliacion historica» (solo
  `super_admin`) con desglose por propietario, reconocimiento de pagos sin
  comprobante con autorizante y clasificacion manual de diferencias; nunca hay
  correccion automatica.
- QA (Etapa 7): suite completa en SQLite y validacion sobre MySQL real con
  migraciones, colacion `utf8mb4_unicode_ci` y carrera de procesos sobre
  aplicaciones de pagos (`MysqlConcurrencyQaTest`, se omite fuera de MySQL).

## Pruebas

Ejecutar suite:

```bash
composer test
```

Incluye prueba de health-check para validar que la app responde correctamente.

## Alcance implementado

Fase 0 deja listo:

- Proyecto Laravel operativo.
- Filament instalado con panel admin.
- Estructura de roles inicial.
- Seeder con usuario administrador.
- Endpoint de salud y prueba automatizada.

Fase 1 deja listo:

- Modelo base `Propietario` (1:1 con `User` de rol `propietario`).
- Modelo base `Lote`.
- Pivote `lote_propietario` con trazabilidad (`assigned_at`, `unassigned_at`, `status`).
- Recurso admin de `Propietarios` con asignacion/desasignacion de lotes.
- Recurso admin de `Lotes` con validaciones de codigo y hectareas.
- Recurso owner `Mis lotes` en solo lectura y scoped por propietario autenticado.
- Tests de dominio y aislamiento para panel owner y elegibilidad de propietarios.

## Siguiente fase recomendada

Continuar con Fase 2: gastos y categorias, preparando la generacion de cobros por propietario.
