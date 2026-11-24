# Plan técnico: Sistema de Gestión Documental — Loteo Fácil

(Este archivo es el plan solicitado por el usuario; `DOCUMENT_MANAGEMENT_PROGRESS.md` en la raíz será el registro de avance.)

## 1. Objetivo

Implementar un **sistema centralizado, modular y reutilizable de gestión documental** en Loteo Fácil, utilizando la arquitectura y convenciones existentes del proyecto Laravel.

Debe permitir:
- Adjuntar documentos a cualquier entidad del sistema.
- Gestionar documentos comunitarios, administrativos y privados.
- Tipos documentales configurables por comunidad.
- Control de acceso por roles, propietarios y relaciones reales con los registros.
- Componente reutilizable de carga y gestión de documentos.
- Centro documental administrativo y portal de propietario ("Mis documentos").
- Integración con nuevos módulos sin duplicar lógica.

Priorizar simplicidad, seguridad, mantenibilidad y reutilización. No implementar un sistema documental independiente por módulo.

## 2. Análisis obligatorio del proyecto

Antes de modificar código:
1. Inspeccionar la estructura actual.
2. Identificar versión de Laravel, autenticación y autorización.
3. Revisar modelos, migraciones, Policies, servicios y componentes de interfaz.
4. Identificar cómo se gestionan comunidades, propietarios, accionistas, lotes, acciones, recaudaciones, gastos y egresos.
5. Identificar el framework frontend existente y reutilizarlo (Filament v5 + Livewire v4).
6. Revisar almacenamiento, configuración Docker/Coolify y backups.
7. Identificar convenciones de pruebas, traducciones y validaciones.
8. Comprobar si ya existe alguna solución para adjuntar archivos.

No asumir nombres de modelos, tablas, rutas o componentes. Adaptar el diseño a la arquitectura real. Evitar dependencias adicionales cuando Laravel ya lo ofrezca.

## 3. Arquitectura

### A. Documentos (`documents`)
```text
id, community_id, document_type_id, original_name, stored_name, disk,
file_path, mime_type, file_size, visibility, description (nullable),
document_date (nullable), uploaded_by, created_at, updated_at, deleted_at
```
- No almacenar binarios en MySQL; nombres físicos únicos (uuid).
- Conservar nombre original. SoftDeletes con reglas de retención.
- Cada documento pertenece inequívocamente a una comunidad.
- Índices para búsquedas frecuentes. Transacciones cuando corresponda.

### B. Tipos de documentos (`document_types`)
```text
id, community_id, name, description, default_visibility, is_active, settings (JSON), created_at, updated_at
```
Asociación módulo–tipo:
```text
document_type_modules: document_type_id, module_key
```
El administrador puede crear/editar/activar-desactivar tipos, asociar módulos, visibilidad predeterminada, formatos admitidos, cantidad máxima de archivos, obligatoriedad cuando el flujo lo permita. Tipos independientes por comunidad. No permitir eliminar físicamente un tipo con documentos asociados.

### C. Relación polimórfica (`documentables`)
```text
id, document_id, documentable_type, documentable_id, created_at
```
Asociable a: comunidades, propietarios, accionistas, adquisiciones/transferencias de acciones, lotes, gastos, egresos, recaudaciones y módulos futuros. Usar morphMap explícito. Un documento puede vincularse a múltiples entidades con justificación funcional y de permisos; una relación adicional nunca amplía el acceso. Validar pertenencia de las entidades a la misma comunidad.

## 4. Almacenamiento

Laravel Storage con disco privado configurable:
```text
storage/app/private/communities/{community_id}/documents/{uuid}.{extension}
```
- Nada privado en `public`; sin URLs directas ni rutas físicas expuestas.
- Descarga por endpoints autorizados; vista previa segura de PDF/imágenes.
- Validar tamaño y MIME real; evitar ejecución de subidos.
- Manejo de errores; limpieza segura de huérfanos.
- Separar lógica de almacenamiento de la de negocio. Preparado para S3 por configuración.
- Verificar persistencia del volumen en Docker/Coolify y documentar requisitos de backup.

## 5. Permisos

