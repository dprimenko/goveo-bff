# goveo-bff — notas para agentes

BFF Symfony/PHP que sirve la API pública que consume la app móvil (`goveo-expo-approuter`)
bajo rutas `/public/...`. Corre en Docker (`make up`; PHP en el contenedor `goveo-bff-php-1`,
nginx en `:8080`, Postgres/PostGIS en `goveo-db`, DB `goveo`/`goveo`).

`php` NO está en el host — ejecutar siempre dentro del contenedor:
`docker exec goveo-bff-php-1 sh -lc 'php bin/console ...'`.

## API pública (`/public`)

- `GET /public/businesses?lat&lng&page&size&category&radius` — negocios por cercanía (page **base 1**).
  `radius` (metros, máx 500 km) acota con `ST_DWithin` sobre el índice GiST: lo usa el mapa de la
  app para pedir sólo la zona visible en vez de traer todo por cercanía. Cada item incluye
  `lat`/`lng` para los marcadores.
  `category` admite **varias separadas por coma**, cada una como id **o slug**, y con `-` delante
  **excluye** (`category=-hotels,-boats` = todo menos esas). La home lo usa para partir el listado
  en «turismo» y «comercio local»: el segundo es *todo lo demás*, y enumerarlo serían cuarenta y
  tantos slugs en la URL que además habría que mantener al día. Lo que no existe se descarta en
  silencio —un slug renombrado dejaría la home vacía sin explicar por qué—, y al excluir entran
  también los negocios **sin categoría** (`IS NULL`), que con `NOT IN` a secas desaparecerían.
- `GET /public/businesses/{id}` — negocio por id (guid) **o** slug: `id,name,description,avatar,main_image,meta`.
- `GET /public/businesses/{id}/subcategories` — subcategorías del negocio: `[{id,name,sort_order}]`.
- `GET /public/businesses/{id}/products?subcategory=&page=&size=` — productos paginados
  (page **base 0**, size máx 50): `{items:[{id,title,description,image,subcategory_id,category_id,price_amount,price_currency,formatted_price}], total, page}`.
  `publishedOnly=true` fijo (no expone borradores).
- `GET /public/influencers?q=&page=&size=` — listado/búsqueda de creadores por nombre **o**
  username: `{items:[{id,name,username,avatar}], total, page, size}`.
- `GET /public/geostories?...`, `/public/categories?...`, `/public/influencers/{id}`.

**Búsqueda por texto** (`q` en negocios e influencers): `unaccent(lower(...)) LIKE` para que
"jamoneria" encuentre "Jamonería" — imprescindible en español. La extensión `unaccent` se activa en
`Version20260822160000`. Los negocios mantienen el orden por cercanía al filtrar por nombre
(buscando, lo de al lado interesa más que lo de la otra punta); los influencers van alfabéticos.
Sin índice: con 448 negocios y 69 creadores el scan es instantáneo — si crece, `pg_trgm` ya está
instalado para un índice GIN.

Controllers nuevos en `src/Products/Infrastructure/Controller/` (`ListBusinessSubcategoriesController`,
`ListBusinessProductsController`). Resuelven id-or-slug con `BusinessRepository::findById() ?? findBySlug()`.
Autowiring interface→impl (una sola impl por repo). Rutas registradas vía `products_controllers` en `config/routes.yaml`.

## Modelo de datos: productos y subcategorías

- `products` (`App\Products\Domain\Product`): `business_id`, `category_id?`, `subcategory_id?`,
  `title`, `slug`, `description`, `images` (JSON `[{url,order}]`), `price_amount` (**céntimos**),
  `price_currency` (ISO-4217), soft-delete (`deleted_at`), `published_at`. `uq(business_id, slug)`.
  `meta` (JSON) guarda lo que acompaña al producto sin ser del dominio; hoy, el **enlace
  directo**: `link_url` (http/https) + `link_action` (`buy|book|info`). Sale plano en la API
  (`link_url`, `link_action`) tanto en el listado público como en la gestión. No tiene
  columnas propias porque no es nuestro —no cobramos ni reservamos— y lo de esa clase llega
  de uno en uno: con columnas, cada añadido sería otra migración. Se guarda la **intención**,
  no el texto del botón: el rótulo lo pone la app en el idioma de quien mira. Quitar
  `link_url` (mandarlo vacío) borra también la acción.
- `product_subcategories` (`App\Products\Domain\ProductSubcategory`): subcategorías que **crea cada
  tienda** (`business_id`, `name`, `sort_order`). Distintas de `default_subcategories` (plantillas
  del sistema por categoría). `product.subcategory_id` → `product_subcategories.id`.
- Repos con las queries: `ProductRepository::findByBusinessPaginated(businessId, subcategoryId?, page, size, publishedOnly)`,
  `ProductSubcategoryRepository::findByBusinessId(businessId)`.

### Gestión del catálogo (`/api/businesses/{id}/…`)

Lo que usa la app para que una tienda mantenga su catálogo. Todo bajo `^/api`, así que
exige sesión, y **404 —nunca 403— para el negocio ajeno**: un 403 confirmaría que ese id
existe. La comprobación está en `App\Business\Application\ManagedBusinessFinder`, que
acepta id o slug y devuelve `null` tanto si no existe como si es de otro; lo usan también
`MyBusinessController` y `UploadBusinessImageController`.

| Método | Ruta | |
|---|---|---|
| `POST` | `/products` | Alta. Sólo `title` es obligatorio |
| `PATCH` | `/products/{id}` | **Sólo lo que venga en el cuerpo** |
| `DELETE` | `/products/{id}` | Baja lógica |
| `POST` | `/products/{id}/images` | Una imagen (`multipart`, campo `file`) |
| `DELETE` | `/products/{id}/images` | Quita una por su `url` |
| `GET` | `/subcategories` | Las suyas + las sugerencias de su categoría |
| `POST` | `/subcategories` | Crear (o adoptar una sugerencia: se manda su `name`) |
| `PATCH` | `/subcategories/{id}` | `name` y/o `sort_order` |
| `DELETE` | `/subcategories/{id}` | Borra y saca de ella a sus productos |

