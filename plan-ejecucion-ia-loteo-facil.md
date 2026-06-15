# Plan de Ejecucion con IA - Loteo Facil

## Objetivo

Construir el MVP de Loteo Facil usando IA como apoyo principal de desarrollo, manteniendo control tecnico humano en las decisiones criticas de negocio, arquitectura, datos financieros y seguridad.

El MVP debe permitir administrar socios, lotes, hectareas, gastos, distribucion de gastos, cobros, pagos, pagos parciales, saldos a favor, estados de cuenta y reportes basicos.

Stack recomendado:

- Laravel
- Filament
- MySQL o MariaDB
- Dos paneles Filament separados: `/admin` y `/propietario`
- Servicios de dominio para la logica financiera

---

## Principio central

La IA puede acelerar mucho el desarrollo, pero el sistema maneja dinero y trazabilidad. Por eso el trabajo debe dividirse asi:

- La IA implementa, genera CRUDs, tests, servicios, vistas, documentacion y refactors.
- El humano valida reglas de negocio, pantallas clave, formulas, permisos y datos reales.
- Toda logica financiera debe tener tests automatizados.
- No se debe avanzar a pantallas bonitas sin tener primero bien modelados gastos, cobros, pagos y aplicaciones.

---

## Roles de IA recomendados

### 1. Arquitecto Laravel

Responsable de:

- Modelo de datos.
- Migraciones.
- Relaciones Eloquent.
- Servicios de negocio.
- Separacion entre admin, portal del propietario y dominio financiero.

Usarlo para:

- Disenar entidades.
- Revisar si una regla esta bien modelada.
- Detectar deuda tecnica temprana.

### 2. Desarrollador Filament

Responsable de:

- Resources.
- Forms.
- Tables.
- Filters.
- Actions.
- Relation managers.
- Pages administrativas.

Usarlo para:

- Socios.
- Lotes.
- Asignaciones.
- Categorias.
- Gastos.
- Cobros.
- Pagos.
- Reportes basicos.

### 3. Especialista en Logica Financiera

Responsable de:

- Distribucion de gastos.
- Generacion de cobros.
- Aplicacion de pagos.
- Pagos parciales.
- Saldos a favor.
- Estado de cuenta.
- Tests de casos borde.

Este es el rol mas importante del proyecto.

### 4. QA / Tester

Responsable de:

- Crear escenarios de prueba.
- Revisar flujos completos.
- Probar casos borde.
- Validar que los saldos cuadren.
- Revisar permisos por rol.

### 5. Documentador

Responsable de:

- README.
- Guia de instalacion.
- Guia de uso administrador.
- Guia de uso propietario.
- Checklist de despliegue.
- Registro de decisiones tecnicas.

---

## Skills y capacidades necesarias

### Skills tecnicas

- Laravel migrations, models, factories y seeders.
- Eloquent relationships.
- Filament resources, actions y pages.
- Laravel policies y roles/permisos.
- Tests con PHPUnit o Pest.
- Manejo de transacciones de base de datos.
- Validacion de formularios.
- Consultas agregadas para reportes.

### Skills de dominio

- Modelar cuenta corriente.
- Separar cobros de pagos.
- Aplicar pagos a multiples cobros.
- Mantener historial de calculos.
- Evitar recalcular historia destructivamente.
- Cuadrar saldos desde movimientos, no desde campos manuales.

### Herramientas de IA recomendadas

- Codex para implementar y revisar codigo dentro del repo.
- ChatGPT para pensar reglas de negocio, documentacion, prompts y escenarios de prueba.
- IA como revisor tecnico antes de cambios grandes.

---

## Estructura de trabajo recomendada

Cada bloque debe seguir este ciclo:

1. Definir alcance pequeno.
2. Pedir a la IA un plan tecnico corto.
3. Implementar.
4. Ejecutar tests.
5. Probar manualmente.
6. Revisar con foco en reglas de negocio.
7. Documentar decision o comportamiento.

No conviene pedir "haz todo el sistema" en un solo prompt. Conviene avanzar por modulos cerrados.

---

## Fase 0 - Preparacion del proyecto

### Objetivo