Autorización central con Policies y Services. Visibilidades: `admin`, `owner`, `community`, `custom` (usuarios/roles explícitos).

Acciones: listar, ver metadatos, visualizar, descargar, subir, modificar metadatos, reemplazar archivo, eliminar.

Reglas obligatorias:
1. Validar comunidad activa.
2. Validar autenticación.
3. Validar rol/permisos.
4. Validar relación real del propietario con la entidad.
5. Aplicar restricciones del módulo de origen.
6. No confiar en `owner_id`, `community_id` ni `visibility` del cliente.
7. Un usuario no puede cambiar visibilidad para obtener privilegios.
8. Sin accesos cruzados entre comunidades.
9. Sin exposición por enumeración de IDs.

La autorización se resuelve por relaciones reales del dominio (ej.: documento `owner` de una adquisición de acciones solo visible por administración autorizada y el usuario #35 relacionado). No asumir que quien subió es el propietario. Considerar transferencias de acciones: documentos históricos privados no deben ser accesibles automáticamente por el nuevo titular; política explícita. Para `custom`, estructura de permisos explícitos compatible con el sistema de roles actual.

## 6. Configuración administrativa de tipos

Sección **Configuración → Tipos de documentos**: tabla, buscador, filtros por módulo y estado, crear/editar, activar/desactivar, módulos permitidos, visibilidad predeterminada, formatos admitidos, obligatoriedad, validaciones claras.

Seeder idempotente de tipos iniciales por comunidad:
- **Acciones**: Documento CVE; Registro SII; Compraventa o cesión de acciones; Certificado de acciones; Otro documento de acciones.
- **Finanzas**: Comprobante de transferencia; Factura; Boleta; Comprobante de depósito; Recibo; Otro comprobante financiero.
- **Comunidad**: Libro de accionistas; Escrituras; Planos y topografía; Contribuciones; Actas; Reglamentos; Contratos; Otros documentos comunitarios.
- **Lotes**: Plano individual; Escritura; Certificado; Contrato; Otro documento de lote.

Tipos modificables/desactivables. Sin enums rígidos. Catálogo extensible a nuevos módulos.

## 7. Componente reutilizable

Componente frontend (Filament/Livewire) `DocumentManager`, usable desde cualquier módulo. Funcionalidades: listar, subir uno/varios, elegir tipo, mostrar nombre/formato/tamaño/fecha, estado de carga, previsualizar PDF/imágenes, descargar autorizados, reemplazar con permiso, eliminar/desvincular, mostrar errores, deshabilitar acciones no autorizadas, responsive. Debe deducir comunidad activa, entidad, tipos disponibles para el módulo, permisos efectivos, visibilidad y restricciones. No duplicar lógica de subida/descarga/permisos entre módulos; servicio backend reutilizable.

## 8. Integraciones

- **Acciones/adquisiciones** (ShareMovement / adquisición): CVE, Registro SII, contrato de compraventa, otros. Documentos privados vinculados a la operación y su propietario autorizado según dominio.
- **Gastos** (Expense): sección "Documentos y comprobantes", adjuntos opcionales (facturas, boletas).
- **Egresos**: comprobantes opcionales (transferencias, recibos, otros respaldos).
- **Recaudaciones** (Collection): comprobante de transferencia, depósito, otros. Comprobantes privados consultables por propietario relacionado y administración según permisos.
- **Documentos comunitarios**: administración publica escrituras, planos, libro de accionistas, reglamentos, actas, contribuciones, otros; propietarios ven biblioteca comunitaria con solo lo autorizado.
- **Lotes** (Lote) cuando la estructura lo permita.

No cambiar reglas financieras ni estados de operaciones existentes.

## 9. Centro documental

**Administración → Documentos**: listado paginado, búsqueda por nombre, filtros por tipo/módulo/visibilidad/propietario relacionado/fechas/entidad asociada, metadatos, vista previa, descarga, acceso al registro de origen, subida de documentos comunitarios, edición según permisos. Columnas: Nombre, Tipo, Módulo, Registro relacionado, Propietario relacionado, Visibilidad, Fecha, Acciones. Evitar N+1 (eager loading + paginación); no cargar todo en memoria.

**Portal del propietario — Mis documentos**: Documentos comunitarios; Documentos de mis acciones; Documentos de mis lotes; Comprobantes financieros autorizados. Solo documentos con acceso real.

## 10. Organización del código

Adaptar nombres a convenciones existentes. Conceptual: `DocumentService`, `DocumentStorageService`, `DocumentAuthorizationService`, `DocumentTypeService`; `DocumentPolicy`, `DocumentTypePolicy`; modelos `Document`, `DocumentType`, `Documentable`; trait `HasDocuments`; componentes UI (`DocumentManager`, etc.). Form Requests para validaciones. Controladores coordinan, no contienen lógica. Sin repositorios ni abstracciones innecesarias.

## 11. Seguridad e integridad

1. Validación de formatos y tamaño máximo. 2. Almacenamiento privado. 3. Autorización en cada operación. 4. Aislamiento entre comunidades. 5. Protección path traversal. 6. Prevención de ejecutables maliciosos. 7. Control de eliminación/sustitución. 8. Manejo de errores y operaciones parciales. 9. Integridad de referencias. 10. Registro de quién subió/modificó.

MySQL no garantiza la existencia de entidades referenciadas en polimórficas: validar y gestionar limpieza. Si una operación falla tras subir el archivo, evitar huérfanos. Al eliminar una entidad relacionada, definir retención (contable/legal/histórica). No eliminar automáticamente documentos financieros importantes por eliminación de una relación.

## 12. Pruebas

PHPUnit (el proyecto usa PHPUnit). `Storage::fake()` cuando corresponda. Cubrir: creación/modificación de tipos; independencia por comunidad; asociación polimórfica; subida/descarga; archivos inválidos; límites de tamaño; usuario sin autorización; propietario autorizado; propietario no relacionado; administración autorizada; accesos cruzados entre comunidades; cambio de titularidad de acciones; documentos comunitarios; documentos administrativos; desactivación de tipos; prevención de escalamiento de permisos; conservación de históricos. Verificar autorizaciones reales en endpoints, no solo visibilidad de botones.

## 13. Etapas

1. **Fundamentos**: análisis, migraciones, modelos, relaciones, almacenamiento privado, servicios, policies, pruebas fundamentales.
2. **Configuración**: CRUD tipos, asociación módulos, configuración por comunidad, seeder, pruebas.
3. **Componente reutilizable**: carga, listado, vista previa, descargas, reemplazo/eliminación, errores, pruebas.
4. **Integraciones**: acciones, gastos, egresos, recaudaciones, comunidad, lotes.
5. **Centro documental**: biblioteca administrativa, filtros, búsqueda, vista propietario, navegación a origen.
6. **Validación final**: pruebas, seguridad, permisos, consultas, responsive, documentación de almacenamiento y backups.

## 14. Progreso

`DOCUMENT_MANAGEMENT_PROGRESS.md` en la raíz, con: resumen, análisis, arquitectura, decisiones, checklist por etapas, archivos creados/modificados, pruebas ejecutadas, problemas, pendientes. Actualizar a medida que avance.

## 15. Criterios de aceptación

- Administración configura tipos sin tocar código.
- Un tipo se asocia a varios módulos; cada comunidad con su propia configuración.
- Archivos almacenados privadamente.
- Documentos vinculables a entidades existentes con mecanismo reutilizable.
- Permisos respetan comunidad, rol y propietario real.
- Adquisición de acciones con documentos privados.
- Gastos, egresos y recaudaciones con comprobantes opcionales.
- Administradores consultan todo lo autorizado; propietarios solo lo suyo.
- El componente se incorpora a un nuevo módulo sin duplicar lógica.
- Pruebas esenciales aprobadas y archivo de progreso actualizado.

## 16. Instrucciones finales

Respetar la arquitectura existente; priorizar funcionalidades completas y probadas; no reconstruir módulos existentes ni cambios incompatibles; sin OCR, IA, firmas digitales ni versionado complejo. Si una decisión de arquitectura impide un requisito, documentar el problema y adoptar la solución más simple y segura.