- **Categoría y localización se heredan del negocio.** El producto no tiene columnas para
  eso y no debe tenerlas: un producto suelto de su tienda no significa nada, y duplicarlas
  sólo abriría la puerta a que se contradigan. `category_id` sigue en la tabla —permite que
  una tienda salga en varias categorías de descubrimiento— pero no se toca desde aquí.
- **La descripción se guarda en HTML** (`description_format`, por defecto `html` en el alta
  desde la app): lo importado ya viene así, la app lo pinta con `HtmlText` y un editor de
  texto enriquecido escupe HTML. El enum admite markdown, pero elegirlo obligaría a
  convertir dos veces.
- **El slug no cambia al renombrar** (es ruta pública) y se numera al chocar
  (`vino-tinto-2`): la tabla exige `uq(business_id, slug)` y dos productos con el mismo
  nombre en una tienda es normal.
- **Las imágenes van de una en una** (máx. 4, `Product::MAX_IMAGES` → 409 `too_many_images`):
  así una foto que falle no tumba las otras y la app puede enseñar progreso. Al dar de baja
  un producto **los ficheros se quedan en el CDN** a propósito — la baja es lógica y se
  deshace desde la base; perder los binarios lo haría irreversible.
- ⚠️ **`ProductRepository::findById` no filtra las bajas lógicas.** Los controladores
  descartan a mano lo borrado; sin eso se podía editar un producto dado de baja y volver a
  borrarlo respondía 204 como si existiera.
- **La subcategoría se comprueba contra el negocio** (422 `unknown_subcategory`). Sin eso se
  podía colocar un producto en la subcategoría de otra tienda: no se filtraría mal en la
  ficha ajena —el listado público cruza negocio y subcategoría— pero dejaría el catálogo
  apuntando a algo que su dueño puede borrar cuando quiera.
- **Borrar una subcategoría no borra sus productos**: se les pone `subcategory_id = NULL`
  primero (`ProductRepository::clearSubcategory`, devuelto como `moved_products`) y luego se
  borra la fila. No hay clave ajena, así que al revés se quedarían apuntando a algo
  inexistente y desaparecerían de los filtros sin estar borrados.

### Sugerencias de subcategoría (`default_subcategories`)

Existen para que cada restaurante no tenga que teclear otra vez «Entrantes, Platos, Bebidas,
Postres» — es lo que hacía el alta de `../store-register`: chips de las de por defecto para
marcar, y un campo libre para las propias. Hoy sólo hay cuatro, todas de `category.hostelry`.

Adoptar una **copia el nombre** a una fila nueva de `product_subcategories`: la del negocio
es suya y la sugerencia no guarda de dónde salió. Por eso el listado las cruza **por nombre**
para no ofrecer las que ya tiene, y renombrar una adoptada vuelve a liberar la sugerencia.

⚠️ **`name` es una clave de i18n** (`subcategory.hostelry.starters`), igual que en las
categorías, y se guarda tal cual: así se lee en el idioma de quien mira y no en el de quien
la creó. Una escrita a mano se guarda literal; en la app las dos pasan por el mismo
`subcategoryLabel`, que traduce si hay clave y devuelve el texto si no.

El `icon` de las sugerencias **no se sirve**: los cuatro apuntan todavía a Cloudinary, que se
apaga al publicar la app. Los chips no llevan icono, pero si algún día se quieren hay que
moverlos a Bunny como el resto.

### Quién gestiona qué negocio

`business_managers` es lo único que mira el BFF: de ahí salen los `business_ids` de
`/api/auth/me`, y con ellos la app enseña «Gestión de negocios» en vez de «¿Tienes un
negocio?» y deja editar ficha y catálogo.

⚠️ **La importación desde Supabase no lo trajo todo.** En la app de Flutter la gestión no
salía de esa tabla sino de un array `managerOf` en el documento del usuario en Firestore,
y lo que no estaba además en Supabase se quedó fuera: **1.548 vínculos**, entre ellos los
de las cuentas de gestión (`goveoapp@gmail.com`, 424 tiendas). El síntoma es una cuenta
que lleva su negocio desde siempre y en la app ve «¿Tienes un negocio?».

```bash
php bin/console goveo:migrate:firestore:business-managers --dry-run
php bin/console goveo:migrate:firestore:business-managers
php bin/console goveo:migrate:firestore:business-managers --email=alguien@ejemplo.com
```

El usuario se busca **por correo** —el documento de Firestore lleva el UID de Firebase, que
no es el `users.id` de aquí— y el negocio por id, con el mismo UUID v5 de siempre. Sólo
inserta, así que los vínculos creados desde el alta web sobreviven, y descarta las tiendas
dadas de baja para no llenar la lista de fichas que no se pueden abrir.

Para tocarlo a mano: [`docs/sql/gestores-de-negocio.sql`](docs/sql/gestores-de-negocio.sql).
Después hay que **cerrar sesión y volver a entrar**: `business_ids` viaja en
`/api/auth/me`, que sólo se pide al arrancar.

`GET /api/account/businesses` está **paginado** (`page`, `size`, `q`) por lo mismo: con
cuatrocientas tiendas, devolverlas todas era una consulta por negocio y una lista que nadie
recorre. La búsqueda va con `unaccent`, como la pública. Ordena por **pendientes de validar y luego
por fecha de alta descendente**: las listas largas son las de las cuentas comerciales, que
dan de alta una tienda y luego se la traspasan al dueño, así que lo que acaban de crear es
justo a lo que vuelven. Por `business.created_at` y no por cuándo se vinculó — los vínculos
importados de Firestore comparten la fecha de la importación y ahí no ordenarían nada.

## Follows y likes

