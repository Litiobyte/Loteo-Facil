# Gestión documental — progreso

## Resumen

Implementados y validados los seis bloques del plan: fundamentos, catálogo administrativo, componente reutilizable, integraciones, centro documental y validación final. Las decisiones históricas y pendientes quedan registradas en este archivo.

## Análisis y decisiones pendientes

- El dominio actual usa `CommunitySetting::current()`, que obtiene el registro con ID 1; no se encontró un modelo `Community` ni referencias a `community_id` en `app/`. No existe, por tanto, un selector de comunidad activa ni una relación comunitaria comprobable en las entidades actuales.
- `User` utiliza roles Spatie `super_admin`, `admin` y `propietario`; un usuario puede estar asociado a un propietario principal o secundario.
- `ShareHolding` identifica al propietario mediante `propietario_id` y conserva la titularidad original; `ShareMovement` mantiene historia inmutable. `Collection` tiene `propietario_id`.
- Decisión del usuario: instalación de comunidad única, identificada internamente por `1`; no crear modelo `Community`. `community_id` queda como preparación de esquema y no implica aislamiento multicomunidad real.
- Se registra un morph map explícito usando los nombres completos de clase como claves: los importadores históricos existentes comparan `getMorphClass()` con los valores guardados. Cambiar esas claves a alias cortos alteraría su comportamiento.
- Los tipos iniciales se crean con visibilidad administrativa por defecto; la visibilidad personalizada no está implementada y debe permanecer denegada hasta diseñar permisos explícitos.
- `owner` requiere que todas las vinculaciones existentes pertenezcan al propietario; una relación adicional no amplía acceso. Solo se reconocen explícitamente propietario, participación, recaudación y cobro. Movimientos y lotes quedan denegados para propietarios hasta definir acceso histórico seguro. `community` requiere vínculo exclusivo a `CommunitySetting` ID 1; `custom` se deniega a propietarios.
- Las descargas no autenticadas responden 401 explícitamente: el middleware genérico de autenticación intenta resolver una ruta `login` inexistente en esta instalación con paneles Filament.
- El almacenamiento comprueba contenido real mediante `finfo`, extensión y lista de formatos segura; limita por defecto a PDF, JPEG, PNG y WebP. No existe aún operación de subida expuesta a usuarios.
- `DocumentService` permite al administrador subir a entidades y módulos explícitamente admitidos, reemplazar y eliminar lógicamente. Para evitar huérfanos por rollback externo, rechaza las subidas/reemplazos dentro de una transacción preexistente; limpia el archivo nuevo si falla su transacción. Una eliminación lógica conserva el archivo.
- En reemplazo se borra el archivo anterior después del commit; si falla ese borrado se reporta la excepción y puede quedar un archivo huérfano que requiere limpieza operativa.
- El seeder conserva modificaciones existentes y agrega los módulos iniciales faltantes; no se ejecuta automáticamente.
- El catálogo administrativo usa un recurso Filament simple con módulos mediante relación `Repeater`; los formatos seleccionables coinciden con la lista segura del almacenamiento. La configuración se guarda en `settings`.
- El gestor es un componente Livewire de clase, incrustable desde Blade/Filament con `@livewire('document-manager', ['entity' => $record, 'moduleKey' => 'acciones', 'multiple' => true, 'required' => false])`. La entidad, el módulo y las opciones se bloquean frente a cambios del cliente y se reautorizan en cada operación. No se integra en recursos existentes en esta etapa.
- `required` muestra la exigencia y expone `validateRequired()`: el flujo anfitrión debe invocarlo y bloquear su propia finalización si retorna `false`; no existe validación automática de un formulario anfitrión. Subidas múltiples se procesan individualmente: si falla una posterior, las anteriores permanecen guardadas.
- La desvinculación conserva los documentos compartidos y elimina lógicamente el último; para documentos privados compartidos solo permite retirar una relación si todas corresponden al mismo propietario, evitando ampliación de acceso. Las relaciones comunitarias múltiples se deniegan por precaución.
- Egresos corresponden a `ExpenseFundingPayment`, no al gasto `Expense`. Ambos y `Collection` usan el módulo existente `finanzas`; sus adjuntos son opcionales y se gestionan en las fichas sin modificar el registro financiero. Las recaudaciones también muestran comprobantes autorizados en el portal.
- En `ShareMovement`, el único propietario privado autorizado es el de `share_holding_id` (participación histórica de origen); `counterparty_holding_id` no concede acceso. La transferencia no concede al nuevo titular acceso a documentos anteriores. El historial de acciones incrusta el gestor por movimiento.
- Lotes: administración puede gestionar documentos desde la ficha; se mantiene denegado el acceso privado de propietarios por lote hasta definir una política histórica que no transfiera contratos automáticamente.
- La configuración comunitaria y el panel de propietario muestran documentos de comunidad; el propietario solo ve tipos efectivamente publicados con visibilidad `community` y vínculo exclusivo a `CommunitySetting` 1.
- Procedimiento ante fallo reportado de borrado de reemplazo: revisar registros y respaldo; comparar manualmente las rutas del disco privado contra **todos** los `file_path` en `documents`, incluidos los eliminados lógicamente. Investigar referencias y retención antes de solicitar autorización para eliminar cualquier archivo. No se ejecuta limpieza automática ni se eliminan archivos en esta fase.
- PHP no está disponible en el PATH de Windows. Existe el contenedor `loteo-facil-serve`, con PHP 8.3 y el repositorio montado en `/app`, que podría servir para ejecutar Artisan y pruebas.

