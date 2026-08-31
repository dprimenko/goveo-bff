# Migración de datos a un entorno nuevo

Qué ejecutar, en qué orden y cómo comprobar que salió bien, al levantar **producción**
o **demo** desde cero.

Los dos entornos ejecutan **la misma secuencia**: lo que cambia son las credenciales,
no los pasos. Por eso está en un solo documento — dos runbooks separados acabarían
divergiendo, y el que se quedaría atrás sería justo el que menos se usa.

---

## Antes de empezar

**1 · Variables de las fuentes** en el panel del entorno:

```
SUPABASE_DATABASE_URL=postgresql://…      # el histórico
FIREBASE_PROJECT_ID=goveoapp-fd8b1
FIREBASE_CREDENTIALS=/var/www/html/config/firebase-credentials.json
```

Redesplegar después de añadirlas, o el contenedor no las verá.

**2 · El fichero de credenciales de Firebase.** Está en `.gitignore`, así que no viaja
en la imagen. Hay que copiarlo **después** del redespliegue — un despliegue recrea el
contenedor y se lo lleva por delante:

```bash
# desde tu máquina
scp config/firebase-credentials.json root@SERVIDOR:/tmp/

# en el servidor, dentro de la carpeta del compose
docker compose cp /tmp/firebase-credentials.json php:/var/www/html/config/firebase-credentials.json
rm /tmp/firebase-credentials.json
```

Sólo hace falta para productos y subcategorías. Si no los necesitas, sáltate este paso
y también los comandos marcados como *(Firestore)*.

**3 · Las migraciones de esquema ya están aplicadas**: las ejecuta el entrypoint en cada
arranque. No hay que hacer nada.

---

## Secuencia

Todo desde la carpeta del compose del entorno. En `screen` o `tmux` si la conexión
puede cortarse: el paso de negocios y el de productos tardan minutos.

```bash
# 1 · El grueso del histórico, desde Supabase.
#     Orden interno: category_types → categories → mapping → subcategorías por
#     defecto → partners → users → influencers → business → managers → geostories.
docker compose exec php php bin/console goveo:migrate:all --skip-firebase

# 2 · Productos y subcategorías de tienda (Firestore)
docker compose exec php php bin/console goveo:migrate:products
docker compose exec php php bin/console goveo:migrate:subcategories

# 3 · Las tarifas nuevas, y publicarlas en Stripe
docker compose exec php php bin/console goveo:billing:seed-plans-2026
docker compose exec php php bin/console goveo:stripe:sync
```

`goveo:stripe:sync` **sin `--overwrite-ids`**. Esa bandera existe por un incidente real:
una sincronización recreó un producto y pisó el id de otro entorno. Sólo se usa a
sabiendas.

### Lo que NO hace falta ejecutar

- **`goveo:business:backfill-location`** — el import ya calcula la ubicación desde el
  `geohash` de `meta`. El comando sigue existiendo como reparación por si algún negocio
  se queda sin ubicar.
- **`goveo:business:verify --all`** — el import ya marca el histórico como validado:
  eran negocios publicados en la app anterior. La validación manual es para las altas
  nuevas.
- **`goveo:migrate:coupons`** — los 180 cupones antiguos van con las tarifas jubiladas.
  Con las tarifas 2026 no se usan.

---

## Comprobación

```bash
docker compose exec php php bin/console dbal:run-sql \
  "select (select count(*) from categories) categorias,
          (select count(*) from business) negocios,
          (select count(location) from business) ubicados,
          (select count(verified_at) from business) validados,
          (select count(*) from influencers) influencers,
          (select count(*) from geostories) geostories,
          (select count(*) from products) productos,
          (select count(*) from billing_plans) tarifas"
```

Referencia (agosto 2026 — el origen sigue vivo, así que crecerán):

| | esperado |
|---|---|
| categorías | ~53 |
| negocios | ~487, **todos ubicados y validados** |
| influencers | ~72 |
| geostories | ~583 |
| productos | ~13.000 |
| tarifas | **10** (si hay 68, entraron las antiguas) |

`ubicados` y `validados` deben igualar a `negocios`. Si no, los que falten **no
aparecen** en el listado, el mapa ni la búsqueda: los tres exigen `location IS NOT NULL`
y `verified_at IS NOT NULL`.

Y desde fuera, que es la prueba de verdad:

```bash
curl -s "https://api-ENTORNO.goveo.app/public/businesses?page=0&size=1" | head -c 200
curl -s "https://api-ENTORNO.goveo.app/public/billing/plans" | head -c 200
```

---

## En qué se diferencian los entornos

Sólo en credenciales:

| | Producción | Demo |
|---|---|---|
| Compose | `docker-compose.prod.yml` | `docker-compose.demo.yml` |
| Stripe | live (`sk_live_…`) | test (`sk_test_…`) |
| Bunny | `goveo-storage` · librería 497837 | `test-goveo-storage` · librería 740458 |
| Correo | SMTP real | mailpit |
| Fuentes | las mismas | las mismas |

Los dos leen del **mismo** Supabase y del mismo Firestore, que son de sólo lectura en
este proceso. Migrar demo no toca nada de producción.

Los `stripe_price_id` **no** son portables: cada entorno crea los suyos al ejecutar su
`goveo:stripe:sync` contra su propia cuenta. No se copian a mano.


---

## Imágenes que ya no existen en Cloudinary

Parte de las imágenes heredadas fueron borradas de Cloudinary hace tiempo: su URL
devuelve 404 y no hay nada que migrar. Reintentar no las recupera.

```bash
# Da de baja los productos cuyas imágenes hayan desaparecido **todas**.
# Es baja lógica (`deleted_at`): se puede revertir con un UPDATE.
docker compose exec php php bin/console goveo:media:migrate-cloudinary --retire-missing
```

Sólo se da de baja cuando **ninguna** de sus imágenes sobrevive: un producto con
cuatro fotos y una muerta sigue siendo un producto bueno, y se queda con las que sí
se pudieron mover.

El resto de tablas nunca se tocan por esto: un negocio sin avatar sigue siendo un
negocio.

### Y las de más de 8 MB

`BunnyStorageService` rechaza subidas por encima de ese tamaño, que es un límite
pensado para lo que sube un usuario desde el formulario. La migración lo ignora a
propósito: son imágenes que ya existían, no hay a quién pedirle que las reduzca, y
el Optimizer las sirve redimensionadas igualmente.