Dos tablas con la misma forma (id, user_id, destino, created_at) y endpoints idempotentes.
`user_id` es el **id local** (`users.id`), no el `sub` del JWT: el puente por email vive ahora
en `App\Users\Infrastructure\Service\LocalUserResolver` (la misma lógica seguía duplicada en
MeController, CreateGeoStoryController y GeoStoryOwnership — no se tocaron).

- `user_follows` (`App\Follows\Domain\Follow`): `target_type` (`business|influencer`) + `target_id`.
  `GET /api/follows` → `{business:[ids], influencer:[ids]}` · `POST /api/follows` `{type,id}` ·
  `DELETE /api/follows/{type}/{id}`. POST/DELETE devuelven `{following, followers}`.
- `geostory_likes` (`App\GeoStories\Domain\GeoStoryLike`): `geostory_id`.
  `GET /api/geostories/likes` → `{geostories:[ids]}` · `POST|DELETE /api/geostories/{id}/like`
  → `{liked, likes}`.

**Contadores** — dos reglas distintas a propósito:
- **Seguidores** (`FollowerCounter`): recuento real de `user_follows`, salvo que el negocio o
  influencer traiga `meta.followers`, que **manda**. Sirve para arrastrar la cifra heredada de
  Firestore y para fijar el número a mano en casos puntuales.
- **Likes** (`GeoStoryLikeCounter`): `geostories.likes` (acumulado del import, sin usuario) **más**
  los de `geostory_likes`. Aquí es suma y no override, para que dar/quitar like mueva el número
  ±1 sin perder la base. El feed lo resuelve en SQL con un `LEFT JOIN` agregado (las 3 queries de
  `DoctrineGeoStoryRepository`).

`GET /public/businesses/{id}` ahora incluye `followers` de primer nivel (además de `meta`), y
`GET /public/influencers/{id}` lo calcula con la misma regla.

## Cuenta (`/api/account`)

- `GET /api/account/businesses` — negocios que gestiona el usuario, con `name`, `avatar`,
  `main_image` y `verified` (sin `verified_at` = "Pendiente de validación"). Evita N llamadas al
  detalle desde la pantalla de gestión.
- `DELETE /api/account` — borrado de cuenta (requisito de App Store y Play Store). Marca
  `users.deleted_at` **y deshabilita al usuario en Keycloak** (`KeycloakService::disableUser`).
  Lo segundo no es opcional: sin ello el usuario volvería a entrar en el siguiente login y la
  cuenta seguiría viva de hecho. Si Keycloak falla responde `202` con `signInDisabled: false`,
  para no dejar al cliente sin respuesta con la marca ya puesta.

El purgado real del contenido queda para un proceso posterior. Los negocios **no** se borran: son
entidades comerciales que sobreviven a quien las gestionaba.

## Facturación: tarifas y códigos (`App\Billing`)

Sustituye a `/subspayment/*` del backend Firebase (`../goveo-serverless`). Allí Firestore era
la fuente de verdad y tres triggers (`subrate`, `subplan`, `coupon`) mantenían un espejo en
Stripe; aquí manda Postgres y la sincronización va en el propio caso de uso, así que esos
triggers no se migran.

**Tres tablas, tres responsabilidades distintas** — la clave del diseño es que el cupón antiguo
mezclaba las tres en un documento:

- `billing_plans` — la tarifa. `mode` (`free` · `paid` · `trial_then_paid`) dice **si se cobra**,
  y `trial_days` cuántos días son gratis antes del primer cargo (va tal cual al
  `trial_period_days` de Stripe: no hace falta cron). Sustituye a los flags `noCheckout` y
  `external` del cupón — que se cobre o no es propiedad del plan, nunca del código.
- `billing_discounts` — el descuento, espejo del Coupon de Stripe: `percent_off` **o**
  `amount_off_cents` (CHECK que obliga a exactamente uno), `duration` (`once`/`repeating`/`forever`).
  `duration` sustituye al flag `before`, que codificaba "el % sólo va al primer periodo" como
  booleano y había que reinterpretar en cada vista.
- `promo_codes` — lo que el usuario teclea. Tiene hasta tres efectos **independientes y
  combinables**: `plan_ids` (carga tarifas), `discount_id` (abarata) y `partner_id` (atribuye).

**`noChanges` desaparece.** Un código toca el precio cuando —y sólo cuando— tiene `discount_id`.
El flag antiguo era un negativo implícito que había que interpretar; ahora se lee en positivo con
`PromoCode::hasDiscount()` / `unlocksPlans()`. Un CHECK impide guardar un código sin ningún efecto.

**El precio se calcula en un solo sitio**: `PriceCalculator::quote(plan, discount?)` → `PriceQuote`.
En el registro antiguo la fórmula estaba escrita tres veces con expresiones distintas
(`BasicData`, `SelectPlan` y `RegisterResume` de `../store-register`) y no coincidían. La app pinta
los importes que recibe y **no hace aritmética**. Ojo con los tres importes, que son distintos:
`due_now_cents` (lo que se carga a la tarjeta al alta, 0 en trial), `first_charge_cents` (la primera
factura real, que en un trial llega después y **sí lleva el descuento**) y `recurring_cents` (el
precio completo cuando se acaban descuento y trial).

El IVA sale de `BILLING_TAX_PERCENT` (21). En el backend antiguo era un `tax_percent: 21.0`
hardcodeado, además de un parámetro que Stripe ya deprecó en favor de `tax_rates`.

`POST /api/registration/promo-codes/validate` `{code}` → `{valid, code:{unlocks_plans, has_discount,
partner_id}, discount, plans:[PriceQuote]}`. Devuelve **una lista** de tarifas: un código puede
desbloquear varias y entonces la app enseña selector; con una sola, la carga directamente. Los
códigos que sólo llevan descuento devuelven `plans: []` — la tarifa es la que ya hubiera elegido.
Inválido → 404 con `reason` (`unknown` · `inactive` · `expired` · `exhausted` · `no_active_plans`).