Dejar listo el entorno base para desarrollar.

### Tareas

- Crear proyecto Laravel.
- Configurar base de datos local.
- Instalar Filament.
- Configurar autenticacion.
- Definir roles iniciales:
  - super_admin
  - admin
  - propietario
- Definir estrategia de panel dual y reglas de acceso por panel:
  - `/admin` solo super_admin/admin
  - `/propietario` solo propietario
- Crear repositorio Git.
- Crear archivo `.env.example`.
- Configurar tests.

### Entregables

- Proyecto corre localmente.
- Login administrativo funcionando.
- Primer test automatizado pasando.

### Prompts utiles

```text
Actua como arquitecto Laravel senior. Revisa este proyecto base y propon una estructura inicial para un MVP con Filament, roles super_admin/admin/propietario y servicios de dominio financiero. No implementes todavia; primero dame el plan de archivos y responsabilidades.
```

```text
Implementa la configuracion inicial de Laravel + Filament para un panel administrativo con rol admin. Mantener cambios pequenos, siguiendo convenciones Laravel.
```

---

## Fase 1 - Modelo base de propietarios y lotes

### Objetivo

Registrar Propietarios, lotes y asignaciones.

### Modelos

- Propietario
- Lote
- LotePropietario

### Tareas

- Migraciones.
- Modelos Eloquent.
- Factories.
- Seeders de ejemplo.
- Resources Filament para propietarios y lotes.
- Asignacion de lotes a propietarios.
- Relacion 1:1 entre `User` con rol `propietario` y `Propietario`.
- Calculo de hectareas totales activas por propietario.

### Tests minimos

- Un propietario puede tener multiples lotes.
- Un lote asignado suma sus hectareas al propietario.
- El scope del panel propietario no permite ver lotes de otro propietario.

### Prompts utiles

```text
Implementa los modelos Propietario, Lote y LotePropietario con migraciones, relaciones Eloquent, factories y tests. El total de hectareas de un propietario debe calcularse desde sus lotes activos.
```

---

## Fase 0.1 - Panel propietario separado

### Objetivo

Habilitar un panel dedicado para propietario con aislamiento por rol y datos.

### Tareas

- Crear provider de panel propietario (`owner`) con ruta `/propietario`.
- Ajustar acceso de usuario por panel (`admin` vs `owner`).
- Crear recurso inicial de consulta (`Mi perfil`) en modo solo lectura.
- Aplicar scope estricto para que cada propietario vea solo sus datos.
- Agregar pruebas de acceso entre paneles y aislamiento de datos.

### Entregables

- Panel `/propietario` operativo para rol propietario.
- Panel `/admin` sigue operativo solo para super_admin/admin.
- Test de aislamiento de datos propietario en verde.

---

## Fase 2 - Gastos y categorias

### Objetivo

Registrar gastos de la sociedad y prepararlos para distribuir.

### Modelos

- ExpenseCategory
- Expense

### Tareas

- CRUD de categorias.
- CRUD de gastos.
- Campos de tipo de distribucion:
  - equal_by_partner
  - proportional_by_hectares
  - manual
- Estados del gasto:
  - draft
  - distributed
  - cancelled
- Validaciones.

### Tests minimos

- Un gasto requiere monto positivo.
- Un gasto requiere tipo de distribucion.
- No se puede distribuir un gasto cancelado.

---

## Fase 3 - Generacion de cobros

### Objetivo

Convertir gastos en cobros individuales por socio.

### Modelos

- PartnerCharge

### Servicios

- ExpenseDistributionService
- ChargeGenerationService

### Tareas

- Distribucion en partes iguales.
- Distribucion proporcional por hectareas.
- Distribucion manual.
- Guardar snapshot del calculo:
  - tipo de calculo
  - hectareas del socio en ese momento
  - hectareas totales en ese momento
  - porcentaje aplicado
  - monto calculado
- Evitar doble generacion accidental de cobros para el mismo gasto.

### Tests criticos

- 54 socios y gasto de 540.000 generan 54 cobros de 10.000.
- Gasto proporcional usa hectareas del momento.
- Cambiar hectareas despues no altera cobros historicos.
- No se puede distribuir dos veces el mismo gasto sin una accion explicita.

