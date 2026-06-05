# Contexto del Proyecto — Loteo Fácil

## 1. Resumen general

### Nota de arquitectura de acceso (actualizada)

- El sistema opera con dos paneles separados:
  - Panel administrativo (`/admin`) para roles `super_admin` y `admin`.
  - Panel de propietario (`/propietario`) para rol `propietario`.
- En documentacion funcional se usa "socio" como concepto de negocio.
- En autorizacion y codigo se usa `propietario` como rol tecnico del portal.
- En Fase 1 tecnica se modela `Propietario` como entidad principal asociada 1:1 con `User` de rol `propietario`.

**Loteo Fácil** es un sistema de gestión para sociedades o comunidades que administran loteos, socios, lotes, hectáreas, gastos comunes, pagos, deudas y saldos a favor.

El objetivo principal es que la administración de una sociedad con loteos pueda llevar un control claro y transparente de:

- Socios.
- Lotes asignados a cada socio.
- Cantidad de hectáreas por lote.
- Total de hectáreas asignadas por socio.
- Gastos de la sociedad.
- Distribución de esos gastos entre socios.
- Cobros individuales generados por gastos.
- Pagos realizados por socios.
- Pagos parciales.
- Pagos que cubren varios conceptos.
- Saldos pendientes.
- Saldos a favor.
- Estado de cuenta individual por socio.

La primera sociedad objetivo tiene **54 socios**.

---

## 2. Problema que resuelve

Actualmente la administración de una sociedad con loteos puede volverse poco clara cuando existen:

- Socios con más de un lote.
- Lotes con distintas cantidades de hectáreas.
- Gastos que deben dividirse entre socios.
- Gastos que deben dividirse proporcionalmente según hectáreas.
- Pagos que no corresponden exactamente a un solo cobro.
- Pagos que cubren varios conceptos.
- Pagos parciales.
- Pagos superiores a la deuda, generando saldo a favor.
- Necesidad de transparentar a cada socio qué tiene pagado, qué debe y por qué.

El sistema debe permitir que tanto el administrador como cada socio puedan revisar la información de manera ordenada.

---

## 3. Concepto de negocio

La sociedad registra a sus socios y los lotes que cada uno tiene asignados.

Cada lote tiene una cantidad específica de hectáreas.  
Un socio puede tener uno o varios lotes.

La sociedad tiene gastos, por ejemplo:

- Contador.
- Trámites.
- Impuestos.
- Gastos legales.
- Administración.
- Mantención.
- Otros gastos comunes.

Cada gasto puede repartirse de distintas formas:

1. **En partes iguales entre socios.**
2. **Proporcionalmente según hectáreas.**
3. **Manual, para casos especiales.**

Cuando se registra un gasto, el sistema debe generar cobros individuales para cada socio según la regla de distribución seleccionada.

Luego, cuando un socio paga, el sistema debe permitir registrar el pago completo y aplicarlo contra uno o varios cobros pendientes.

Si el socio paga menos que su deuda, queda deuda pendiente.  
Si paga más que su deuda, queda saldo a favor.  
Ese saldo a favor puede utilizarse para futuros cobros.

---

## 4. Usuarios del sistema

### 4.1 Administrador

Puede:

- Crear y editar socios.
- Crear y editar lotes.
- Asignar lotes a socios.
- Ver hectáreas totales por socio.
- Crear gastos de la sociedad.
- Definir cómo se reparte cada gasto.
- Generar cobros a socios.
- Registrar pagos.
- Aplicar pagos a cobros.
- Ver deudas por socio.
- Ver saldos a favor.
- Ver reportes básicos.
- Revisar estados de cuenta.

### 4.2 Socio

Puede:

- Ingresar a su cuenta.
- Ver sus datos.
- Ver sus lotes asignados.
- Ver hectáreas por lote.
- Ver total de hectáreas asignadas.
- Ver cobros realizados.
- Ver pagos registrados.
- Ver deuda pendiente.
- Ver saldo a favor.
- Ver detalle de su estado de cuenta.