**`billed_externally`** (el antiguo `noCheckout`) es la excepción a lo anterior, y viene impuesta
por los datos: los 7 planes de los códigos `*ext` los comparte un código de pago —`Goveo Start
Anual` se vende cobrando (`goveo-start-anual24`) y sin cobrar (`goveo-start-anual24ext`)—, así que
el mismo plan se factura de las dos formas. No es un descuento: el precio y lo que debe el negocio
no cambian, sólo el canal de cobro. Por eso `due_now_cents` va a 0 pero `first_charge_cents` no.

**Import**: `goveo:migrate:coupons` (Firestore `coupons` → `promo_codes` + `billing_discounts`),
después de `goveo:migrate:billing-plans`. El mapeo campo a campo, con el inventario real de los 196
documentos y la justificación de lo que se tira, está en el docblock de `Version20260824150000`.

**Estado**: 58 planes (21 gratuitos), 180 códigos, 118 descuentos, 7 partners. Todos los planes en
`is_visible = false` a propósito: hoy toda tarifa se carga por código. Comprobado contra la pantalla
real del registro antiguo: `100mejores` → 369,00 € + 21 % = **446,49 €**.

Sin importar quedan **13 documentos de la red comercial** (`dele00x`, `canarias00x`, `dircomerc01`…):
no son códigos de alta sino fichas de comercial con comisión, cuenta de Stripe Connect y datos
personales. Los códigos que sí traen comisión (`232xxx`, 40 %) guardan `percentage`, `agency`,
`delegate` e `is_commercial` en `promo_codes.meta` para cuando se monte la tabla `commercials` y
los `transfer_data` de Connect — es una fase pendiente, no descartada.

`retail2025promomes` **se queda fuera a propósito**: su campo `plan` en Firestore es
`Qigtg7HVugYLsyA¡PbfT`, con un `¡` donde iría una `i`. Es corrupción del dato de origen y no se
corrige — buena parte de estos 180 códigos hay que borrarlos por desuso, así que no compensa
rescatar uno roto. El importador lo avisa en vez de adivinar.

### Stripe

SDK `stripe/stripe-php` (instalar con `--ignore-platform-req=ext-grpc`: `google/cloud-firestore`
pide esa extensión, que no está en la imagen — por eso el Firestore va por `transport: rest`).
`StripeClientFactory` con `STRIPE_SECRET_KEY`; la clave **de test** está en `.env.local`
(gitignorado), copiada de `../goveo-serverless/functions/config_local.js`. La de producción sólo
vive en `firebase functions:config:get stripe` — nunca en el repo. No se fija `stripe_version`:
manda la versión por defecto de la cuenta.

**Los objetos de Stripe ya existen y llevan el id del documento de Firestore**, tanto los Prices
como los Coupons. `billing_discounts.stripe_coupon_id` vino ya del import, pero el de los planes se
había perdido: `billing_plans.id` es el UUID v5 derivado y el id original no se guardaba.
`goveo:migrate:billing-plan-stripe-ids` lo recupera leyendo la colección `plans` y volviendo a
calcular el v5 (58/58 enlazados; `Majadahonda → x5pOjW67Vl3dBIhClp8p`, que es el Price real).

⚠️ **Ejecutarlo antes que cualquier sincronización con Stripe.** Sin él, un sync crearía un Price
nuevo por cada plan que ya tiene uno — inocuo en test, caro de deshacer en producción.

Ojo también con el entorno: la cuenta de test tiene sólo parte de los objetos (`goveo0424` sí,
`aupabeca1` y `basic25` no), así que el sync tendrá que crear lo que falte en cada entorno.

`goveo:stripe:sync` crea en Stripe lo que falte para los planes y descuentos activos. Es
idempotente y sustituye a los triggers `subrate`/`subplan`/`coupon`: los objetos apenas cambian y
espejar a la pasarela en cada escritura es una buena forma de duplicar cosas que nadie pidió.

⚠️ **Los ids de Price no son portables entre entornos, los de Coupon sí.** La API de Stripe deja
elegir el id al crear un Coupon pero no al crear un Price (la vieja de Plans sí lo permitía, y por
eso los heredados llevan el id de Firestore). Con la clave de test apuntando a una base con datos
de producción, crear los Prices que faltan **machacaría los ids buenos**. El comando lo detecta y
omite esos planes; hace falta `--overwrite-ids` para forzarlo. Si algún día hace falta trabajar con
los dos entornos sobre la misma base, la solución es una columna por entorno, no el flag.

Estado en la cuenta de test: 118/118 descuentos sincronizados, y los planes omitidos a propósito
(sólo 3 de los 37 de pago existen allí; los 34 restantes conservan su id de producción intacto).

### Alta de negocio — `POST /api/businesses`

[`CreateBusinessController`](src/Business/Infrastructure/Controller/CreateBusinessController.php).
Sustituye a `createGoveoStore` del panel Vue. Cuerpo: `name`, `email`, `phone?`, `website?`,
`booking_url?`, `category_id`, `address{formatted,lat,lng}`, `billing{company_name,tax_id,address,
email,phone}`, `promo_code`, `plan_id?`, `payment_method_id?`, `avatar?`, `main_image?`.

**La tarifa la decide el servidor.** El cliente manda el código y, si desbloquea varias, cuál
eligió; el precio y el cobro se resuelven aquí. En el panel antiguo el navegador mandaba el plan
**con su importe**, así que bastaba con tocar la petición para contratar 650 € por cero.

El negocio nace **sin validar** (`verified_at` nulo), como hacía `storestoconfirm`. Lo que no tiene
columna propia va a `meta` con las mismas claves que los 484 importados (`address`, `public_phone`,
`website_url`, `booking_url`, `is_whatsapp`, `is_appointment`) más `billing`.

