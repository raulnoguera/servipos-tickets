# ServiPos Tickets

Sistema de monitoreo y gestión de tickets de soporte para cajeros automáticos (ATM) de **ServiPos 2019, C.A.**
Alcance contratado: Opción 3 — Suite Enterprise.

## Stack

- **Backend:** Laravel 13 (PHP ≥ 8.3) + MySQL/MariaDB en hosting cPanel. En desarrollo local se usa SQLite.
- **Frontend:** React 19 + Tailwind 4 (Vite). Panel web del Call Center y vista móvil instalable para técnicos (iPhone/Android). Colores y tipografías de servipos2019.com.
- **Excel:** PhpSpreadsheet (importación del histórico y exportación del consolidado).

## Modelo de datos

| Tabla | Contenido |
|---|---|
| `banks`, `states`, `atms` | Maestros de bancos, estados/regiones y cajeros (RF-1.1, RF-1.2) |
| `users` + `state_user` | Usuarios con rol `admin`, `operator` o `technician`, y estados que cubre cada técnico (RF-1.3) |
| `fault_types`, `parts` | Catálogo de fallas y repuestos (RF-1.4) |
| `atm_conditions` | "Estatus Final" del Excel (cómo queda el cajero) y el estado de ticket al que lleva |
| `tickets` | Un ticket por falla reportada. N° correlativo `TK-AAAA-NNNNN` que vuelve a 1 cada año (RF-2.2) |
| `visits` + `visit_parts` | Cada atención en sitio (= una fila del consolidado), con constancia, repuestos y cantidades (RF-3.x) |
| `ticket_events` | Bitácora de cambios de estado |

**Estados del ticket:** Abierto → Asignado → En tránsito → En sitio → Concluido / Pendiente por repuesto / Pendiente por validación (y Anulado).
Un ticket pendiente se reabre con una nueva visita. El reloj del SLA corre desde la apertura hasta el cierre y **no se detiene** mientras espera repuesto.

## Puesta en marcha (local)

```bash
composer install
cp .env.example .env && php artisan key:generate   # definir ADMIN_EMAIL / ADMIN_PASSWORD
php artisan migrate --seed
```

### Importar el histórico del Excel

```bash
# Primero simular: valida todo y genera el reporte de limpieza sin guardar nada
php artisan servipos:importar-excel "ruta/al/Libro Consolidado.xlsx" --banco="Nombre del banco" --simular
# Luego importar
php artisan servipos:importar-excel "ruta/al/Libro Consolidado.xlsx" --banco="Nombre del banco"
```

El reporte queda en `storage/app/private/importacion/`. Los técnicos importados reciben un correo provisional
(`nombre.apellido@tecnicos.servipos.test`) que debe actualizarse antes de darles acceso.

### Exportar el consolidado

```bash
php artisan servipos:exportar-consolidado --desde=2026-08-01 --hasta=2026-08-31 [--banco="..."]
```

Genera la pestaña "Base General" con las mismas columnas del libro actual, más la columna "N° Ticket".
Si una visita usó varios repuestos van juntos en una celda (`PICKER x2, FEEDSHAFT x1`) y la cantidad es el total.

### Interfaz

```bash
npm install
npm run dev      # desarrollo con recarga (junto a: php artisan serve)
npm run build    # compila a public/build
```

Datos de demostración (solo ambiente de pruebas; requiere `DEMO_PASSWORD` en `.env`):

```bash
php artisan db:seed --class=DemoSeeder
```

Crea `operador@servipos.test` y `tecnico@servipos.test` y ~70 tickets de los últimos 30 días.

### Publicar en Hostinger (enlace temporal)

1. En tu Mac: `./deploy/empaquetar-hostinger.sh` → genera `dist/servipos-tickets-AAAAMMDD-HHMM.zip`.
2. hPanel → **Avanzado → Configuración de PHP**: versión **8.3 o superior**.
3. hPanel → **Bases de datos MySQL**: crear base de datos y usuario.
4. hPanel → **Administrador de archivos** → `public_html` del dominio temporal: subir el `.zip` y extraerlo ahí (queda `public_html/.htaccess`, `public_html/app`, `public_html/public`, …).
5. Copiar `deploy/env.hostinger.example` como `.env` y completar los valores `CAMBIAR`. La contraseña de MySQL se escribe con `bash deploy/configurar-bd.sh` (la pide sin mostrarla y prueba la conexión).
6. Por SSH (hPanel → Avanzado → Acceso SSH), dentro de `public_html`:

```bash
php artisan key:generate --force
php artisan migrate --seed --force
php artisan config:cache && php artisan route:cache
```

7. Entrar con el administrador del `.env` → **Importar Excel** (simular, luego importar). Los datos de demostración (`DemoSeeder`) no se ejecutan en producción; solo en un sitio de pruebas con `ALLOW_DEMO_DATA=true` en `.env`.

8. Notificaciones push: `php artisan servipos:claves-push && php artisan config:cache`.

**Actualizaciones:** con `deploy/hostinger.conf` configurado (ver `hostinger.conf.example`) y la llave SSH autorizada en hPanel:

```bash
./deploy/actualizar-hostinger.sh
```

Empaqueta, sube, pone el sitio en mantenimiento unos segundos, extrae sin tocar `.env` ni `storage/`, migra y verifica.

## App del técnico: sin señal y notificaciones

- **Sin señal:** la app queda guardada en el teléfono (`public/sw.js`). Los tickets asignados, su detalle y los catálogos se leen de lo último descargado. Los cambios de estado y las atenciones (con foto) se guardan en una bandeja del teléfono (IndexedDB) y se envían solos al volver la conexión, con la hora real en que se hicieron.
- **Notificaciones push:** al asignar un ticket se avisa al técnico en su teléfono. En Android funciona desde Chrome; en iPhone (iOS 16.4+) hay que **agregar la app a la pantalla de inicio** y activar los avisos desde ahí.

### Pruebas

```bash
php artisan test
```

## Pendientes

- [ ] **Correos de los bancos:** muestras reales para programar la lectura automática (apertura de tickets por correo).
- [ ] **Inventario de repuestos:** definir con el cliente almacenes (central / por técnico / por región).
- [ ] **PDF de facturación:** confirmar con el cliente el formato del reporte mensual por banco.
- [ ] **Hosting:** confirmar acceso al cPanel y que tenga PHP ≥ 8.3 (MultiPHP Manager).
- [ ] **Banco de los cajeros del histórico:** el Excel no lo indica.
- [ ] Datos del maestro a completar: 107 cajeros sin modelo, 116 sin dirección, 9 sin marca, 3 con código de agencia vacío.