### Prompt util

```text
Implementa ExpenseDistributionService y ChargeGenerationService. Deben generar PartnerCharge desde un Expense segun equal_by_partner, proportional_by_hectares o manual. Usar transacciones de base de datos y tests para casos borde.
```

---

## Fase 4 - Pagos y aplicaciones

### Objetivo

Registrar pagos y aplicarlos a uno o varios cobros.

### Modelos

- Payment
- PaymentAllocation

### Servicios

- PaymentApplicationService
- PartnerBalanceService

### Tareas

- Registrar pago.
- Aplicar pago manualmente a cobros.
- Aplicar pago automaticamente a deuda mas antigua.
- Permitir pagos parciales.
- Generar saldo a favor si sobra dinero.
- Actualizar estados de cobros.
- Actualizar estados de pagos.

### Reglas

- Un pago puede cubrir varios cobros.
- Un cobro puede tener varios pagos aplicados.
- El saldo a favor es `payment.unapplied_amount`.
- El saldo pendiente se calcula desde cobros pendientes.
- Usar transacciones en toda aplicacion de pago.

### Tests criticos

- Pago exacto deja cobro pagado.
- Pago parcial deja cobro partial.
- Pago mayor a deuda deja saldo a favor.
- Pago de 100.000 puede aplicarse a tres cobros.
- No se puede aplicar mas que el saldo disponible del pago.
- No se puede aplicar mas que el saldo pendiente del cobro.

---

## Fase 5 - Estado de cuenta

### Objetivo

Mostrar resumen financiero claro por socio.

### Servicio

- PartnerStatementService

### Debe mostrar

- Lotes asignados.
- Hectareas totales.
- Total cobrado.
- Total pagado.
- Total aplicado.
- Saldo pendiente.
- Saldo a favor.
- Detalle de cobros.
- Detalle de pagos.
- Detalle de aplicaciones.

### Tests minimos

- El resumen cuadra con cobros, pagos y aplicaciones.
- Socio sin deuda y con excedente muestra saldo a favor.
- Socio con pago parcial muestra deuda pendiente correcta.

---

## Fase 6 - Panel administrativo completo

### Objetivo

Que el administrador pueda operar el sistema.

### Pantallas Filament

- Socios.
- Lotes.
- Asignaciones.
- Categorias de gastos.
- Gastos.
- Accion para distribuir gasto.
- Cobros.
- Pagos.
- Accion para aplicar pago automaticamente.
- Accion para aplicacion manual.
- Estado de cuenta por socio.
- Reporte de deudas.
- Reporte de pagos.

### Validaciones importantes

- Confirmaciones antes de distribuir gastos.
- No editar cobros historicos sin control.
- No borrar pagos con aplicaciones sin accion especial.
- Mostrar montos en CLP.

---

## Fase 7 - Portal del propietario

### Objetivo

Que cada propietario pueda consultar su informacion.

### Pantallas

- Login propietario.
- Mis lotes.
- Mis hectareas.
- Mis cobros.
- Mis pagos.
- Mi estado de cuenta.
- Saldo pendiente.
- Saldo a favor.

### Restriccion clave

Un propietario solo puede ver su propia informacion.

### Tests minimos

- Un propietario no puede ver datos de otro propietario.
- Un admin puede ver todos.
- Usuario sin partner asociado no entra al portal del propietario.

---

## Fase 8 - Reportes basicos

### Reportes MVP

- Socios con deuda.
- Pagos por fecha.
- Gastos distribuidos.
- Saldos a favor.
- Resumen por socio.

### Entregables

- Filtros por fecha.
- Filtros por socio.
- Totales visibles.
- Exportacion puede quedar fuera del MVP si se necesita reducir alcance.

---

## Fase 9 - Carga inicial y QA

### Objetivo

Preparar el sistema para la primera sociedad de 54 socios.

### Tareas

- Crear plantilla de carga.
- Cargar socios.
- Cargar lotes.
- Asignar lotes.
- Verificar hectareas totales.
- Crear gastos reales de prueba.
- Generar cobros.
- Registrar pagos ficticios.
- Validar saldos con casos manuales.