[`SubscriptionCreator`](src/Billing/Application/SubscriptionCreator.php) toma uno de tres caminos,
y lo decide el plan y el código, nunca quien llama: **gratis** o **facturado fuera** no tocan Stripe
pero sí guardan la suscripción (es lo que dice a qué tarifa está el negocio); **de pago** y **trial**
crean cliente y suscripción, y ambos exigen tarjeta — en el trial también, porque sin ella Stripe no
puede cobrar al acabar la prueba. El descuento se manda como Coupon de Stripe y no restando importes
a mano, para que lo que se cobra salga de la misma fuente que lo que se enseñó.

**Orden de escritura: base de datos y después Stripe.** Al revés, un fallo al guardar dejaría al
negocio cobrado y sin existir; así, como mucho, queda un alta sin suscripción, que se ve y se
arregla. El **uso del código se apunta sólo al final**: si el cobro falla, el código sigue
disponible para reintentar.

Respuestas: `201` con `{id, slug, verified, subscription}`; `402 payment_method_required` o
`payment_failed` (el negocio **ya existe**, se devuelve su id); `422` con `fields` en validación,
o `invalid_promo_code` / `plan_not_unlocked_by_code`.

⚠️ **Pendiente**: reintentar el pago crea otro negocio, porque no hay endpoint para completar el
cobro de uno ya creado. Hasta que lo haya, un alta de pago fallida deja una ficha huérfana.

`BusinessSlugger` genera el slug desde el nombre, sin tildes y con sufijo numérico si ya existe
(`business.slug` es único y además ruta pública).

🐛 Arreglado de paso: `Business::setLocation` guardaba la geometría como array GeoJSON y reventaba
al flush con «Array to string conversion». Nunca se había disparado porque el import escribe SQL
directo y nada más creaba negocios por el ORM. Ahora usa EWKT, igual que `GeoStory::setLocation`,
que ya documentaba el mismo problema.

Falta: webhook de Stripe y la subida de imágenes.

### Imágenes del alta de negocio

Van a **Bunny Storage**, que es un servicio distinto del Bunny Stream de los vídeos (otra API y
otras credenciales: zona de almacenamiento, contraseña y hostname de la pull zone). No hay nada de
eso configurado todavía en ningún repo. Estructura acordada: `business/{businessId}/avatar` y
`business/{businessId}/main_image`.

Las 484 imágenes actuales siguen en `res.cloudinary.com` y **no se migran con esto**: si se cierra
la cuenta de Cloudinary, esos negocios se quedan sin imagen. Es una migración aparte, pendiente.

`interval = 'biannual'` del sistema antiguo (que se guardaba como `interval=month` +
`intervalCount=6` y se filtraba a mano en tres sitios) aquí es simplemente `interval_count = 6`.

## Tarifas 2026 y estado de cobro

Las tarifas de la landing (**TOP 3 · PLATINUM · PREMIUM · FREE**, cada una en mensual, semestral y
anual) se crean con `goveo:billing:seed-plans-2026`. Un producto por tarifa y un plan por
periodicidad, como lo modela Stripe. El semestral es `interval_count = 6` sobre meses, no un
intervalo propio. **ENTERPRISE no entra**: es a medida y no se contrata solo.

Las **58 tarifas heredadas** de Firestore (21 gratuitas + 37 de pago, de 9,95 € a 650 €) quedan en
`is_active = false` con `--retire-legacy`. No se borran: dejan de ofrecerse.

**Nadie estaba pagando.** Los 31 registros de `stores.paymentData.subscription` en Firestore están
desincronizados y no corresponden a cobros reales, así que los 448 negocios importados arrancan en
**FREE** (`goveo:billing:assign-free-plan`), con periodo abierto — una tarifa gratuita no vence, y
dejar `current_period_end` nulo evita que un futuro proceso de renovación se invente un cobro.

⚠️ Quedan **180 códigos y 118 descuentos** heredados apuntando a tarifas ya jubiladas (63 de ellos
a planes inactivos). No estorban, pero tampoco sirven ya: el canje por código se retiró.

Ver [docs/COMANDOS.md](docs/COMANDOS.md) para el orden de ejecución y las trampas de entorno.

## Alta de negocio desde la web (`/public/registration/business`)

Formulario **público** en la landing: lo rellena el propio comerciante o un comercial de Goveo, y
en el segundo caso lo único que cambia es que el enlace de pago se lo pasa él. No hay panel aparte
ni códigos de canje.

**El orden es el contrario al de la app: el negocio nace antes de pagar.**

1. Se crea la cuenta en Keycloak **sin contraseña** (`createUserPendingPassword`, con
   `UPDATE_PASSWORD` como acción obligatoria) y el usuario local. Si el email ya existe se
   reutiliza: dar de alta un segundo negocio con el mismo correo es normal, no un error.
2. Se crea el negocio **sin validar**, con ese usuario como `creator_id` y como gestor.
3. Se crea la suscripción en `pending_payment` con el Payment Link de Stripe, que lleva
   `goveo_business_id` en `metadata`.
4. El webhook confirma el cobro y la pasa a `active`.

⚠️ **Pagar no valida el negocio.** `verified_at` sigue nulo hasta que alguien de Goveo lo revise a
mano — verificado en la prueba de punta a punta.

⚠️ **El endpoint no acepta importes.** El precio sale siempre de la tarifa: siendo público, admitir
un importe pactado sería regalar suscripciones a cero.

⚠️ **Un alta fallida deja usuario en Keycloak.** La cuenta se crea antes que el enlace de pago, así
que si Stripe rechaza queda un usuario sin negocio. Es el precio de crear la cuenta al enviar el
formulario; habrá que limpiarlos o crearlos más tarde.

`business_subscriptions` guarda ahora el cobro pendiente: `stripe_payment_link_id`, `payment_url`,
`stripe_customer_id`, `stripe_price_id` y `amount_cents`. Los periodos son **nullable**: una
suscripción sin pagar no tiene periodo, y poner «ahora» sería inventarse una fecha que luego alguien
leería como real.

### Correo de bienvenida y contraseña inicial

