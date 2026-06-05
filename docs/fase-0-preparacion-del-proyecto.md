# Fase 0 - Preparacion del proyecto

## Estado

- Estado general: completada.
- Tipo: preparacion tecnica base + decisiones iniciales.
- Alcance: entorno local, base Laravel, panel admin + panel propietario, roles iniciales, pruebas base, plan IA por fases.

## Decisiones tomadas

| Decision | Opciones evaluadas | Decision aplicada | Motivo |
| --- | --- | --- | --- |
| Base de datos de desarrollo | SQLite, MySQL, PostgreSQL | SQLite para bootstrap local | Permite iniciar rapido sin depender de servicio externo. |
| Base de datos objetivo MVP | MySQL/MariaDB, PostgreSQL | MySQL/MariaDB (objetivo) | Alineado con contexto del proyecto y operacion esperada. |
| Paneles de aplicacion | Panel unico vs dual panel | Dual panel Filament (`/admin` y `/propietario`) | Separa operacion administrativa de consulta del propietario y reduce riesgo de fuga de datos. |
| Roles y permisos | Gates manuales, Spatie Permission | Spatie Laravel Permission | Control de roles explicito para super_admin/admin/propietario. |
| Autenticacion inicial | Breeze, Jetstream, solo Filament login | Login de Filament por panel y rol | Mantiene separacion explicita entre backoffice y portal propietario. |
| Regla de acceso por panel | Cualquier usuario autenticado, acceso por rol | `/admin` para `super_admin`/`admin`, `/propietario` para `propietario` | Evita exposicion de modulos administrativos y limita acceso por contexto de uso. |

## Arquitectura inicial acordada

### Limites por capa

- Controllers/Resources: orquestacion, validacion de entrada y salida.
- Domain Services: reglas financieras y calculos de negocio (proximas fases).
- Models: persistencia y relaciones.
- Policies/Permissions: autorizacion por rol y permisos.

### Convenciones iniciales

- Mantener logica financiera fuera de Resources Filament.
- Usar transacciones para operaciones financieras que muevan montos.
- Acompanhar cambios financieros con pruebas automatizadas.
- Evitar recalculo destructivo de historicos de cobros/pagos.

## Implementacion realizada en Fase 0

- Proyecto Laravel 13 creado e instalado en el repositorio.
- Filament instalado con panel admin (`/admin`) y panel propietario (`/propietario`).
- Spatie Permission instalado y publicado (`config` + migracion).
- Usuario adaptado para Filament con acceso por panel (`admin` y `owner`) segun rol.
- Seeder base con roles `super_admin`, `admin`, `propietario`.
- Seeders con usuarios iniciales por rol (`superadmin`, `admin`, `propietario`).
- Ruta de salud `/health` para verificacion operacional.
- Test de health-check agregado para validacion automatizada.
- Resource inicial de propietario (`Mi perfil`) en modo solo lectura con scope por usuario autenticado.
- README actualizado con instalacion, accesos y alcance.

## Plan de ejecucion IA (Fase 1 a 5)

| Fase | Agente lider | Entrada requerida | Salida esperada | Criterio de handoff |
| --- | --- | --- | --- | --- |
| Fase 1 - Propietarios/Lotes | laravel-architect + filament-developer | Base Fase 0 estable | Modelos `Propietario`, `Lote`, `LotePropietario`, migraciones, resources y tests base | Migraciones limpias + tests de propietarios/lotes en verde |
| Fase 2 - Gastos | laravel-architect + financial-logic-specialist | Modelo socios/lotes operativo | `ExpenseCategory`, `Expense`, validaciones y estados | Gasto registrable con reglas de distribucion definidas |
| Fase 3 - Cobros | financial-logic-specialist | Gastos listos para distribuir | `PartnerCharge` + `ExpenseDistributionService` + tests criticos | Cobros generados con snapshot historico y consistencia |
| Fase 4 - Pagos | financial-logic-specialist + financial-qa | Cobros operativos | `Payment`, `PaymentAllocation`, aplicacion manual/automatica, saldo a favor | Saldos cuadran y casos borde pasan |
| Fase 5 - Estado de cuenta | financial-logic-specialist + filament-developer | Cobros/pagos consistentes | `PartnerStatementService`, vistas admin/propietario, reportes base | Estado de cuenta validado funcional y tecnicamente |

## Riesgos vigentes y mitigacion

- Riesgo: mezclar logica financiera en UI administrativa.
  - Mitigacion: obligar uso de servicios de dominio para reglas de negocio.
- Riesgo: errores en saldos por aplicaciones parciales.
  - Mitigacion: pruebas de flujo financiero y QA por casos borde.
- Riesgo: fuga de datos entre propietarios en panel de consulta.
  - Mitigacion: panel separado + scopes por usuario + pruebas de aislamiento por URL y listado.

## Bloqueadores actuales

- No hay bloqueadores tecnicos para iniciar Fase 1.

## Decisiones que puede tomar el equipo hoy

- Confirmar si se mantiene SQLite en local o se cambia a MySQL local desde Fase 1.
- Definir formato final de autenticacion del portal propietario (Filament separado o Blade).
- Confirmar orden y estrategia de redondeo para distribuciones monetarias.

## Checklist Ready for Fase 1

- [x] Proyecto Laravel funcional en local.
- [x] Panel administrativo accesible.
- [x] Roles base definidos.
- [x] Usuario admin inicial disponible.
- [x] Migraciones y seeding ejecutan sin error.
- [x] Health-check disponible.
- [x] Test automatizado base pasando.
- [x] Documentacion base de onboarding actualizada.
