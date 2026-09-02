-- Quién gestiona qué negocio.
--
-- `business_managers` es lo único que mira el BFF para decidirlo: de ahí salen
-- los `business_ids` de `/api/auth/me`, y con ellos la app enseña «Gestión de
-- negocios» en vez de «¿Tienes un negocio?», y deja editar la ficha y el
-- catálogo. Sin fila aquí, la cuenta no gestiona nada por mucho que el negocio
-- sea suyo.
--
-- La tabla se importó de Supabase tal cual. Lo que en la app de Flutter se
-- resolvía por la relación de Firestore no llegó, así que hay cuentas que
-- gestionaban su tienda allí y aquí aparecen sin nada.

-- ── Qué gestiona una cuenta ────────────────────────────────────────────────
SELECT u.email,
       b.name AS negocio,
       b.slug,
       b.verified_at IS NOT NULL AS validado,
       bm.created_at
  FROM business_managers bm
  JOIN users u    ON u.id = bm.user_id
  JOIN business b ON b.id = bm.business_id
 WHERE lower(u.email) = 'goveoapp@gmail.com'
   AND bm.deleted_at IS NULL
 ORDER BY bm.created_at;

-- ── Encontrar el negocio que se quiere vincular ────────────────────────────
SELECT id, name, slug, verified_at IS NOT NULL AS validado
  FROM business
 WHERE deleted_at IS NULL
   AND name ILIKE '%parte del nombre%'
 ORDER BY name
 LIMIT 20;

-- ── Vincular ───────────────────────────────────────────────────────────────
-- Un negocio admite varios gestores, así que esto suma, no sustituye.
INSERT INTO business_managers (user_id, business_id)
SELECT u.id, b.id
  FROM users u, business b
 WHERE lower(u.email) = 'goveoapp@gmail.com'
   AND b.slug = 'SLUG-DEL-NEGOCIO'
ON CONFLICT (user_id, business_id) DO NOTHING;

-- Varios de una vez.
INSERT INTO business_managers (user_id, business_id)
SELECT u.id, b.id
  FROM users u, business b
 WHERE lower(u.email) = 'goveoapp@gmail.com'
   AND b.slug IN ('slug-uno', 'slug-dos')
ON CONFLICT (user_id, business_id) DO NOTHING;

-- ── Desvincular ────────────────────────────────────────────────────────────
-- Baja lógica: la fila se queda, así que se puede deshacer poniendo NULL.
UPDATE business_managers bm
   SET deleted_at = NOW(), updated_at = NOW()
  FROM users u, business b
 WHERE u.id = bm.user_id AND b.id = bm.business_id
   AND lower(u.email) = 'goveoapp@gmail.com'
   AND b.slug = 'SLUG-DEL-NEGOCIO';

-- ⚠️ Después de tocar esto, la app **no se entera sola**: `business_ids` viaja
-- en `/api/auth/me`, que se pide al arrancar. Hay que cerrar sesión y volver a
-- entrar, o reiniciar la app.