El alta web crea la cuenta **sin contraseña**, así que hace falta un correo que le permita ponerla.

**Cuándo sale**: con tarifa de pago, cuando el webhook confirma el cobro; con tarifa gratuita, en el
propio alta. Nunca antes — quien abandona en la pasarela no debe recibir un «bienvenido».

**Qué dice** ([`WelcomeMailer`](src/Account/Application/WelcomeMailer.php)): crear la contraseña como
**acción única** (competir con otros enlaces sólo baja la probabilidad de que la cree), que la ficha
**está pendiente de validación** —dicho aquí y no descubierto después, que es la pregunta que llega a
soporte— y qué puede ir haciendo mientras. **No lleva importes**: de eso se encarga el recibo de
Stripe, y repetirlos aquí invita a discutir el cobro en el correo equivocado.

HTML con estilos en línea y tablas, que es lo único que respetan los clientes de correo.

**El token** (`password_setup_tokens`): 32 bytes aleatorios, **guardado como hash SHA-256** — en claro
sería una llave para entrar en cualquier cuenta recién creada. Un solo uso y 7 días. Se marca usado
**antes** de tocar Keycloak: si algo falla después se pide otro correo, mientras que al revés un
reintento podría cambiar una contraseña ya establecida.

`POST /public/account/initial-password` `{token, password}` → fija la contraseña y **quita
`UPDATE_PASSWORD`** de Keycloak, que si no la volvería a pedir en el primer login justo después de
elegirla. Público por necesidad: el usuario aún no puede autenticarse. Devuelve el **mismo 410** para
token inexistente, caducado o usado: distinguirlos diría a quien pruebe enlaces cuáles existieron.

Página: [`/bienvenida?token=…`](../goveo-astro/src/pages/bienvenida.astro), con
`referrer: no-referrer` porque el token viaja en la URL. El token **no se valida al renderizar**: eso
lo quemaría con que un previsualizador de enlaces del cliente de correo lo abriera.

