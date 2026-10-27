# Conector SalesManago: Voucherify ↔ SalesManago

Contexto del proyecto. Léelo antes de tocar nada.

## Qué es

Versión para **SalesManago** del conector de Blueshift (`/opt/loyalty/repo`). Mismo esqueleto (`bootstrap`, `Config`, `Http`, `Log`, `Auth`, `Cors`, `Db`, cola en PostgreSQL y worker), mismas reglas: **las de su `CLAUDE.md` se aplican aquí igual**, en especial timeouts en todas las llamadas (`Http::postJson`), nada sensible en `public/`, nada se borra de la base y logs sin credenciales ni datos personales.

Por ahora hay diez flujos:

| Fichero | Qué hace |
|---|---|
| `public/registro_club.php` | Síncrono. Recibe el formulario del storefront, valida la contraseña, responde 409 si el correo ya tiene un registro completado (`sincronizado_en` no nulo), guarda al usuario en `sm_clientes` (contraseña solo como hash en `password_hash`), hace upsert del cliente en Voucherify (`source_id` = DNI) y después del contacto en SalesManago con su `voucherifyId` |
| `public/webhooks/anadir_cupon.php` | Webhook `voucher.published` de Voucherify (cupón asignado a un cliente). Encola en `sm_eventos_pendientes`; el worker (`ManejadorCuponAsignado`) guarda el cupón en `sm_cupones` y lanza un evento externo (`detail1` = `COMUNICAR_CUPON`) en el contacto de SalesManago, que dispara el correo. `notificado_en` impide enviarlo dos veces. Las tarjetas de fidelización (`LOYALTY_CARD`) solo se guardan en `sm_clientes`: el código en `loyalty_card` y su `campaign_id` en `loyalty_campaign`; ni `sm_cupones` ni SalesManago |
| `public/webhooks/consumir_cupon.php` | Webhook `redemption.succeeded` de Voucherify (cupón canjeado). Encola en `sm_eventos_pendientes`; el worker (`ManejadorCuponConsumido`) actualiza los usos del cupón en `sm_cupones` con el `redeemed_quantity` del payload (no suma uno). El cupón no se borra: al agotar sus usos pasa a estado `canjeado`. No avisa a nadie |
| `public/crear_cupon.php` | Síncrono, lo llama el storefront. Comprueba que el correo recibido tiene fila en `sm_clientes` (404 si no) y crea el cupón en Shopify (regla de precio + código, `ApiShopify`). No busca al cliente en Shopify, porque exigiría acceso al email de los clientes (datos protegidos de nivel 2), así que el cupón no queda restringido a un cliente. Todos los datos del cupón llegan en la petición: no consulta a Voucherify. 409 si el código ya existe |
| `public/obtener_datos_usuario.php` | Síncrono, lo llama el storefront. Con el correo, devuelve de PostgreSQL (`Storefront\DatosUsuario`) los puntos, tier, tarjeta y campaña de la tarjeta de `sm_clientes`, los cupones de `sm_cupones` con estado `disponible` (y ya empezados) y el historial de `sm_movimientos_puntos` de los últimos 10 meses. No llama a ninguna API. Antes de leer, pasa a `canjeado` o `caducado` los cupones disponibles del cliente que ya no lo son; es su única escritura. La forma de la respuesta es la del script original y la web depende de ella. Sin datos personales en la respuesta: el endpoint responde a cualquiera que conozca un correo |
| `public/obtener_loyalty.php` | Síncrono, lo llama el storefront. Con el `memberId`, pide a Voucherify las recompensas del miembro (`ApiVoucherify::recompensasMiembro`) y las devuelve tal cual, junto con los tiers leídos del fichero JSON `RUTA_TIERS_JSON`, como en el conector de Blueshift. 404 si Voucherify no conoce al miembro, 502 si falla. Si el fichero de tiers falta o no es válido, `tiers` va a null y las recompensas se devuelven igual. Lo mantiene `modificar_tiers.php` |
| `public/adquirir_recompensa.php` | Síncrono, lo llama el storefront. Con `campaignId`, `memberId`, `rewardId` y `rewardPoints`, canjea los puntos del miembro por la recompensa en Voucherify (`ApiVoucherify::canjearRecompensa`, `/v1/loyalties/{campaña}/members/{miembro}/redemption`) y devuelve la respuesta de Voucherify tal cual, con su código. No escribe en la base: si la recompensa es un cupón, llega después por `anadir_cupon.php`. No reintenta si la petición pudo llegar (gasta puntos). El `memberId` no se escribe entero en el log |
| `public/webhooks/anadir_puntos.php` | Webhook `voucher.loyalty_card.transaction.created` de Voucherify (movimiento de puntos). Encola en `sm_eventos_pendientes`; el worker (`ManejadorMovimientoPuntos`) guarda el movimiento en `sm_movimientos_puntos` (único por `transaction_id`), actualiza `saldo_puntos`, `total_puntos` y `tier` del socio en `sm_clientes` (por `voucherify_id`) salvo que haya un movimiento posterior, y hace upsert de las propiedades `balance`, `total_points` y `tier` en su contacto de SalesManago. El tier sale de `holder_loyalty_tier` del payload. Si el cliente no está en `sm_clientes`, solo se guarda el movimiento |
| `public/webhooks/modificar_tiers.php` | Webhook `campaign.loyalty_tier.updated` de Voucherify (cambia el nombre o los puntos de un tramo). Encola en `sm_eventos_pendientes`; el worker (`ManejadorTierActualizado`) sustituye el tramo por su `id` en el fichero `RUTA_TIERS_JSON`, o lo añade si no estaba. No toca la base. Escribe en un temporal y lo renombra, para que `obtener_loyalty.php` no lea nunca un fichero a medias. Si el fichero existe pero no es una lista de tramos válida, no lo sobrescribe y el evento falla |
| `public/webhooks/ejecutar_compra.php` | Webhook de SalesManago al entrar una compra. **Público: no pide credenciales**, solo valida el `contactId` (UUID) y encola `salesmanago.purchase`. El worker (`ManejadorCompra`) lee el contacto en SalesManago (`contact/listById`), toma sus eventos `PURCHASE` de las últimas `COMPRAS_VENTANA_HORAS` horas que no estén en `sm_compras`, pide a Shopify el precio de cada variante (GraphQL) y registra la compra en Voucherify: sin código (`detail5` vacío) como pedido pagado (`/v1/orders`, con `source_id` para que sea idempotente); con código, como canje (`/v1/redemptions`). Cada compra se anota en `sm_compras` y no se vuelve a enviar. nginx limita las peticiones por IP a este fichero |