### 4.3 Contador o usuario administrativo secundario

Opcional para fases futuras.

Puede:

- Revisar gastos.
- Revisar pagos.
- Revisar reportes.
- No necesariamente administrar usuarios o configuración general.

---

## 5. Flujo funcional principal

### 5.1 Registro de socios

El administrador registra a los socios de la sociedad.

Cada socio debe tener al menos:

- Nombre.
- RUT o identificador.
- Email.
- Teléfono opcional.
- Estado: activo/inactivo.

Cada socio puede tener acceso al portal del socio.

---

### 5.2 Registro de lotes

El administrador registra los lotes disponibles.

Cada lote debe tener:

- Código o número de lote.
- Nombre o descripción.
- Cantidad de hectáreas.
- Estado.

Ejemplo:

- Lote A1 — 1.5 hectáreas.
- Lote A2 — 2 hectáreas.
- Lote B3 — 0.75 hectáreas.

---

### 5.3 Asignación de lotes a socios

El administrador asigna uno o más lotes a cada socio.

Ejemplo:

Socio Juan Pérez:

- Lote A1 — 1.5 ha.
- Lote B2 — 2.0 ha.

Total asignado: 3.5 ha.

El sistema debe calcular automáticamente el total de hectáreas por socio.

---

### 5.4 Registro de gastos

El administrador registra un gasto de la sociedad.

Ejemplo:

- Contador enero: $100.000.
- Impuesto territorial: $1.000.000.
- Trámite municipal: $300.000.
- Abogado: $500.000.

Cada gasto debe tener:

- Nombre.
- Descripción.
- Monto total.
- Fecha.
- Categoría.
- Tipo de distribución.
- Fecha de vencimiento opcional.

---

### 5.5 Distribución de gastos

El sistema debe permitir tres tipos de distribución:

#### A. Partes iguales por socio

El gasto se divide entre todos los socios activos.

Ejemplo:

Gasto: $540.000  
Socios activos: 54

Cada socio paga:

$540.000 / 54 = $10.000

---

#### B. Proporcional por hectáreas

El gasto se divide según la proporción de hectáreas que tiene cada socio.

Ejemplo:

Gasto: $1.000.000  
Total hectáreas asignadas: 100 ha

Socio A tiene 10 ha.  
Socio A paga 10% del gasto:

$1.000.000 x 10% = $100.000

Fórmula:

monto_socio = gasto_total * (hectareas_socio / hectareas_totales)

---

#### C. Manual

El administrador define manualmente cuánto debe pagar cada socio.

Esto permite manejar casos especiales.

---

### 5.6 Generación de cobros

Cuando se registra y distribuye un gasto, el sistema genera cobros individuales para los socios.

Ejemplo:

Gasto: Contador enero — $540.000  
Distribución: partes iguales  
Socios: 54

Resultado:

- Socio 1: $10.000.
- Socio 2: $10.000.
- Socio 3: $10.000.
- etc.

Cada cobro debe quedar asociado al gasto que lo originó.

---

### 5.7 Registro de pagos

El administrador registra los pagos realizados por los socios.

Un pago puede:

- Cubrir un cobro completo.
- Cubrir varios cobros.
- Cubrir parte de un cobro.
- Ser mayor que la deuda.
- Generar saldo a favor.

Ejemplo:

Un socio debe:

- Contador: $20.000.
- Impuesto: $60.000.
- Trámite: $30.000.

Total deuda: $110.000.

El socio paga $100.000.

El sistema puede aplicar el pago así:

- Contador: $20.000 pagado.
- Impuesto: $60.000 pagado.
- Trámite: $20.000 abonado.

Resultado:

- Quedan $10.000 pendientes del trámite.

---

### 5.8 Saldo a favor

Si un socio paga más de lo que debe, el excedente queda como saldo a favor.

Ejemplo:

El socio debe $80.000.  
El socio paga $100.000.

Resultado:

- Deuda pagada.
- Saldo a favor: $20.000.