**En desarrollo todo el correo lo captura Mailpit** (http://localhost:8025), incluido el de Keycloak
—el realm ya apunta ahí—. Es un sumidero: acepta cualquier dirección y no entrega nada fuera, así que
para probar vale `loquesea@ejemplo.com` y no hace falta un dominio real.

### Cuando el correo ya tiene cuenta

Antes el alta reutilizaba esa cuenta **en silencio**: cualquiera podía escribir el correo de otro y
colgarle un negocio sin su permiso, y encima le llegaba un enlace de contraseña que no había pedido.

Ahora el alta responde **409 `account_exists`** y el formulario pide la contraseña; con ella inicia
sesión y reenvía el alta con `Authorization`. Entonces el negocio se cuelga del usuario **del
token**, ignorando el email del formulario: si no, bastaría con entrar como uno y reclamar el correo
de otro.

**Se comprueba al enviar, no antes.** Un endpoint de «¿existe este email?» dejaría comprobar
direcciones sueltas hasta dar con las que tienen cuenta; así sólo se sabe de la dirección que ya has
escrito en un formulario entero.

El correo de bienvenida **no sale** si la cuenta ya tenía contraseña: `hasPendingPasswordSetup()`
lo pregunta a Keycloak, que es quien lo sabe. El negocio se marca igual como resuelto para que no
aparezca luego en `goveo:account:resend-welcome`.

### Imágenes: Bunny Storage

`POST /api/businesses/{id}/images/{avatar|main_image}` — multipart, sólo para gestores del negocio.
[`BunnyStorageService`](src/Shared/Infrastructure/Storage/BunnyStorageService.php).

**Distinto del Bunny Stream de los vídeos**: otra API, otras credenciales, otro CDN. Zona
`goveo-storage`, CDN `goveo.b-cdn.net`. La contraseña va **sólo en `.env.local`** — ni siquiera
vacía en `.env`, que se carga como `env_file` y taparía a la de allí.

Ruta: `business/{businessId}/{slot}-{timestamp}.{ext}`. Una carpeta por negocio (borrar su rastro es
borrar una carpeta) y **marca de tiempo en el nombre** para saltarse la caché del CDN: sin ella,
cambiar la portada dejaría la vieja a la vista durante horas.

- **El formato se comprueba por el contenido** (`finfo`), no por la extensión: un `.jpg` puede ser
  cualquier cosa. Sólo JPG, PNG y WebP, máximo 8 MB.
- **La anterior se borra después de guardar la nueva**, no antes: al revés, un fallo al guardar
  dejaría al negocio apuntando a una imagen que ya no existe. Sólo se borra si la URL es de nuestro
  CDN — las heredadas de Cloudinary no se tocan.
- **Va aparte del `PATCH`** de la ficha: es lo único que viaja como multipart, y meterlo ahí
  obligaría a mandar el formulario entero así y a cargar el binario en memoria en cada guardado.
- El endpoint **ya asocia la URL al negocio**, así que la app no debe reenviarla en el PATCH:
  guardarla dos veces dejaría un fichero huérfano por intento.

⚠️ Las 484 imágenes heredadas siguen en `res.cloudinary.com`. Esto no las migra: si se cierra esa
cuenta, esos negocios se quedan sin imagen.

### Validación manual: `business.verified_at`

**Es la única columna que decide si un negocio se publica.** Con fecha sale en el feed, el mapa y
la búsqueda; sin ella sólo lo ve su dueño en «Gestión de negocios», marcado como pendiente.

⚠️ Hasta ahora `verified_at` **no filtraba nada**: sólo se usaba para pintar el estado en el listado
del usuario, así que un negocio recién dado de alta habría salido publicado. El filtro está ahora en
`DoctrineBusinessRepository::findNearby()`, que es por donde pasan feed, mapa y búsqueda. Los 448
negocios importados ya venían con `verified_at`, así que filtrar no escondió ninguno.

`goveo:business:verify` mientras no haya panel:

```
goveo:business:verify                      # lista los pendientes con dueño, tarifa y estado de pago
goveo:business:verify bar-manolo           # valida
goveo:business:verify bar-manolo --revoke  # retira la validación
goveo:business:verify --all                # todos de golpe (pregunta antes)
```

**El detalle `/public/businesses/{id}` es accesible aunque no esté validado, y es deliberado**: el
dueño necesita poder abrir su ficha para ver cómo va quedando mientras la configura, y compartirla
con quien le esté ayudando. Lo que no hace un negocio sin validar es **aparecer en ningún listado**:
no se descubre, sólo se llega con el enlace.

🐛 Arreglado de paso: `/public/businesses/{slug}` devolvía **500 para cualquier slug**, no sólo para
los pendientes. Las rutas aceptan id o slug y probaban primero por id, con lo que Postgres reventaba
con «invalid input syntax for type uuid» antes de llegar a la segunda opción. `findById()` ahora
devuelve null si el texto no es un UUID, lo que arregla de una vez el detalle, los productos y las
subcategorías.

### El formulario (`goveo-astro`)

Vive en [`src/pages/alta.astro`](../goveo-astro/src/pages/alta.astro) →
[`RegisterBusiness`](../goveo-astro/src/features/registration/RegisterBusiness.tsx), isla React con
`client:load`. **No usa el layout `Base`**: es un embudo, y el menú y la barra inferior sólo dan
sitios a los que irse antes de terminar.

Los query params se leen **en el servidor** y se pasan como props, así que el formulario ya se pinta
relleno en el primer render, sin parpadeo de campos vacíos. Ver
[docs/ENLACES-LANDING.md](docs/ENLACES-LANDING.md) para el listado de enlaces.

La validación es la **misma que la de la app** (incluida la letra de control de NIF/NIE/CIF): es el
mismo negocio y el mismo endpoint, así que una regla distinta aquí acabaría en un rechazo del BFF
que el usuario no sabría interpretar.

⚠️ **Sin imágenes todavía.** El alta de la app pide portada y logo, pero aquí no se piden porque la
subida a Bunny Storage sigue pendiente de credenciales. Se añaden desde la app una vez dentro.

Las traducciones de categoría (`category.*`) se copiaron a
[`src/i18n/resources/{es,en}.ts`](../goveo-astro/src/i18n/resources/): el BFF devuelve la clave, no
el nombre, y la web no tenía esas claves.

### 🪤 `.env.local` no funciona por sí solo en este proyecto

`docker-compose.yml` carga `.env` con `env_file`, así que **todo lo de `.env` entra como variable de
entorno real del contenedor** — y Symfony no sobrescribe variables reales con `.env.local`. Una
clave declarada vacía en `.env` tapa la de `.env.local` y el servicio arranca sin credenciales,
con un error que no apunta a la causa («STRIPE_SECRET_KEY no está configurada» teniéndola puesta).

Arreglado añadiendo `.env.local` al `env_file` con `required: false`. Si añades una variable nueva,
o va en `.env` con su valor, o va **sólo** en `.env.local`; declararla vacía en `.env` la rompe.

## ⚠️ Origen de datos e IDs deterministas (UUID v5)

Los datos migran desde **Firestore** (colecciones `stores`, `geoproducts`) y Supabase. Los ids
del BFF se derivan **deterministamente** del id de Firestore con **UUID v5** y namespace
`7e4d3c2a-1b5f-4e8d-9a6c-0f2e1d3b5a7c` (constante `NS` en los comandos). Así casan entre tablas:
- `business_id = uuidv5(NS, storeDocId)`
- `product.subcategory_id = uuidv5(NS, subCat.id)` y `product_subcategories.id = uuidv5(NS, subCat.id)` → **mismo id**.

Las subcategorías de tienda viven **dentro del doc de `stores`** en Firestore, campo
`subCategories: [{custom, id, name}]` (el nombre lo pone la propia tienda). NO hay tabla de
subcategorías en Supabase.

## Comandos de import (Firestore → Postgres)

- `goveo:migrate:products` — `geoproducts` → `products`.
- `goveo:migrate:products:supabase` — Supabase `geoproducts` → `products`.
- `goveo:migrate:subcategories` — `stores.subCategories` → `product_subcategories`
  (`--store=<firestoreId>` para una sola tienda, `--dry-run`).

Los imports de productos ahora marcan `->publish()` (los items del catálogo son productos vivos en
la app origen). Para publicar los **ya importados** antes de este cambio, un one-off SQL:
`UPDATE products SET published_at = now() WHERE published_at IS NULL AND deleted_at IS NULL;`

Ejemplo de tienda con datos completos: **Jamonería López Pascual** — Firestore `2vyvumaqnCCVqE5xBoaE`,
business `c91efd77-dc56-5c54-8c52-38b9aaed3f1a` (251 productos, 9 subcategorías).

## Subida de vídeos a Bunny Stream (GeoStories)

Patrón **proxy** (como anyclazz): el cliente NO habla con Bunny; sube el fichero por multipart al
BFF y el BFF lo sube a Bunny. Las URLs (`play_720p.mp4`, `thumbnail.jpg`) son deterministas desde
el GUID → se fijan al crear; el estado lo lleva `geostories.status` (`processing→ready→failed`).

- **`GeoStory`**: columnas nuevas `status` (default `ready`) y `provider_video_id` (GUID Bunny).
  Métodos `markProcessing/markReady/markFailed`. `findFeed` muestra `processing` solo en consultas
  con filtro de owner (perfil); los feeds de descubrimiento exigen `status='ready'`.
- **`BunnyVideoService`** (`src/GeoStories/Infrastructure/Service/`): `uploadVideo(file,title)` →
  `POST /library/{id}/videos` (crea GUID) + `PUT` binario, header `AccessKey`.
- **`POST /api/geostories`** (auth JWT, multipart: `video,title,description,categoryId,businessId?,lat?,lng?,started_at?,ended_at?`):
  resuelve influencer (`InfluencerRepository::findByUserId`) o negocio (`BusinessManagerRepository::findByUserId`);
  negocio → localización de la propia tienda; influencer → lat/lng del request. Crea en `processing` + `publish()`.
- **Vigencia** (`StorySchedule`, `src/GeoStories/Infrastructure/Service/`): dos categorías caducan.
  **Eventos** piden `started_at` (422 `event_start_required`) y aceptan `ended_at` opcional; sin él,
  tres horas desde el inicio (`GeoStory::EVENT_DEFAULT_DURATION`). **Noticias** (`news`) empiezan al
  publicarse y caducan a la semana (`NEWS_LIFESPAN`), sin preguntar nada — y reeditarlas **no** las
  renueva, que sería la forma de que no caducaran nunca. El resto se queda sin fechas. El fin por
  defecto **se escribe al guardar**, no se deriva al leer: así la fecha que decide si algo sigue vivo
  se puede mirar en la base en vez de repetir la cuenta en el feed, el detalle y la app. Las fechas
  se validan **antes** de subir a Bunny: al revés, una fecha mal dejaría el vídeo colgado allí sin
  fila que lo apunte.
- ⚠️ **La caducidad está apagada** (`GEOSTORY_EXPIRY_ENABLED=0`): hoy no desaparece nada por
  fecha. Es un interruptor de entorno y no una constante para poder encenderla y apagarla **sin
  publicar app** — la regla vive aquí. Las fechas se guardan igual esté como esté. No afecta a lo
  borrado, lo no publicado ni lo pendiente de validar, que siguen fuera pase lo que pase. Lo de
  abajo es lo que rige **con el interruptor encendido**.
- **Quién sigue vivo** (`findFeed`): se aplica **siempre**, no sólo en su feed — un evento terminado
  tampoco sigue en el perfil de quien lo subió. Lo único que cambia entre sitios es la antelación:

  | | Feed | Perfil del dueño |
  |---|---|---|
  | Eventos | `started_at - 1 mes` → `ended_at` | hasta `ended_at` (sin antelación: su catálogo, no un descubrimiento) |
  | Noticias | `started_at` → `ended_at` | igual |

  Los eventos comparan contra columnas: los importados ya llevan sus dos fechas escritas
  (`Version20260903170000`). Las noticias conservan el respaldo a `created_at` porque quedan 71
  importadas sin fechas. **El detalle por id no caduca**: un enlace compartido de un evento pasado
  sigue abriendo el vídeo.
- **`POST /api/v1/webhooks/bunny/video-status`** (público, `?secret=BUNNY_WEBHOOK_SECRET`): payload
  `{VideoGuid,Status,EventType}` → busca por `provider_video_id`, mapea (`Status 4`/`video.encoded`→ready,
  `5`/`video.failed`→failed) y persiste. **Configurar esta URL en el panel de Bunny.**
- **Reconcile (fallback en lectura)**: `ListGeoStoriesController`, en consultas **owner-scoped**
  (`businessId`/`influencerId`), reconsulta a Bunny (`BunnyVideoService::getVideoStatus`) cualquier item
  en `processing` y lo pasa a ready/failed si ya terminó; si algo cambia, re-consulta el feed. El webhook
  sigue siendo el camino principal; esto **auto-repara** cuando el webhook no llega (p.ej. túnel de dev caído).
- **`location` = string EWKT** (`SRID=4326;POINT(lng lat)`): el tipo PostGIS de Doctrine bindea con
  `ST_GeomFromEWKT(?)`, así que **nunca** se le pasa un array GeoJSON (peta con *Array to string conversion*).
  Al leer, `ST_AsEWKT` ya devuelve string (por eso `Business::getLocation()` sirve tal cual).
- **Requiere `symfony/mime`** (ya en `composer.json`): `UploadedFile::getMimeType()` lo necesita; sin él la
  subida da 500 *"You cannot guess the mime type…"*.
- **`/api/auth/me`** ahora devuelve `influencer_id` y `business_ids` (rol del usuario para el flujo de subida).
- **Env** (en `.env`, vacías — rellenar con las credenciales de la library Bunny de goveo):
  `BUNNY_API_KEY`, `BUNNY_LIBRARY_ID`, `BUNNY_CDN_HOSTNAME_VIDEOS`, `BUNNY_WEBHOOK_SECRET`.

## Campos nuevos ↔ migración / import (checklist)

Al añadir un campo/dato nuevo, dejarlo reflejado aquí **y** en su migración o comando de import. Verificar
con `doctrine:schema:validate --skip-sync` (mapping) + `doctrine:migrations:diff` (no debe generar ningún
`ALTER TABLE … ADD COLUMN`; el diff sí produce ruido de índices/PostGIS `tiger`/`topology` — ignorar).

| Campo / dato | Esquema | Migración / import |
|---|---|---|
| `geostories.status` | columna nueva | `Version20260812100743` (`ADD status DEFAULT 'ready'`) |
| `geostories.provider_video_id` | columna nueva | `Version20260812100743` |
| `geostories.description` | **preexistente** | — (ya en el esquema; el flujo de subida solo la rellena) |
| `categories.mode` | columna nueva | `Version20260812113346` (`ADD mode` + backfill por slug) · import: `ImportCategoriesFromSupabaseCommand` (`modeForSlug` en INSERT y `ON CONFLICT … mode = EXCLUDED.mode`) |
| `products` / `product_subcategories` / `default_subcategories` | **tablas preexistentes** | — (mapeadas por entidades; datos vía `goveo:migrate:*`) |
| subcategorías de tienda (nombres) | — | `goveo:migrate:subcategories` (`stores.subCategories` → `product_subcategories`, id UUID v5) |

`mode` se deriva **por slug** (no por id) en migración e import → robusto contra los datos reales, sin
hardcodear UUIDs. `is_whatsapp`/`public_phone`/`profile_name` **no son columnas**: salen de `meta` (JSON) y
`profile_name` se calcula en `MeController` — no requieren migración.