## Checklist

- [x] Etapa 1: fundamentos, autorización y pruebas.
- [x] Etapa 2: configuración, seeder y pruebas.
- [x] Etapa 3: componente reutilizable y pruebas enfocadas.
- [x] Etapa 4: integraciones y pruebas enfocadas, con las restricciones históricas documentadas.
- [x] Etapa 5: centro documental y pruebas enfocadas.
- [x] Etapa 6: validación final, auditoría y suite de confirmación.

## Archivos creados o modificados

- `DOCUMENT_MANAGEMENT_PROGRESS.md`: registro de análisis.
- `database/migrations/2026_11_21_163638_create_document_management_tables.php`: esquema inicial.
- `tests/Feature/DocumentManagementSchemaTest.php`: pruebas del esquema.
- `app/Models/Document.php`, `DocumentType.php`, `DocumentTypeModule.php`, `Documentable.php` y `app/Models/Concerns/HasDocuments.php`: modelos y relación reutilizable.
- `app/Providers/AppServiceProvider.php`: morph map compatible con registros históricos.
- `config/filesystems.php`: disco documental privado local.
- `database/seeders/DocumentTypeSeeder.php`: catálogo inicial idempotente.
- `tests/Feature/DocumentTypeCatalogTest.php` y `DocumentRelationsTest.php`: catálogo y relaciones.
- `app/Domain/Documents/Services/DocumentStorageService.php`, `DocumentAuthorizationService.php`, `app/Policies/DocumentPolicy.php`: almacenamiento y autorización inicial.
- `app/Http/Controllers/DocumentFileController.php`, `routes/web.php`: descarga y previsualización autorizadas.
- `tests/Feature/DocumentStorageServiceTest.php`, `DocumentAccessTest.php`: pruebas de archivo y acceso HTTP.
- `app/Domain/Documents/Services/DocumentService.php`, `tests/Feature/DocumentServiceTest.php`: subida, reemplazo, retención y fallo transaccional.
- `app/Filament/Admin/Resources/DocumentTypes/` y `app/Policies/DocumentTypePolicy.php`: catálogo y permisos administrativos. `tests/Feature/DocumentTypeResourceTest.php`: CRUD y permisos.
- `app/Livewire/DocumentManager.php`, `resources/views/livewire/document-manager.blade.php` y `tests/Feature/DocumentManagerTest.php`: gestor incrustable y flujos Livewire/HTTP.
- `app/Filament/Concerns/HasDocumentManager.php`, fichas de gasto, egreso, recaudación y configuración comunitaria, historial de acciones, ficha administrativa de lote y vistas del portal de propietario: integraciones.
- `tests/Feature/DocumentIntegrationTest.php`: comprobaciones de integración, permisos y estados financieros.

## Pruebas ejecutadas