Ese saldo a favor puede utilizarse en futuros cobros.

---

### 5.9 Estado de cuenta del socio

El socio debe poder ver un resumen claro:

- Total cobrado.
- Total pagado.
- Total aplicado a cobros.
- Saldo pendiente.
- Saldo a favor.
- Detalle de cobros.
- Detalle de pagos.
- Lotes asignados.
- Hectáreas totales.

Ejemplo:

Socio Juan Pérez

Lotes:

- Lote A1 — 1.5 ha.
- Lote B2 — 2.0 ha.

Total hectáreas: 3.5 ha.

Resumen financiero:

- Total cobrado: $500.000.
- Total pagado: $400.000.
- Saldo pendiente: $100.000.
- Saldo a favor: $0.

---

## 6. Reglas importantes de negocio

### 6.1 No usar solo campos simples de “pagado” o “debe”

El sistema debe funcionar como una cuenta corriente.

Debe existir trazabilidad entre:

- Gastos.
- Cobros.
- Pagos.
- Aplicaciones de pagos.
- Saldos.

Esto permite responder preguntas como:

- ¿Por qué se cobró este monto?
- ¿Qué gasto originó esta deuda?
- ¿Qué pagos cubrieron este cobro?
- ¿Qué saldo tiene el socio?
- ¿Por qué un socio pagó más que otro?

---

### 6.2 Los gastos generan cobros

Un gasto no debe ser solo un registro informativo.

Cuando se registra un gasto, el sistema debe poder generar los cobros correspondientes a cada socio.

---

### 6.3 Los pagos no pertenecen necesariamente a un solo cobro

Un pago puede aplicarse a varios cobros.

Ejemplo:

Pago: $100.000

Aplicación:

- $20.000 a contador.
- $60.000 a impuesto.
- $20.000 a trámite.

---

### 6.4 Permitir pagos parciales

Un cobro puede quedar parcialmente pagado.

Estados recomendados de un cobro:

- Pendiente.
- Parcialmente pagado.
- Pagado.
- Cancelado.

---

### 6.5 Permitir saldo a favor

Si un pago excede la deuda pendiente, el monto restante queda como saldo a favor del socio.

Ese saldo puede aplicarse automáticamente o manualmente a futuros cobros.

---

### 6.6 Aplicación automática de pagos

Regla recomendada:

Cuando se registra un pago, el sistema puede aplicar automáticamente el pago a las deudas más antiguas primero.

Orden sugerido:

1. Cobros vencidos más antiguos.
2. Cobros pendientes más antiguos.
3. Cobros parcialmente pagados.
4. Saldo a favor si sobra dinero.

También debe existir opción manual para que el administrador decida cómo aplicar el pago.

---

### 6.7 Historial de cálculo

Cuando un gasto se reparte proporcionalmente por hectáreas, el cobro generado debe guardar los datos usados en el cálculo.

Esto evita que cambios futuros en lotes o hectáreas alteren cobros históricos.

Por ejemplo, si en enero un socio tenía 3 hectáreas y en marzo tiene 5, el cobro de enero debe mantenerse calculado con 3 hectáreas.

Cada cobro debería guardar información como:

- Tipo de cálculo.
- Hectáreas del socio al momento del cálculo.
- Total de hectáreas al momento del cálculo.
- Porcentaje aplicado.
- Monto calculado.

---

## 7. Alcance recomendado para MVP

El MVP debe incluir:

### Administración

- Login de administrador.
- Gestión de socios.
- Gestión de lotes.
- Asignación de lotes a socios.
- Cálculo de hectáreas por socio.
- Gestión de categorías de gastos.
- Registro de gastos.
- Distribución de gastos:
  - Por partes iguales.
  - Proporcional por hectáreas.
  - Manual.
- Generación automática de cobros.
- Registro de pagos.
- Aplicación de pagos a cobros.
- Pagos parciales.
- Saldo a favor.
- Reporte básico de deudas.
- Reporte básico de pagos.

### Portal del socio

