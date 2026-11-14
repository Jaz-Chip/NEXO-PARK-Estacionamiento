# NEXO PARK — Sistema de estacionamiento

**Variante académica** sobre Laravel 13 + MySQL/MariaDB, Blade, Bootstrap 5, jQuery 4/AJAX, DataTables y Spatie Laravel Permission.

## Diferencias de diseño

- **Plantilla administrativa de referencia:** [AdminLTE 4.10.0](https://www.adminlte.io/themes/v4/docs/) (licencia MIT).
- **Paleta:** azul nocturno y turquesa.
- **Navegación:** consola oscura con navegación lateral izquierda y métricas de monitoreo. **Siempre a la izquierda**, desplegable en celulares.
- Login, indicadores y vistas principales personalizados para NEXO PARK.
- Para respetar las bibliotecas de JavaScript ya probadas se mantiene la API de modales de `@tabler/core` internamente. **El CSS visual de Tabler fue sustituido por Bootstrap 5 local y el diseño administrativo alternativo**.
- La hoja de estilo oficial de AdminLTE 4.10.0 se consulta desde un CDN; si no hay conexión, quedan los estilos locales propios y Bootstrap. El enlace CDN no contiene datos privados ni ejecuta un servicio del sistema.

## Alcance del docente

| Requisito | Implementación |
| --- | --- |
| Base de datos relacional MySQL | Migraciones de Laravel con tablas y relaciones |
| Login de empleados | Inicio/cierre de sesión, usuarios desactivados bloqueados |
| Plantilla administrativa | AdminLTE 4.10.0, navegación lateral y Bootstrap |
| Clientes y abonados | Alta, edición, estado y abonos |
| Vehículos | Placa, tipo, marca, modelo, color y propietario opcional |
| Pisos, sectores y espacios | Ubicación y disponibilidad comprobada |
| Entradas y salidas | Boleto, fecha/hora, duración y tarifa configurada |
| Caja | Cobro en efectivo o QR de confirmación manual |
| Estadísticas | Ingresos diarios/mensuales, vehículos frecuentes, ocupación actual/histórica |
| PDF y Excel | Exportación de reportes |
| No eliminación física | Activación/desactivación de clientes, usuarios, vehículos y espacios |
| Extras: roles/middleware | Spatie, usuarios, asignación de roles y permisos con casillas |
| Extra: DataTables | Búsqueda, ordenamiento y paginación |
| jQuery/AJAX | Formularios en modales y filtros sin recarga completa; un JS por módulo |

**Alcance compactado:** las opciones se agrupan en Gestión, Operaciones, Reportes y Administración. Tipos, pisos, sectores y tarifas van dentro de **Configuración**. El historial de tickets se consulta desde operaciones, sin duplicar la navegación. Se conserva la lógica y las rutas del motor original para evitar cambios de integridad.

## Instalación en Windows con VS Code

1. Extraer el ZIP y abrir su carpeta raíz en VS Code.
2. Abrir MySQL (XAMPP o servidor propio) y crear **una base de datos vacía** llamada `parqueo_nexo_park` (o definir otro nombre en `.env`).
3. Ejecutar en la terminal `composer install` y `npm install`.
4. Copiar `.env.example` a `.env` y editar los datos de MySQL y `APP_URL`.
5. Completar en `.env` **DEMO_ADMIN_PASSWORD**, **DEMO_OPERATOR_PASSWORD**, **DEMO_SUPERVISOR_PASSWORD** con tres contraseñas de **al menos 12 caracteres**. Los correos están en `DEMO_*_EMAIL`. No hay contraseñas incluidas en esta entrega.
6. Ejecutar `php artisan key:generate` y `php artisan migrate --seed` **solo para una base nueva o vacía**. Nunca usar `migrate:fresh` sobre una base con datos.
7. Abrir una terminal con `php artisan serve` y otra con `npm run dev`.
8. Visitar `http://127.0.0.1:8000`, iniciar sesión y comenzar registrando un piso, sector, espacio y tarifa.

Para preparar assets de producción: `npm run build`. El instalador necesita Internet para Composer y npm, aunque el CSS base de Bootstrap ya está incluido localmente.

> **Bases independientes:** si vas a probar las tres versiones al mismo tiempo, utiliza tres bases MySQL distintas. Cada versión tiene su propio `.env`, aunque compartan la estructura de datos.

## Verificación y limitaciones

- Probar con `php artisan route:list`, `php artisan test` y `npm run build` en Windows tras instalar las dependencias.
- La UI está preparada con modales; el menú móvil utiliza `resources/js/ui-shell.js`.
- Esta variante conserva la lógica Laravel común con otras variantes. **No representa un desarrollo independiente de cero**: es una personalización del mismo motor. Cada persona debe comprender y atribuir el trabajo de acuerdo con las reglas académicas de su materia.
- QR significa **registro manual de pago confirmado**, no transferencia automática ni factura fiscal del SIAT.
- Las mensualidades se administran como abonos, pero no descuentan automáticamente el cobro por estancia.
- No se incluyen `.env`, bases privadas, contraseñas, `vendor/` ni `node_modules/`.
- Esta entrega no ha pasado una ejecución integral en este entorno: tras extraer e instalar, verifica las rutas y los flujos en tu equipo.

## Créditos de plantillas

NEXO PARK: interfaz adaptada sobre la plantilla abierta [AdminLTE 4.10.0](https://www.adminlte.io/themes/v4/docs/). CSS base Bootstrap 5.3.6 (MIT), DataTables (MIT), jQuery (MIT), SweetAlert2, Laravel y Spatie bajo sus respectivas licencias. Los avisos se incluyen en `LICENCIAS_Y_FUENTES.md`.