El id de Voucherify (`cust_...`) sale de la respuesta del alta en `registro_club.php`. Por eso no hay webhook `customer.created`: el antiguo `completar_usuario.php` se retiró. `tier`, `saldo_puntos` y `total_puntos` de `sm_clientes` y la tabla `sm_movimientos_puntos` los rellena `anadir_puntos.php`.

## Cupones: usos y estado

Regla común a todo el proyecto:

- `quantity` es el número de veces que se puede consumir un cupón; `redeemed_quantity`, las veces que ya se ha consumido. Cuando son iguales, el cupón no se puede consumir más. `quantity` nulo es «usos ilimitados» en Voucherify: nunca se agota.
- `sm_cupones.estado` dice si un cupón está disponible: `disponible`, `canjeado` (usos agotados) o `caducado` (pasó `fecha_caducidad`). Solo se ofrecen los `disponible`. Nada se borra: un cupón deja de ofrecerse cambiando de estado, y nunca vuelve a `disponible`.
- Quién cambia el estado: `ManejadorCuponConsumido` (canjeado al agotarse), `ManejadorCuponAsignado` (si el cupón ya llega agotado o caducado) y `obtener_datos_usuario.php` (al consultar, los que ya no son válidos).
- `redeemed_quantity` sale siempre del payload de Voucherify y nunca retrocede (`GREATEST`).
- `sm_cupones` no tiene columna `metadata`: los datos útiles están en columnas propias (`descripcion`, `image_url`, `product_id`, `category_id`, `category_name`, `solo_uno`, `minimo_compra`).
- `crear_cupon.php` no lleva `quantity` a Shopify: la validez la decide Voucherify (ver Shopify).

## Endpoints públicos