- Login de socio.
- Ver lotes asignados.
- Ver hectáreas totales.
- Ver cobros.
- Ver pagos.
- Ver saldo pendiente.
- Ver saldo a favor.
- Ver estado de cuenta.

---

## 8. Funcionalidades fuera del MVP inicial

Estas funcionalidades pueden quedar para fases posteriores:

- Subida de comprobantes de pago.
- Exportación PDF del estado de cuenta.
- Exportación Excel.
- Notificaciones por email.
- Notificaciones por WhatsApp.
- Actas de reuniones.
- Votaciones internas.
- Intereses o multas por atraso.
- Gestión documental.
- Multi-sociedad o multi-comunidad.
- Auditoría avanzada.
- Dashboard financiero avanzado.
- Integración con pasarela de pago.
- Integración bancaria.
- Carga masiva de datos.
- Importación desde Excel.

---

## 9. Stack técnico recomendado

### Backend

Laravel.

Motivos:

- El proyecto tiene mucha lógica de negocio.
- Se necesita control sobre pagos, saldos y distribución de gastos.
- El equipo ya tiene experiencia con Laravel.
- Permite escalar el sistema más adelante.
- Es más adecuado que WordPress o no-code para este tipo de lógica.

### Panel administrativo

Filament.

Motivos:

- Permite construir CRUDs, formularios, tablas, filtros y paneles rápidamente.
- Reduce mucho el tiempo de desarrollo.
- Es ideal para socios, lotes, gastos, pagos y reportes.
- Permite crear recursos administrativos de forma ordenada.

### Base de datos

MySQL o MariaDB.

### Frontend

Para el MVP:

- Filament Panel para administración.
- Filament Panel separado o Blade simple para portal del socio.

No se recomienda crear un frontend SPA complejo inicialmente.

### Autenticación y permisos

Opciones:

- Autenticación nativa de Laravel/Filament.
- Spatie Laravel Permission para roles y permisos.

Roles iniciales:

- Admin.
- Socio.
- Contador, opcional.

---

## 10. Arquitectura inicial sugerida

Monolito Laravel.

Estructura conceptual:

- Admin Panel.
- Portal Socio.
- Servicios de negocio.
- Modelos de dominio.
- Reportes básicos.

Servicios recomendados:

- ExpenseDistributionService.
- ChargeGenerationService.
- PaymentApplicationService.
- PartnerBalanceService.
- PartnerStatementService.

---

## 11. Modelos principales sugeridos

### Partner

Representa a un socio.

Campos sugeridos:

- id
- name
- rut
- email
- phone
- status
- user_id, opcional si se separa User de Partner
- created_at
- updated_at

---

### Lot

Representa un lote.

Campos sugeridos:

- id
- code
- name
- hectares
- description
- status
- created_at
- updated_at

---

### PartnerLot

Relación entre socio y lote.

Campos sugeridos:

- id
- partner_id
- lot_id
- assigned_at
- status
- created_at
- updated_at

Aunque un lote normalmente pertenezca a un solo socio, se recomienda tabla intermedia para mantener flexibilidad e historial.

---

### ExpenseCategory

Categoría de gasto.

Campos sugeridos:

- id
- name
- description
- created_at
- updated_at

Ejemplos:

- Contador.
- Trámites.
- Impuestos.
- Legal.
- Administración.
- Mantención.

---

### Expense

Representa un gasto de la sociedad.

Campos sugeridos:

- id
- title
- description
- amount
- expense_date
- due_date
- category_id
- distribution_type
- status
- created_by
- created_at
- updated_at

distribution_type puede ser:

- equal_by_partner
- proportional_by_hectares
- manual

---

### PartnerCharge

Cobro individual generado para un socio.

Campos sugeridos:

- id
- partner_id
- expense_id
- amount
- paid_amount
- remaining_amount
- status
- due_date
- description
- calculation_type
- partner_hectares_at_moment
- total_hectares_at_moment
- percentage_at_moment
- created_at
- updated_at

Estados sugeridos:

- pending
- partial
- paid
- cancelled

---

### Payment

Pago realizado por un socio.

Campos sugeridos:

- id
- partner_id
- amount
- applied_amount
- unapplied_amount
- payment_date
- payment_method
- reference
- proof_file, opcional futuro
- notes
- status
- created_at
- updated_at

Estados sugeridos:

- pending_application
- partially_applied
- fully_applied
- cancelled

---

### PaymentAllocation

Aplicación de un pago a un cobro específico.

Campos sugeridos:

- id
- payment_id
- partner_charge_id
- amount
- created_at
- updated_at

Ejemplo:

Un pago de $100.000 puede generar tres aplicaciones:

- $20.000 a cobro A.
- $60.000 a cobro B.
- $20.000 a cobro C.

---

## 12. Cálculo de saldos

El saldo del socio no debería depender solamente de un campo manual.

Debe calcularse desde los cobros y pagos.

Variables:

- total_charges = suma de cobros generados.
- total_paid = suma de pagos realizados.
- total_applied = suma de pagos aplicados a cobros.
- available_credit = suma de saldos no aplicados.
- pending_balance = suma de montos pendientes de cobros.

Interpretación:

- Si pending_balance > 0, el socio debe dinero.
- Si available_credit > 0, el socio tiene saldo a favor.
- Puede existir saldo a favor incluso si no hay deuda pendiente.
- Si hay deuda y saldo a favor, el sistema podría aplicar el saldo automáticamente o permitir aplicación manual.

---

## 13. Reportes básicos para MVP

### Reporte de socios

Debe mostrar:

- Nombre del socio.
- Cantidad de lotes.
- Total de hectáreas.
- Total cobrado.
- Total pagado.
- Saldo pendiente.
- Saldo a favor.

### Reporte de deudas

Debe mostrar:

- Socios con deuda.
- Monto pendiente por socio.
- Cobros vencidos.
- Cobros parcialmente pagados.

### Reporte de pagos

Debe mostrar:

- Pagos por fecha.
- Socio.
- Monto.
- Método.
- Estado de aplicación.
- Saldo no aplicado.

### Reporte de gastos

Debe mostrar:

- Gastos registrados.
- Categoría.
- Monto.
- Tipo de distribución.
- Cantidad de cobros generados.
- Total cobrado.
- Total pendiente.

---

## 14. Modelo comercial de referencia

La primera sociedad objetivo tiene 54 socios.

Se evaluó cobrar $1.000 CLP por socio mensual.

Cálculo:

54 socios x $1.000 = $54.000 CLP mensuales.

En 24 meses:

$54.000 x 24 = $1.296.000 CLP.

Conclusión:

Cobrar solo $1.000 CLP por socio mensual no recupera bien el desarrollo, soporte, hosting ni mantención.

Modelo recomendado:

- Setup inicial preferencial: $2.000.000 CLP.
- Mensualidad: $1.000 CLP por socio.
- Mínimo mensual: $80.000 CLP.
- Compromiso mínimo: 24 meses.

Alternativa simple:

- Pago inicial: $2.000.000 CLP.
- Mensualidad fija: $80.000 CLP.
- Duración mínima: 24 meses.

Condición recomendada:

El código debe quedar para el desarrollador/empresa creadora, para poder reutilizarlo y venderlo a otras sociedades o loteos.

---

## 15. Estimación de desarrollo

Con un equipo de:

- Junior: 30 horas semanales.
- Senior: 10 horas semanales.

Estimación:

- MVP básico: 6 a 8 semanas.
- MVP sólido: 8 a 10 semanas.
- Versión más completa: 11 a 14 semanas.

Horas recomendadas para cotización:

- MVP básico: 300 HH aproximadas.
- MVP sólido: 380 a 420 HH.
- Número recomendado de referencia: 400 HH.

---

## 16. Distribución recomendada del trabajo

### Senior

Debe enfocarse en:

