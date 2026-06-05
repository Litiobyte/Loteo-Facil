# Loteo Facil - Fases 0 y 1

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

Usuarios iniciales (seeder):

- Super Admin: `superadmin@loteofacil.cl` / `password`
- Admin: `admin@loteofacil.cl` / `password`
- Propietario (acceso solo a panel propietario): `propietario@loteofacil.cl` / `password`

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