- `DocumentManagementSchemaTest`: 2 pruebas, 6 aserciones.
- `DocumentTypeCatalogTest`: 2 pruebas, 4 aserciones.
- `DocumentRelationsTest` y `HistoricalExternalIdentityTest`: 5 pruebas, 9 aserciones.
- Ejecución conjunta final de los cuatro archivos: 9 pruebas, 19 aserciones, satisfactorias. Pint ejecutado sobre los archivos PHP de este bloque.
- `DocumentStorageServiceTest`: 4 pruebas, 6 aserciones; `DocumentAccessTest`: 2 pruebas, 10 aserciones.
- `DocumentServiceTest`: 3 pruebas, 12 aserciones. Se ejecuta `migrate:fresh` sobre SQLite en memoria porque una migración histórica existente impide ejecutar rollback de migraciones.
- `DocumentTypeResourceTest`: 3 pruebas, 26 aserciones, incluyendo filtros y acción de eliminación deshabilitada. `DocumentServiceTest` ampliado: 4 pruebas, 18 aserciones, incluyendo fallo al borrar el archivo anterior.
- `DocumentServiceTest`: 6 pruebas, 23 aserciones, incluida desvinculación segura. `DocumentManagerTest`: 6 pruebas, 44 aserciones, incluido el flujo y las restricciones de acceso, tipos, cantidad y formato.
- Primera suite completa: interrumpida por el límite PHP del contenedor de 128 MB (memoria agotada en `DocumentTypeResourceTest`, sin fallo de aserción). Se relanzó con límite de 512 MB solo para las pruebas.
- Segunda suite completa (512 MB): 710 aprobadas y 19 fallidas. No es una verificación concluyente: se modificaron simultáneamente la clase y la vista del dashboard, y hubo ejecuciones de pruebas concurrentes que comparten discos `Storage::fake()`; los fallos incluyen variables de vista inconsistentes y archivos temporales ausentes. El dashboard pasó 16 pruebas de forma aislada después de estabilizar el código. Repetir la suite completa sin cambios ni pruebas concurrentes antes de afirmar que no hay regresiones.
- Tercera suite completa (512 MB, código estabilizado): 731 aprobadas y 1 fallida, 5419 aserciones. Falló `OwnerImportAccountsTest::test_missing_email_does_not_allow_invalid_rut` porque no estaba disponible el archivo temporal `storage/framework/testing/disks/local/tmp/owners.csv`. El archivo completo de pruebas `OwnerImportAccountsTest`, repetido con disco falso aislado mediante `TEST_TOKEN`, pasó 9 pruebas y 41 aserciones. No está confirmada la causa de la ausencia del archivo; no se modificó el módulo de importaciones.
- `DocumentIntegrationTest`: 5 pruebas, 42 aserciones; `Owner/DashboardTest`: 16 pruebas, 92 aserciones.
- Regresión documental enfocada (`DocumentServiceTest`, `DocumentManagerTest`, `DocumentAccessTest`): 14 pruebas, 77 aserciones. Pint ejecutado sobre los archivos PHP tocados.
- `ShareFilamentTest`: 8 pruebas, 86 aserciones.
- Ninguno de los flujos integrados exige adjuntos para completar operaciones; se mantienen opcionales. El mecanismo `validateRequired()` está cubierto por `DocumentManagerTest` y deberá conectarse en el anfitrión si se define obligatoriedad.

## Problemas y pendientes

- La futura multicomunidad requerirá introducir y verificar pertenencia en el dominio existente antes de habilitarla.
- Lotes privados de propietario siguen restringidos; la consulta histórica por movimiento solo habilita al propietario de origen, no al adquirente.
- Pendiente edición de metadatos y pruebas adicionales de permisos, concurrencia y cobertura de fallos. La subida está disponible a través del componente, no como endpoint independiente.
- El carácter obligatorio debe ser aplicado por el anfitrión mediante `validateRequired()`; revisar esta integración al implementar cada módulo.
- Completar análisis de almacenamiento persistente y respaldo. No se aplicó la migración fuera del entorno aislado de pruebas.
- Las fichas de movimientos y participaciones no disponen de una ruta individual directa: el centro no ofrece un enlace de origen para ellas. La agrupación de lotes del propietario permanece vacía hasta definir una política histórica segura.

