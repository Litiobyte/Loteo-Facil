# Mapa del proyecto — Loteo Facil

> Ayuda de navegación; verifica el código antes de cambiar comportamientos. Actualiza solo las secciones afectadas.

## Directorios y módulos
| Ruta | Responsabilidad |
| --- | --- |
| `app/Domain/{Accounting,Balances,Charges,Collections,Expenses,Owners,Statements,Users}/Services` | Lógica financiera/dominio (reglas de negocio obligadas a vivir aquí) |
| `app/Domain/*/Enums` | Estados de dominio (`ChargeStatus`, `ExpenseStatus`, `CollectionStatus`, `AccountingPeriodStatus`, etc.) |
| `app/Models` | Eloquent: `AccountingPeriod(+Snapshot)`, `Collection(+Allocation)`, `Expense(+Category,+FundingPayment,+children)`, `PartnerCharge`, `PartnerChargeConsolidationLink`, `Propietario`, `Lote`, `Etapa`, `Region/Comuna`, `User` |
| `app/Domain/Accounting/Services/ClosingProcessingService` | Pre-cierre: distribuye gastos vencidos y consolida morosidad dentro de `closePeriod()` (no invocarlo directo desde Filament) |
| `app/Domain/Expenses/Services/RecurringExpenseService` | Gasto mensual fijo: serie en 1 transacción, máx 12 instancias incluida cabeza, `cancelFutureInstances`/`updateFutureInstances` solo hijos `Registered` |
| `database/migrations/2026_11_16_*` | Aditivas: recurrencia en `expenses` (`is_recurring`, `recurrence_end_date`, `parent_expense_id`), consolidación en `partner_charges` (`charge_type`, `consolidation_year/month`, CHECK `consolidated` en MySQL), tabla `partner_charge_consolidation_links` (`transferred_amount` congelado), `unique(year,month)` en `accounting_periods` |
| `app/Filament/Admin` | Panel `/admin` (`super_admin`, `admin`): Resources, Widgets, Pages |
| `app/Filament/Owner` | Panel `/propietario` (solo lectura, scoped al propietario autenticado) |
| `app/Filament/Resources` | Recursos base: `Lotes`, `Propietarios`, `Users` |
| `app/Policies`, `app/Rules` | Autorización (p. ej. matriz de roles) y reglas de validación (p. ej. `ChileanRut`) |
| `database/factories` | Factories por modelo; `PartnerChargeFactory` crea `Expense` anidado |
| `docker/`, `Dockerfile` | Despliegue productivo (nginx + supervisor + php-fpm); sin `docker-compose` |
| `routes/web.php` | Solo `/` y `/health`; Filament registra sus rutas por panel |

## Entradas y límites arquitectónicos
- Entrada: `public/index.php` → bootstrap Laravel; HealthCheck en `routes/web.php`.
- Límite: `app/Filament` → `app/Domain/*Services` — los paneles delegan reglas financieras a servicios de dominio; no implementar reglas en recursos/widgets.
- Límite: `app/Models` (`Expense`, `Collection`, etc.) — invariantes en `booted()` con `Validator` + `DomainException` (p. ej. `funded_amount <= amount`).
- Límite: paneles owner solo lectura y scoped por propietario autenticado (ver `app/Filament/Owner`, tests `tests/Feature/Owner/`).

## Estado UI panel admin (18-11-2026)
- Ancho completo: `AdminPanelProvider` con `->maxContentWidth(Width::Full)`.
- Ancho de columnas: helper `app/Support/AdminTableColumns.php` aplica a 11 tablas admin (`width('fit-content')` + `extraCellAttributes` máx 15.625rem, `wrap`/`wrapHeader`; sin límites de caracteres). Owner y widgets intactos.
- Tabla Gastos: acciones de fila en `Filament\Actions\ActionGroup` (menú 3 puntos verticales); bulk replican fila (distribuir/cancelar/eliminar seleccionados, válida por registro, solo `Registered`, fallo suave por fila); "registrar pago de gasto" es solo de fila (monto individual + validación de caja).
- Base común de tablas admin (buscador, filtros reales, `toggleable()` por columna, bulk respetando políticas, orden por columnas): `ShareHolding`, `CommunitySetting`, `PartnerCharge`, `Collection`, `ExpenseCategory`, `ExpenseFundingPayment`, `AccountingPeriods`, `Expense` (ya cumplía), `Lote` (filtros Estado/Etapa), `Propietario` (filtro Región; búsqueda por `nombre`/`apellido`), `User` (filtro Rol; orden de Rol por subconsulta, `MorphToMany` no se ordena automático). `share_quantity`/`share_area` no ordenables (cálculo PHP). Widget `LatestCollectionsTableWidget` y panel Owner sin cambios.
- Form de creación de períodos contables (`AccountingPeriodForm.php`): selects de año (actual − 5 a actual, sin futuros) y mes (12 meses es-ES, default mes en curso), validación anti-períodos-futuros, `close_folio`/campos de cierre ocultos en creación (solo se generan en `MonthlyClosingService`); Edit intacto. Nota: la suite Filament requiere GD; sin ella falla el run estándar.
- Tests UI: `FilamentAdminTableDefaultsTest`, `FilamentCoreTableControlsTest`, `FilamentMonthlyClosingUiTest` (8 pruebas); 72 tests Filament aprobados / 828 aserciones.