`ejecutar_compra.php` es el único que no pide `X-Loyalty-Key`. Puede serlo porque de la petición solo toma el `contactId`: los datos de la compra se leen de SalesManago, cada compra se registra una sola vez (`sm_compras`), responde sin llamar a ninguna API y nginx limita las peticiones por IP (`limit_req` en `nginx-salesmanago.conf`). Un endpoint nuevo sin credenciales tiene que cumplir lo mismo: no fiarse de los datos de la petición, no poder repetir un efecto y no exponer datos personales.

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
| Tablas | `clientes`, `eventos_pendientes`... | `sm_clientes`, `sm_eventos_pendientes`, `sm_cupones`, `sm_movimientos_puntos`, `sm_compras` |
| Espacio de nombres PHP | `Loyalty\` | `SalesManago\` |

**Prefijo `sm_` obligatorio** en toda tabla, índice y restricción nuevos: en PostgreSQL los nombres de índice son únicos por esquema. Desde aquí no se lee ni se escribe ninguna tabla del conector de Blueshift.

La cola es propia a propósito: si los dos conectores reciben el mismo evento de Voucherify, llega con el mismo `event.id`; con una sola tabla, el segundo chocaría con la restricción de unicidad y se daría por duplicado.

## SalesManago

- Las credenciales van en el cuerpo de la petición, no en cabecera. El cuerpo nunca se escribe en el log.
- Responde 200 también cuando rechaza: se comprueba `success` (`ApiSalesManago::upsertContacto`).
- `registro_club.php` no reintenta respuestas ambiguas: con `useApiDoubleOptIn` un segundo upsert podría enviar otra vez el correo de confirmación.
- Los nombres de `properties` (`dni`, `genero`, `voucherifyId`; `balance`, `total_points` y `tier` en `anadir_puntos.php`) son los de los scripts originales y las segmentaciones dependen de ellos. No se renombran.
- Las posiciones `detail1`..`detail10` del evento externo del cupón son las del script original (`v2/webhooks/añadir_cupon.php`) y la plantilla del correo se apoya en ellas. No se mueven. El código del cupón va en `detail11`, porque `value` es numérico en SalesManago.
- El evento externo tampoco reintenta respuestas ambiguas: cada evento es un correo.

## Shopify

- API REST de administración (`price_rules` y `discount_codes`), versión en `SHOPIFY_API_VERSION`. Shopify la considera heredada pero sigue funcionando; es la que usa también el conector de Blueshift.
- Si el código no se puede asociar a la regla, la regla se elimina para no dejarla huérfana.
- El cupón de Shopify no lleva fechas de inicio ni de caducidad ni `quantity`: la validez la decide Voucherify, que se consulta siempre justo antes de aplicarlo. Shopify solo aplica el descuento. `starts_at` es obligatorio en Shopify y se pone el momento de creación. Tampoco hay importe máximo: `maxCount` se ignora.
- Los importes de la petición van en céntimos (`count`), salvo `minimum_purchase_quantity` y `reduction_amount`, que van en euros como en el script original.

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

En Voucherify, cuatro webhooks **nuevos**: `voucher.published` hacia `https://<dominio>/webhooks/anadir_cupon.php`, `redemption.succeeded` hacia `https://<dominio>/webhooks/consumir_cupon.php` `voucher.loyalty_card.transaction.created` hacia `https://<dominio>/webhooks/anadir_puntos.php` y `campaign.loyalty_tier.updated` hacia `https://<dominio>/webhooks/modificar_tiers.php`, los cuatro con la cabecera `X-Loyalty-Key` y el `CONECTOR_SECRETO` de este `.env`. El webhook del conector de Blueshift no se toca.

## Pendiente de confirmar

- `forceOptIn` y `forceOptOut` (y los de teléfono) van los dos a `true`, como en el script original. Son contradictorios: confirmar con marketing el estado que debe quedar.
- Dominio definitivo en `nginx-salesmanago.conf`.
- Si Voucherify rechaza el canje de una compra con cupón (agotado, caducado...), `ManejadorCompra` la deja como `rechazada` y no la registra como pedido sin cupón, así que el cliente no suma los puntos de esa compra. Confirmar si debe registrarse igualmente como pedido.
- `adquirir_recompensa.php`: quien conozca el `memberId` de alguien (el código de su tarjeta) puede gastar sus puntos, porque el secreto de los endpoints de navegador es público. Necesita la misma protección real que `crear_cupon.php`.
- `crear_cupon.php` crea en Shopify el descuento que se le pida, y su secreto es público porque lo llama el navegador. Falta una protección real (firma del App Proxy de Shopify o comprobar el código contra `sm_cupones`).