## Etapa 5 — centro documental y diagnóstico de pruebas

- [x] Centro administrativo con búsqueda, filtros, metadatos, vista previa, descarga y edición autorizada. Subida comunitaria mediante el gestor existente.
- [x] Centro de propietario con pestañas por categoría, consulta autorizada antes de paginar y enlaces a recaudaciones propias.
- [x] Etapa 6: validación final, auditoría de seguridad y suite de confirmación.
- `DocumentAuthorizationService::visibleQuery()` aplica en SQL la misma restricción de todas las vinculaciones que `DocumentPolicy`; las pruebas comparan ambos para propietarios, administradores, comunidad, movimientos y recaudaciones. Se cargan anticipadamente tipos, entidades y propietarios usados en el centro administrativo.
- `app/Filament/Admin/Resources/Documents/`, `app/Filament/Owner/Resources/Documents/`, `resources/views/filament/documents/community-upload.blade.php`, `tests/Feature/DocumentCenterTest.php` y `tests/Feature/DocumentVisibilityQueryTest.php` implementan y verifican el centro.
- Reproducción: documentos más importación de propietarios, 40 pruebas y 204 aserciones; importaciones administrativas y financieras más importación de propietarios, 113 pruebas y 2036 aserciones. Ambos subconjuntos pasaron. No se identificó un vecino que reproduzca el fallo.
- El archivo `owners.csv` anterior residía dentro del directorio compartido que `Storage::fake('local')` limpia al reinicializarse; se sustituyó únicamente el fixture por `UploadedFile::fake()->createWithContent()`, independiente de ese directorio. La prueba adicional comprueba que el fixture sobrevive a reinicializar el disco falso. `OwnerImportAccountsTest`: 10 pruebas y 43 aserciones. Esto elimina el riesgo observado, pero no prueba que esa fuera la causa efectiva del fallo intermitente anterior.
- Centro, consulta de autorización y fixture de importación tras Pint: 16 pruebas, 118 aserciones. Se repetirá la suite completa con 512 MB sobre los cambios finales.
- Suite completa con disco falso aislado y 512 MB: 747 aprobadas y 1 fallida, 5612 aserciones. El único fallo fue la expectativa anterior de navegación del propietario, que no incluía la nueva opción «Mis documentos». Se actualizó esa expectativa; `Owner/DashboardTest` pasó 16 pruebas y 93 aserciones. Pendiente una nueva ejecución completa posterior al ajuste.
- Suite completa posterior al ajuste, con disco falso aislado y 512 MB: **748 pruebas aprobadas, 5630 aserciones**, en 658,21 segundos.

## Etapa 6 — validación final (cerrada)

- [x] Auditoría de metadatos: la edición del centro ignora `visibility`, `uploaded_by`, `community_id`, `disk` y `file_path` del cliente. Verificado con `DocumentCenterTest::test_metadata_edit_ignores_client_supplied_security_and_storage_fields`.
- [x] Consultas: `DocumentManager` paginado (10 por página) y reautorizado con `visibleQuery` sin consultar `documentables` por documento. Cubierto con `DocumentManagerTest::test_owner_documents_are_authorized_and_paginated_without_per_document_link_queries`.- Centro, gestor y consulta de autorización tras los cambios de esta etapa: 14 pruebas, 132 aserciones; Pint ejecutado. Pendiente el resto de la auditoría y la suite completa final.
- [x] Registro de policies en el Gate (`Document`, `DocumentType`) en `AppServiceProvider`; verificado con `DocumentVisibilityQueryTest::test_document_policy_is_registered_in_the_gate`.
- [x] Acceso al recurso de tipos cerrado a no administradores: `canAccess` en `DocumentTypeResource` y `ManageDocumentTypes`; verificado con `DocumentTypeResourceTest::test_type_resource_is_inaccessible_to_owners_and_anonymous_users`.
- [x] Vista/edición de documentos del centro autorizadas por policy vía `DocumentResource::can()` en ambos paneles; verificado por el conjunto de `DocumentCenterTest` (5 pruebas, 49 aserciones).
- Centro, tipos y consulta de autorización tras Pint: 12 pruebas, 117 aserciones. Pendiente revisión de responsive, documentación de almacenamiento y suite final.
- [x] Responsive: etiquetas del gestor convertidas en visibles y independientes del placeholder (`font-medium` sobre texto del campo), conservando el layout de una columna en móvil y dos en `sm:`. Verificado tras el ajuste con `DocumentManagerTest` (7 pruebas, 46 aserciones). Filament hereda el resto del responsive del panel.
- Regresión enfocada tras la auditoría completa del módulo (11 archivos de pruebas documentales): 41 pruebas, 256 aserciones. La suite completa final está en ejecución.
- **Suite completa final de confirmación (512 MB, disco falso aislado, TEST_TOKEN=stage6_all): 757 pruebas aprobadas, 5673 aserciones**, en 1055,37 segundos. Cero fallos.

