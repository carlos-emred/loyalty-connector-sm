# Conector SalesManago: Voucherify ↔ SalesManago

Contexto del proyecto. Léelo antes de tocar nada.

## Qué es

Versión para **SalesManago** del conector de Blueshift (`/opt/loyalty/repo`). Mismo esqueleto (`bootstrap`, `Config`, `Http`, `Log`, `Auth`, `Cors`, `Db`, cola en PostgreSQL y worker), mismas reglas: **las de su `CLAUDE.md` se aplican aquí igual**, en especial timeouts en todas las llamadas (`Http::postJson`), nada sensible en `public/`, nada se borra de la base y logs sin credenciales ni datos personales.

Por ahora hay tres flujos:

| Fichero | Qué hace |
|---|---|
| `public/registro_club.php` | Síncrono. Recibe el formulario del storefront, valida la contraseña, responde 409 si el correo ya tiene un registro completado (`sincronizado_en` no nulo), guarda al usuario en `sm_clientes` (contraseña solo como hash en `password_hash`), hace upsert del cliente en Voucherify (`source_id` = DNI) y después del contacto en SalesManago con su `voucherifyId` |
| `public/webhooks/anadir_cupon.php` | Webhook `voucher.published` de Voucherify (cupón asignado a un cliente). Encola en `sm_eventos_pendientes`; el worker (`ManejadorCuponAsignado`) guarda el cupón en `sm_cupones` y lanza un evento externo (`detail1` = `COMUNICAR_CUPON`) en el contacto de SalesManago, que dispara el correo. `notificado_en` impide enviarlo dos veces. Las tarjetas de fidelización (`LOYALTY_CARD`) solo se guardan en `sm_clientes.loyalty_card`: ni `sm_cupones` ni SalesManago |
| `public/crear_cupon.php` | Síncrono, lo llama el storefront. Crea el cupón en Shopify (regla de precio + código, `ApiShopify`) restringido al cliente del correo recibido. Todos los datos del cupón llegan en la petición: no consulta a Voucherify ni a la base. 409 si el código ya existe, 404 si no hay cliente en Shopify con ese correo |

El id de Voucherify (`cust_...`) sale de la respuesta del alta en `registro_club.php`. Por eso no hay webhook `customer.created`: el antiguo `completar_usuario.php` se retiró. `sm_clientes` tiene también `tier`, `saldo_puntos` y `total_puntos`, que de momento ningún flujo rellena.

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
| Tablas | `clientes`, `eventos_pendientes`... | `sm_clientes`, `sm_eventos_pendientes`, `sm_cupones` |
| Espacio de nombres PHP | `Loyalty\` | `SalesManago\` |

**Prefijo `sm_` obligatorio** en toda tabla, índice y restricción nuevos: en PostgreSQL los nombres de índice son únicos por esquema. Desde aquí no se lee ni se escribe ninguna tabla del conector de Blueshift.

La cola es propia a propósito: si los dos conectores reciben el mismo evento de Voucherify, llega con el mismo `event.id`; con una sola tabla, el segundo chocaría con la restricción de unicidad y se daría por duplicado.

## SalesManago

- Las credenciales van en el cuerpo de la petición, no en cabecera. El cuerpo nunca se escribe en el log.
- Responde 200 también cuando rechaza: se comprueba `success` (`ApiSalesManago::upsertContacto`).
- `registro_club.php` no reintenta respuestas ambiguas: con `useApiDoubleOptIn` un segundo upsert podría enviar otra vez el correo de confirmación.
- Los nombres de `properties` (`dni`, `genero`, `voucherifyId`) son los de los scripts originales y las segmentaciones dependen de ellos. No se renombran.
- Las posiciones `detail1`..`detail10` del evento externo del cupón son las del script original (`v2/webhooks/añadir_cupon.php`) y la plantilla del correo se apoya en ellas. No se mueven. El código del cupón va en `detail11`, porque `value` es numérico en SalesManago.
- El evento externo tampoco reintenta respuestas ambiguas: cada evento es un correo.

## Shopify

- API REST de administración (`price_rules` y `discount_codes`), versión en `SHOPIFY_API_VERSION`. Shopify la considera heredada pero sigue funcionando; es la que usa también el conector de Blueshift.
- Si el código no se puede asociar a la regla, la regla se elimina para no dejarla huérfana.
- El cupón de Shopify no lleva fechas de inicio ni de caducidad ni `quantity`: la validez la decide Voucherify, que se consulta siempre justo antes de aplicarlo. Shopify solo aplica el descuento. `starts_at` es obligatorio en Shopify y se pone el momento de creación.
- Los importes de la petición van en céntimos (`count`, `maxCount`), salvo `minimum_purchase_quantity` y `reduction_amount`, que van en euros como en el script original.

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

En Voucherify, un webhook **nuevo** para `voucher.published` hacia `https://<dominio>/webhooks/anadir_cupon.php` con la cabecera `X-Loyalty-Key` y el `CONECTOR_SECRETO` de este `.env`. El webhook del conector de Blueshift no se toca.

## Pendiente de confirmar

- `forceOptIn` y `forceOptOut` (y los de teléfono) van los dos a `true`, como en el script original. Son contradictorios: confirmar con marketing el estado que debe quedar.
- Dominio definitivo en `nginx-salesmanago.conf`.
- `crear_cupon.php` crea en Shopify el descuento que se le pida, y su secreto es público porque lo llama el navegador. Falta una protección real (firma del App Proxy de Shopify o comprobar el código contra `sm_cupones`).
