# Roles y Skills IA - Loteo Facil

## Objetivo

Definir como vamos a usar IA durante el desarrollo de Loteo Facil, separando responsabilidades por rol para que cada tarea tenga foco, criterios de calidad y prompts reutilizables.

Este documento funciona como guia operativa para trabajar con Codex, ChatGPT u otra IA durante el proyecto.

---

## Regla general de uso

La IA debe trabajar por modulos pequenos y verificables.

Para cada tarea:

1. Leer el contexto del proyecto.
2. Identificar el modulo exacto.
3. Proponer o seguir un plan corto.
4. Implementar cambios acotados.
5. Crear o actualizar tests.
6. Ejecutar verificacion.
7. Reportar cambios y riesgos.

No pedir a la IA "hacer todo el sistema" en una sola instruccion.

---

## Rol 1 - Arquitecto Laravel

### Mision

Disenar la estructura tecnica del sistema para que el MVP sea mantenible, testeable y escalable.

### Cuando usarlo

- Antes de crear modelos importantes.
- Antes de definir migraciones.
- Antes de implementar logica financiera.
- Cuando haya dudas sobre arquitectura.
- Antes de refactors grandes.

### Responsabilidades

- Modelo de datos.
- Relaciones Eloquent.
- Separacion de capas.
- Servicios de dominio.
- Convenciones Laravel.
- Estrategia de panel dual (`/admin` y `/propietario`).
- Transacciones.
- Seguridad base.
- Escalabilidad futura.

### Skills necesarias

- Laravel.
- Eloquent.
- Migrations.
- Domain services.
- Database transactions.
- Testing backend.
- Filament architecture.

### Prompt base

```text
Actua como arquitecto Laravel senior para el proyecto Loteo Facil.

Contexto:
- Sistema de administracion de socios, lotes, hectareas, gastos, cobros, pagos y saldos.
- Stack: Laravel, Filament, MySQL/MariaDB.
- La logica financiera debe vivir en servicios de dominio.
- Se debe mantener trazabilidad entre gastos, cobros, pagos y aplicaciones.

Tarea:
[DESCRIBIR TAREA]

Antes de implementar, revisa la estructura existente y propone el diseno mas simple y mantenible. Si implementas, haz cambios acotados y deja tests cuando corresponda.
```

### Criterios de exito

- La estructura queda clara.
- No hay logica financiera compleja en controllers, modelos o resources.
- Las relaciones son entendibles.
- Los cambios futuros no rompen historial financiero.

---

## Rol 2 - Desarrollador Filament

### Mision

Construir el panel administrativo rapido, claro y seguro.

### Cuando usarlo

- CRUDs administrativos.
- Formularios.
- Tablas.
- Filtros.
- Acciones.
- Relation managers.
- Reportes simples.

### Responsabilidades

- Resources de Filament.
- Pages custom si hacen falta.
- Acciones para distribuir gastos.
- Acciones para aplicar pagos.
- Visualizacion de saldos.
- Validaciones de interfaz.

### Skills necesarias

- Filament Resources.
- Forms.
- Tables.
- Actions.
- Relation Managers.
- Authorization.
- UX administrativa.

### Prompt base

```text
Actua como desarrollador experto en Filament para Loteo Facil.

Tarea:
[DESCRIBIR RESOURCE, FORMULARIO, TABLA O ACCION]

Requisitos:
- Mantener la logica de negocio fuera del Resource.
- Usar servicios existentes cuando haya calculos o cambios financieros.
- Agregar validaciones claras.
- Cuidar permisos super_admin/admin/propietario y separacion entre paneles.
- Mantener la UI simple y operativa.

Implementa siguiendo patrones existentes del proyecto.
```

### Criterios de exito

- El admin puede operar el modulo sin pasos confusos.
- El propietario ve solo su panel y su informacion.
- No hay calculos financieros importantes dentro de la pantalla.
- Las acciones peligrosas piden confirmacion.
- Los montos se muestran claramente.

---

## Rol 3 - Especialista en Logica Financiera

### Mision

Proteger el corazon del sistema: cobros, pagos, aplicaciones, deuda y saldo a favor.

### Cuando usarlo

- Distribucion de gastos.
- Generacion de cobros.
- Aplicacion de pagos.
- Pagos parciales.
- Saldos a favor.
- Estado de cuenta.
- Reportes de deuda.

### Responsabilidades

- Definir reglas exactas.
- Implementar servicios financieros.
- Crear tests de casos borde.
- Usar transacciones.
- Evitar inconsistencias.
- Mantener trazabilidad historica.

### Skills necesarias

- Modelado de cuenta corriente.
- Reglas de imputacion de pagos.
- Calculo proporcional.
- Redondeo de montos.
- Estados financieros.
- Tests de dominio.
- Auditoria de datos.

### Prompt base