### Almacenamiento y operaciones

- Disco usado por el módulo documental: `documents` (`config/filesystems.php`), driver `local`, raíz `/app/storage/app/private` dentro del contenedor `loteo-facil-serve` (en producción `/var/www/storage/app/private`). Ruta física de cada archivo: `storage/app/private/communities/1/documents/{uuid}.{ext}`. Ningún documento privado se publica en `public/`; solo `storage:link` expone `public/storage` (`app/public`), que no contiene documentos.
- Persistencia: el despliegue en Coolify debe montar Persistent Storage en `/var/www/storage/app` (ya indicado en el README). Esa carpeta incluye `private/` (documentos) y también subidas administrativas y logos; enCoolify debe conservarse completa. Si la instalación corre con `docker compose` local, persistir el directorio `storage/` del proyecto.
- Respaldo obligatorio: base de datos completa **y** `storage/app/private`. Sin los archivos, los registros de `documents` apuntan a rutas inexistentes. Verificar restauración de ambos antes de confiar en el respaldo.
- Limpieza de huérfanos (manual, con autorización): comparar el contenido físico de `communities/1/documents/` contra **todos** los `file_path` de `documents` **incluyendo eliminados lógicamente** (`SELECT file_path FROM documents UNION ALL SELECT file_path FROM documents WHERE deleted_at IS NOT NULL`). Archivos presentes sin registro: solo borrar tras revisar logs del día, respaldos y confirmar que no corresponden a un reemplazo fallido. Archivos registrados ausentes: restaurar desde respaldo. No automatizar en esta fase.
- Cambio a S3 u otro disco: definir en `filesystems.disks.documents` el driver destino (p. ej. `s3`, ya definido con variables `AWS_*`) sin cambiar la raíz `local`; mover los objetos conservando la ruta relativa `communities/1/documents/...`; y actualizar `documents.disk` en base de datos (`UPDATE documents SET disk = 's3'`) solo después de verificar la migración. `DocumentFileController` valida `disk === 'documents'`: ese nombre de disco se mantiene. Preferir un comando de copia offline a hacerlo en caliente.
- Reglas vigentes en producción: `upload_max_filesize`/`post_max_size` de nginx (`client_max_body_size 20m`) y PHP deben cubrir `max_size_kb` más alto posible (100 MB en la UI) o ajustar el máximo de la UI. Nginx no sirve `storage/app/private`; las descargas siempre pasan por Laravel.

## Resumen de pendientes consolidados

- Visibilidad `custom` sin implementar: denegada a propietarios y no ofrecida en la UI; habilitarla requiere un modelo de permisos explícito compatible con Spatie.
- Limpieza de huérfanos: solo manual, con el procedimiento descrito en "Almacenamiento y operaciones".
- Política histórica de lotes pendiente: el propietario no tiene acceso privado por lote; la pestaña «Mis lotes» queda vacía hasta definirla.
- El anfitrión debe invocar `validateRequired()` si algún módulo exige adjuntos obligatorios.
- Edición de metadatos limitada a descripción y fecha; reemplazo disponible desde el componente, no desde el centro.
- Concurrencia y fallos extremos de reemplazo cubren lo básico; no hay comando de GC programado ni métricas de tamaño.
- Multicomunidad futura: requerirá resolver pertenencia real en el dominio existente antes de usar `community_id` != 1.
- Integraciones futuras: modales de origen para movimientos y participaciones (sin ruta individual), y más módulos si se agregan.