### Checklist QA

- Los 54 socios aparecen activos.
- Total de hectareas cuadra.
- Gasto igualitario divide correctamente.
- Gasto proporcional divide correctamente.
- Pago parcial funciona.
- Pago excedente genera saldo a favor.
- Estado de cuenta se entiende.
- Permisos estan correctos.

---

## Fase 10 - Despliegue 

### Objetivo

Publicar una primera version usable.

### Tareas

- Hosting.
- Base de datos.
- Backups.
- Variables de entorno.
- Usuario admin inicial.
- HTTPS.
- Politica de respaldos.
- Prueba de restauracion.
- Guia basica para administrador.

### No olvidar

- Backup automatico diario.
- Acceso restringido al panel.
- Logs de errores.
- Credenciales seguras.

---

## Orden recomendado de implementacion

1. Proyecto base Laravel + Filament.
2. Socios, lotes y asignaciones.
3. Gastos y categorias.
4. Generacion de cobros.
5. Pagos y aplicaciones.
6. Saldos y estado de cuenta.
7. Panel administrativo final.
8. Portal del propietario.
9. Reportes.
10. QA, carga inicial y despliegue.

---

## Checklist de control humano

El humano debe revisar si o si:

- Modelo de datos financiero.
- Reglas de distribucion.
- Aplicacion de pagos.
- Calculo de saldo a favor.
- Estado de cuenta.
- Permisos super_admin/admin/propietario.
- Datos iniciales de socios y lotes.
- Primer despliegue.

---

## Definicion de terminado del MVP

El MVP esta listo cuando:

- Admin puede registrar socios y lotes.
- Admin puede asignar lotes.
- El sistema calcula hectareas por socio.
- Admin puede registrar gastos.
- Admin puede distribuir gastos y generar cobros.
- Admin puede registrar pagos.
- Pagos pueden aplicarse a uno o varios cobros.
- Existen pagos parciales.
- Existe saldo a favor.
- Propietario puede ver su estado de cuenta.
- Reporte basico de deuda existe.
- Tests financieros principales pasan.
- Hay backup y despliegue funcional.

---

## Forma recomendada de trabajar con Codex

Para cada modulo, usar pedidos concretos:

```text
Lee el contexto del proyecto y trabaja solo en el modulo de [NOMBRE]. Primero revisa archivos existentes, luego implementa migraciones, modelos, tests y recursos Filament necesarios. Mantener la logica de negocio en servicios y ejecutar tests al final.
```

Para revisiones:

```text
Haz una revision critica de este modulo. Busca errores de negocio, problemas de saldos, permisos incorrectos, falta de transacciones y tests faltantes. No refactorices todavia; primero lista hallazgos por severidad.
```

Para QA:

```text
Crea escenarios de prueba manuales y automatizados para este flujo financiero. Incluye pago parcial, pago excedente, gasto proporcional por hectareas y cambio posterior de hectareas.
```

---

## Riesgos principales

### Riesgo 1: Saldos incorrectos

Mitigacion:

- Tests automatizados.
- Servicios de dominio.
- Transacciones.
- No editar historia destructivamente.

### Riesgo 2: Permisos mal aplicados

Mitigacion:

- Policies.
- Tests de acceso.
- Separar panel admin y portal propietario.

### Riesgo 3: Complejidad excesiva

Mitigacion:

- Mantener MVP simple.
- No incluir integraciones al inicio.
- No crear SPA.

### Riesgo 4: IA genera codigo plausible pero incorrecto

Mitigacion:

- Cambios pequenos.
- Tests.
- Revision humana.
- Revisiones especificas de reglas financieras.

---

## Recomendacion final

La mejor estrategia es construir primero un nucleo financiero solido, aunque las pantallas sean simples. Cuando gastos, cobros, pagos, aplicaciones y saldos esten bien probados, Filament permite completar rapido la experiencia administrativa.

El orden correcto no es "pantallas primero". El orden correcto es:

1. Datos.
2. Reglas.
3. Tests.
4. Admin.
5. Portal.
6. Reportes.
7. Despliegue.