- Arquitectura.
- Modelo de datos.
- Reglas de distribución de gastos.
- Lógica de pagos.
- Saldos a favor.
- Revisión de código.
- Seguridad y permisos.
- QA crítico.
- Despliegue.

### Junior

Puede enfocarse en:

- CRUDs.
- Pantallas administrativas.
- Formularios.
- Tablas.
- Filtros.
- Portal del socio.
- Vistas de estado de cuenta.
- Pruebas manuales.
- Ajustes visuales.

---

## 17. Roadmap sugerido

### Semana 1

- Definición final del flujo.
- Modelo de datos.
- Setup Laravel.
- Setup Filament.
- Login y roles.

### Semana 2

- Gestión de socios.
- Gestión de lotes.
- Asignación de lotes.

### Semana 3

- Cálculo de hectáreas por socio.
- Categorías de gastos.
- Gestión de gastos.

### Semana 4

- Distribución de gastos por socio.
- Distribución proporcional por hectáreas.
- Generación automática de cobros.

### Semana 5

- Registro de pagos.
- Aplicación de pagos a varios cobros.
- Pagos parciales.

### Semana 6

- Saldo a favor.
- Uso de saldo a favor en futuros cobros.
- Estado de cuenta interno.

### Semana 7

- Portal del socio.
- Vista de lotes.
- Vista de hectáreas.
- Vista de pagos y deudas.

### Semana 8

- Reportes básicos.
- Filtros.
- Correcciones.
- Pruebas completas.

### Semanas 9 a 10

- Ajustes finales.
- Carga inicial de datos.
- Mejoras visuales.
- Despliegue.
- Capacitación básica.

---

## 18. Principios de desarrollo

### 18.1 Mantener lógica de negocio en servicios

No poner lógica compleja directamente en controllers, resources o modelos.

Usar servicios como:

- ExpenseDistributionService.
- PaymentApplicationService.
- BalanceCalculatorService.

---

### 18.2 Evitar recalcular historia de forma destructiva

Los cobros generados deben mantener los datos usados en el cálculo original.

Cambios futuros en lotes o hectáreas no deben alterar cobros pasados.

---

### 18.3 Mantener trazabilidad

Toda operación financiera relevante debe poder rastrearse.

Ejemplo:

- Qué gasto originó el cobro.
- Qué pago cubrió el cobro.
- Qué parte del pago quedó como saldo a favor.

---

### 18.4 Mantener el MVP simple

No incluir desde el inicio:

- Pasarela de pago.
- Notificaciones complejas.
- Multi-tenant avanzado.
- App móvil.
- Frontend SPA.
- Integraciones bancarias.

Primero construir el núcleo:

- Socios.
- Lotes.
- Hectáreas.
- Gastos.
- Cobros.
- Pagos.
- Saldos.
- Estado de cuenta.

---

## 19. Nombres internos sugeridos

Nombre comercial:

- Loteo Fácil.

Nombre de repo sugerido:

- loteo-facil
- loteo-facil-app
- loteo-facil-admin

Nombre de base de datos local sugerido:

- loteo_facil

---

## 20. Resumen ejecutivo

Loteo Fácil debe ser una plataforma simple y transparente para administrar sociedades con loteos.

El sistema debe permitir que la administración registre socios, lotes, hectáreas y gastos; que los gastos se distribuyan automáticamente según reglas claras; que los pagos puedan cubrir uno o varios conceptos; y que cada socio pueda revisar su estado de cuenta, deuda, pagos y saldo a favor.

La recomendación técnica es construirlo como un monolito en Laravel con Filament y MySQL, priorizando velocidad de desarrollo y control sobre reglas de negocio.

El MVP debe centrarse en:

- Administración de socios.
- Administración de lotes.
- Asignación de hectáreas.
- Registro y distribución de gastos.
- Generación de cobros.
- Registro y aplicación de pagos.
- Saldo a favor.
- Estado de cuenta del socio.
- Reportes básicos.

El sistema debe ser diseñado con la posibilidad de reutilizarlo posteriormente para otras sociedades o comunidades de loteos.
