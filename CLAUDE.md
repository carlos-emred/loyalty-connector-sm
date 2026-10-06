# Conector SalesManago: Voucherify ↔ SalesManago

Contexto del proyecto. Léelo antes de tocar nada.

## Qué es

Versión para **SalesManago** del conector de Blueshift (`/opt/loyalty/repo`). Mismo esqueleto (`bootstrap`, `Config`, `Http`, `Log`, `Auth`, `Cors`, `Db`, cola en PostgreSQL y worker), mismas reglas: **las de su `CLAUDE.md` se aplican aquí igual**, en especial timeouts en todas las llamadas (`Http::postJson`), nada sensible en `public/`, nada se borra de la base y logs sin credenciales ni datos personales.

Por ahora solo hay un flujo:

| Fichero | Qué hace |
|---|---|
| `public/registro_club.php` | Síncrono. Recibe el formulario del storefront, valida la contraseña, responde 409 si el correo ya tiene un registro completado (`sincronizado_en` no nulo), guarda al usuario en `sm_clientes` (contraseña solo como hash en `password_hash`), hace upsert del cliente en Voucherify (`source_id` = DNI) y después del contacto en SalesManago con su `voucherifyId` |

El id de Voucherify (`cust_...`) sale de la respuesta del alta en `registro_club.php`. Por eso no hay webhook `customer.created`: el antiguo `completar_usuario.php` se retiró. La cola (`sm_eventos_pendientes`) y el worker siguen en el repositorio como parte del esqueleto común, pero hoy no tienen ningún manejador registrado.

## Convivencia con el conector de Blueshift

Misma instancia EC2, **misma base** (`loyalty`) y mismo esquema (`loyalty`), mismo usuario `loyalty_app`. Todo lo demás va separado:

| | Blueshift | SalesManago |
|---|---|---|
| Ruta | `/opt/loyalty/` | `/opt/salesmanago/` |
| `.env` | `/opt/loyalty/.env` | `/opt/salesmanago/.env` |
| Usuario del sistema | `loyalty` | `salesmanago` |
| Pool PHP-FPM | `loyalty.sock` | `salesmanago.sock` |
| Worker | `loyalty-worker` | `salesmanago-worker` |
| Logs | `/var/log/loyalty/` | `/var/log/salesmanago/` |
| Tablas | `clientes`, `eventos_pendientes`... | `sm_clientes`, `sm_eventos_pendientes` |
| Espacio de nombres PHP | `Loyalty\` | `SalesManago\` |

**Prefijo `sm_` obligatorio** en toda tabla, índice y restricción nuevos: en PostgreSQL los nombres de índice son únicos por esquema. Desde aquí no se lee ni se escribe ninguna tabla del conector de Blueshift.

La cola es propia a propósito: si los dos conectores reciben el mismo evento de Voucherify, llega con el mismo `event.id`; con una sola tabla, el segundo chocaría con la restricción de unicidad y se daría por duplicado.

## SalesManago

- Las credenciales van en el cuerpo de la petición, no en cabecera. El cuerpo nunca se escribe en el log.
- Responde 200 también cuando rechaza: se comprueba `success` (`ApiSalesManago::upsertContacto`).
- `registro_club.php` no reintenta respuestas ambiguas: con `useApiDoubleOptIn` un segundo upsert podría enviar otra vez el correo de confirmación.
- Los nombres de `properties` (`dni`, `genero`, `voucherifyId`) son los de los scripts originales y las segmentaciones dependen de ellos. No se renombran.

## Despliegue inicial

```bash
sudo useradd --system --home /opt/salesmanago --shell /sbin/nologin salesmanago
sudo mkdir -p /opt/salesmanago /var/log/salesmanago
sudo chown salesmanago:salesmanago /opt/salesmanago /var/log/salesmanago
sudo -u salesmanago git clone <repo> /opt/salesmanago/repo
# .env a partir de .env.ejemplo, propiedad de salesmanago y modo 600
sudo cat /opt/salesmanago/repo/bbdd/01_esquema.sql | sudo -u postgres psql -d loyalty
sudo cp /opt/salesmanago/repo/despliegue/php-fpm-salesmanago.conf /etc/php-fpm.d/salesmanago.conf
sudo cp /opt/salesmanago/repo/despliegue/nginx-salesmanago.conf /etc/nginx/conf.d/salesmanago.conf
sudo cp /opt/salesmanago/repo/despliegue/salesmanago-worker.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now salesmanago-worker
sudo systemctl reload php-fpm nginx
```

No hace falta ningún webhook en Voucherify para este conector. El del conector de Blueshift no se toca.

## Pendiente de confirmar

- `forceOptIn` y `forceOptOut` (y los de teléfono) van los dos a `true`, como en el script original. Son contradictorios: confirmar con marketing el estado que debe quedar.
- Dominio definitivo en `nginx-salesmanago.conf`.