## Configuración y pruebas
- Configuración: `.env.example` (SQLite por defecto, session/cache/queue en `database`), `docker/php/php.ini`, `docker/nginx/default.conf`, `phpunit.xml`, `vite.config.js`, `composer.json` (PHP ^8.3, Laravel ^13.8, Filament ^5.6, Livewire 4, Spatie Permission).
- Pruebas: PHPUnit 12 en `tests/Feature` (servicios + Filament + autorización) y `tests/Unit` (modelos + `ChileanRut`). Convención: `php artisan make:test --phpunit`.
- Documentación existente: `README.md`, `AGENTS.md`, `contexto-loteo-facil-codex.md`, `docs/`.
- Comandos verificados (entorno Docker local vía `Dockerfile.dev`, imagen `loteo-facil-dev`):
  - `docker build -f Dockerfile.dev -t loteo-facil-dev .` (PHP 8.3 + intl/bcmath/pdo_sqlite/zip/gd + Composer)
  - `docker run --rm -v "${PWD}:/app" -w /app loteo-facil-dev composer install`
  - `php artisan key:generate --force && php artisan migrate --seed --force`
  - Servir: `docker run -d --name loteo-facil-serve -p 8010:8000 -v "${PWD}:/app" -w /app loteo-facil-dev php artisan serve --host=0.0.0.0` → `http://127.0.0.1:8010` (8000 ocupado en el host)
  - Tests: `php artisan test --compact` (última verificación completa SQLite: 327 pass / 1 fallo ajeno `LoteMapActionsTest`; requiere GD para la suite Filament) o con `--filter=`
- Usuarios sembrados: `superadmin@loteofacil.cl` / `admin@loteofacil.cl` / `propietario@loteofacil.cl` con `password` (detalle en `README.md`).

## Estado del módulo de cierres contables (implementado, pendiente commit)
- Listo y en verde: distribución de vencidos al cierre (`due_date < period_end`; `== period_end` o `null` excluidos y reportados en `snapshot.metadata`), consolidación como transferencia (originales a `ChargeStatus::Consolidated` con importes intactos, cobro "Saldo {Mes/Año}", validación `SUM(links.transferred_amount) == amount` del consolidado), imputación contable por `due_date`/`period_end` en snapshot/CSV/aging, reapertura bloqueada si hay asignaciones al consolidado o cobros consolidados con `paid_amount > 0`, recierre idempotente (con nuevos orígenes rechaza), UI admin (toggle recurrente, acciones futuras, modal previa del cierre) y Owner (badge "Saldo {Mes}", scoped, solo lectura).
- Tests: `RecurringExpenseServiceTest`, `ClosingProcessingServiceTest`, `MonthlyClosingIntegrationTest`, `FilamentMonthlyClosingUiTest`, `Owner/MisCobrosResourceTest` + extensiones en `MonthlyClosingServiceTest`, `ChargeGenerationTest`, `FilamentExpenseResourcesTest`, `FilamentPartnerChargeResourceTest`.
- `Expense.expense_date` admite fecha futura SOLO si `parent_expense_id != null` (hijas de serie); la cabeza sigue con `<= today`.
- Pendientes: verificar CHECK de MySQL en servidor real (tests corren en SQLite); `total_charges` del snapshot es métrica bruta (emisión + traspaso informativo); reintento de serie recurrente requiere `head_id`; fallo preexistente en `LoteMapActionsTest` (módulo mapas, ajeno).

## Restricciones
- Acciones/modalidad: configuración única de instalación `CommunitySetting` (id 1, default `lots`), auditoría `community_setting_revisions`, tenencias `ShareHolding` y movimientos inmutables `ShareMovement`. Reglas en `app/Domain/Owners/Services/{CommunitySettingsService,ShareHoldingService}.php`; sin multitenancy.
- `ExpenseDistributionType` agrega `proportional_by_shares` y `proportional_by_equivalent_area`; `ExpenseDistributionService` extiende el motor actual con decimales exactos y mayor residuo, manteniendo cálculo histórico por lotes. `PartnerCharge.distribution_snapshot` congela bases. Equivalencia por adquisición, preservada en transferencias.
- UI: `app/Filament/Admin/Resources/{CommunitySettingResource,ShareHoldingResource}.php`, `app/Filament/Owner/Resources/ShareHoldingResource.php`; navegación lotes/mapas condicional. Pruebas nuevas `ShareAdministrationTest`, `ShareFilamentTest`, `ShareFinancialIntegrationTest`.
- Estados de cuenta: `PartnerBalanceService.getTotalCharges` mide emisión original (excluye traspasos); `PartnerStatementService` da impacto neutro a consolidaciones; desglose owner excluye originales consolidados. Snapshot contable conserva total bruto de movimientos.
- Verificación acciones (17-11-2026): suite completa SQLite 327 pass / 1 fallo previo `LoteMapActionsTest::test_ver_en_mapa_builds_shared_map_panel` (`viewer.blade.php`, `$this` sin contexto Livewire). Ejecución con 512 MB solo en contenedor de tests. Regresión final balance/estado/acciones/owner: 42 pass. Tras cambios UI admin: 60 tests Filament en verde (`--filter=Filament`).
- Toda regla financiera vive en `app/Domain/*/Services`; agregar tests para cambios en reglas financieras (`AGENTS.md`).
- No modificar dependencias ni crear carpetas base nuevas sin aprobación.
- No modificar migraciones históricas; migraciones nuevas aditivas con `down()` reversible.
- El usuario ejecuta `php artisan serve`/migraciones directamente en el host (sin Docker).
- No incluir secretos en docs; no correr `pint --test`, usar `vendor/bin/pint --dirty` tras editar PHP.