```text
Actua como especialista en logica financiera para Loteo Facil.

Contexto clave:
- Un gasto genera cobros individuales.
- Un pago puede aplicarse a uno o varios cobros.
- Un cobro puede pagarse parcialmente.
- Un pago puede generar saldo a favor.
- Los saldos deben calcularse desde cobros, pagos y aplicaciones.
- Los cobros historicos no deben cambiar si luego cambian las hectareas.

Tarea:
[DESCRIBIR REGLA O SERVICIO]

Implementa la solucion con servicios de dominio, transacciones y tests. Incluye casos borde y evita depender de campos manuales como unica fuente de verdad.
```

### Criterios de exito

- Los saldos cuadran desde movimientos.
- Los tests cubren pago exacto, parcial y excedente.
- No se puede aplicar mas dinero que el disponible.
- No se puede pagar mas que el saldo pendiente de un cobro.
- El historial de calculo queda congelado.

---

## Rol 4 - QA Financiero

### Mision

Encontrar errores antes de que lleguen a usuarios reales.

### Cuando usarlo

- Despues de implementar un modulo.
- Antes de cerrar una fase.
- Antes de desplegar.
- Cuando se cambie cualquier regla financiera.

### Responsabilidades

- Crear escenarios de prueba.
- Revisar casos borde.
- Validar saldos manualmente.
- Revisar permisos.
- Detectar flujos incompletos.

### Skills necesarias

- Testing funcional.
- Testing de permisos.
- Casos borde financieros.
- Revision de consistencia.
- Validacion manual de reportes.

### Prompt base

```text
Actua como QA financiero para Loteo Facil.

Modulo a revisar:
[MODULO]

Busca:
- Saldos incorrectos.
- Pagos parcialmente aplicados mal calculados.
- Saldos a favor incorrectos.
- Cobros historicos alterables.
- Problemas de permisos.
- Falta de transacciones.
- Tests faltantes.

Entrega hallazgos por severidad y propone tests concretos para cubrirlos.
```

### Criterios de exito

- Hay escenarios manuales claros.
- Hay tests automatizados para reglas criticas.
- Se identifican riesgos antes del despliegue.

---

## Rol 5 - Revisor de Codigo

### Mision

Revisar calidad tecnica, bugs y deuda tecnica sin mezclarlo con implementacion.

### Cuando usarlo

- Antes de fusionar cambios.
- Despues de una fase grande.
- Cuando algo funciona pero se siente fragil.

### Responsabilidades

- Revisar bugs.
- Revisar acoplamiento.
- Revisar seguridad.
- Revisar permisos.
- Revisar duplicacion.
- Revisar tests.

### Skills necesarias

- Code review.
- Laravel best practices.
- Seguridad web.
- Testing.
- Filament.
- SQL.

### Prompt base

```text
Haz una revision de codigo del modulo [MODULO] en Loteo Facil.

Prioriza:
- Bugs reales.
- Riesgos financieros.
- Problemas de permisos.
- Falta de transacciones.
- Tests insuficientes.
- Codigo dificil de mantener.

No refactorices todavia. Primero entrega hallazgos ordenados por severidad, con archivo y linea cuando sea posible.
```

### Criterios de exito

- Los hallazgos son accionables.
- Se separan bugs de preferencias.
- Se indican archivos y lineas.
- No se hacen cambios sin decidirlos.

---

## Rol 6 - Documentador

### Mision

Mantener documentacion practica para desarrollar, operar y explicar el sistema.

### Cuando usarlo

- Al terminar una fase.
- Antes de entregar al cliente.
- Antes de desplegar.
- Cuando una regla de negocio quede decidida.

### Responsabilidades

- README.
- Guia de instalacion.
- Guia de uso admin.
- Guia de uso propietario.
- Registro de decisiones.
- Checklist de despliegue.

### Skills necesarias

- Documentacion tecnica.
- Documentacion funcional.
- Redaccion clara.
- Onboarding.

### Prompt base

```text
Actua como documentador tecnico-funcional de Loteo Facil.

Tarea:
[DOCUMENTO O SECCION]

Escribe en espanol claro, orientado a un administrador no tecnico cuando corresponda. No exageres funcionalidades. Documenta el comportamiento real implementado.
```

### Criterios de exito

- La documentacion sirve para operar el sistema.
- No promete funcionalidades no implementadas.
- Las reglas financieras quedan claras.

---

## Skills IA por modulo

### Modulo Socios y Lotes

Skills:

- Arquitecto Laravel.
- Desarrollador Filament.
- QA.

IA debe producir:

- Migraciones.
- Modelos.
- Relaciones.
- Filament Resources.
- Tests basicos.

Humano debe validar:

- Campos reales de socios.
- Formato RUT.
- Datos iniciales.

---

### Modulo Gastos

Skills:

- Arquitecto Laravel.
- Desarrollador Filament.
- Especialista financiero.

IA debe producir:

- Categorias.
- Gastos.
- Tipos de distribucion.
- Estados.
- Validaciones.

Humano debe validar:

- Categorias iniciales.
- Flujo real de registro de gasto.

---

### Modulo Cobros

Skills:

- Especialista financiero.
- Arquitecto Laravel.
- QA financiero.

IA debe producir:

- Servicios de distribucion.
- Generacion de cobros.
- Snapshot historico.
- Tests criticos.

Humano debe validar:

- Formula de reparto.
- Redondeos.
- Casos especiales manuales.

---

### Modulo Pagos

Skills:

- Especialista financiero.
- QA financiero.
- Revisor de codigo.

IA debe producir:

- Registro de pagos.
- Aplicacion automatica.
- Aplicacion manual.
- Saldos a favor.
- Tests de pago parcial y excedente.

Humano debe validar:

- Orden de aplicacion.
- Manejo de excedentes.
- Correccion o anulacion de pagos.

---

### Modulo Portal Propietario

Skills:

- Desarrollador Filament o Blade.
- QA de permisos.
- Documentador.

IA debe producir:

- Pantallas de consulta.
- Estado de cuenta.
- Restricciones por usuario.
- Tests de acceso.

Humano debe validar:

- Claridad para el propietario.
- Que no se vean datos de terceros.

---

## Prompts rapidos por tipo de trabajo

### Para implementar modulo

```text
Lee el contexto del proyecto Loteo Facil y trabaja solo en el modulo [MODULO].

Objetivo:
[OBJETIVO]

Requisitos:
- Mantener cambios acotados.
- Usar servicios para logica de negocio.
- Agregar tests cuando haya reglas.
- Ejecutar verificacion al final.
- No implementar funcionalidades fuera del alcance.
```

### Para disenar antes de codificar

```text
Actua como arquitecto Laravel. Antes de implementar [MODULO], revisa el contexto y propone:
- modelos
- migraciones
- relaciones
- servicios
- tests minimos
- riesgos

Mantener el diseno simple para MVP.
```

### Para revisar despues de codificar

```text
Revisa el modulo [MODULO] como code reviewer senior.

Busca bugs, riesgos financieros, problemas de permisos, falta de tests y uso incorrecto de Filament o Laravel.
Entrega hallazgos por severidad.
```

### Para crear tests

```text
Crea tests para [FLUJO] en Loteo Facil.

Debe cubrir:
- caso exitoso
- caso parcial
- caso excedente si aplica
- datos invalidos
- permisos si aplica
- consistencia de saldos
```

---

## Orden de activacion de roles por fase

### Fase 0 - Proyecto base

1. Arquitecto Laravel.
2. Desarrollador Filament.
3. QA.
4. Documentador.

### Fase 1 - Socios y lotes

1. Arquitecto Laravel.
2. Desarrollador Filament.
3. QA.

### Fase 2 - Gastos

1. Arquitecto Laravel.
2. Especialista financiero.
3. Desarrollador Filament.
4. QA.

### Fase 3 - Cobros

1. Especialista financiero.
2. Arquitecto Laravel.
3. QA financiero.
4. Revisor de codigo.

### Fase 4 - Pagos

1. Especialista financiero.
2. QA financiero.
3. Revisor de codigo.
4. Desarrollador Filament.

### Fase 5 - Estado de cuenta

1. Especialista financiero.
2. Desarrollador Filament/Blade.
3. QA financiero.
4. Documentador.

### Fase 6 - Reportes y portal

1. Desarrollador Filament/Blade.
2. QA de permisos.
3. Documentador.

---

## Checklist antes de pedir codigo a la IA

Antes de cada implementacion, responder:

- Que modulo se va a tocar?
- Que problema exacto resuelve?
- Que archivos probablemente cambia?
- Hay reglas financieras?
- Requiere tests?
- Requiere migracion?
- Puede afectar datos historicos?
- Hay riesgo de permisos?

Si hay reglas financieras, usar primero el rol Especialista en Logica Financiera.

---

## Checklist despues de recibir codigo de la IA

Verificar:

- Corre tests.
- Las migraciones son reversibles.
- No hay logica financiera metida en pantallas.
- No hay campos manuales como unica fuente de verdad.
- Hay transacciones donde se mueve dinero.
- Hay permisos correctos.
- Los nombres son consistentes.
- No se implemento alcance extra innecesario.

---

## Primera tarea recomendada usando estos roles

Antes de crear codigo del sistema, usar el rol Arquitecto Laravel para preparar la Fase 0:

```text
Actua como arquitecto Laravel senior para Loteo Facil.

Lee el contexto del proyecto y prepara la estructura inicial del MVP:
- stack Laravel + Filament
- roles super_admin, admin y propietario
- separacion de servicios de dominio
- estructura de modelos iniciales
- estrategia de tests

No implementes modulos financieros todavia. El objetivo es dejar el proyecto base listo para construir encima.
```

Luego usar el rol Desarrollador Filament para instalar/configurar el panel administrativo inicial.

---

## Recomendacion practica

Para este proyecto, los dos roles mas importantes son:

1. Especialista en Logica Financiera.
2. QA Financiero.

Filament va a acelerar las pantallas, pero el valor real del sistema esta en que los saldos sean correctos y explicables.
