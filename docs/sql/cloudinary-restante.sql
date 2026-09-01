-- Qué queda en Cloudinary y en qué estado.
--
-- Sirve para decidir si lo que falta son productos ya dados de baja —en cuyo
-- caso da igual— o registros vivos que sí habría que recuperar.

-- 1 · Reparto por tabla, separando lo borrado de lo vivo.
SELECT 'business'    AS tabla,
       count(*) FILTER (WHERE deleted_at IS NULL)     AS vivos,
       count(*) FILTER (WHERE deleted_at IS NOT NULL) AS borrados
  FROM business
 WHERE (avatar LIKE '%cloudinary%' OR main_image LIKE '%cloudinary%')
UNION ALL
SELECT 'products',
       count(*) FILTER (WHERE deleted_at IS NULL),
       count(*) FILTER (WHERE deleted_at IS NOT NULL)
  FROM products
 WHERE images::text LIKE '%cloudinary%'
UNION ALL
SELECT 'geostories',
       count(*) FILTER (WHERE deleted_at IS NULL),
       count(*) FILTER (WHERE deleted_at IS NOT NULL)
  FROM geostories
 WHERE thumbnail LIKE '%cloudinary%'
UNION ALL
SELECT 'influencers', count(*), 0 FROM influencers WHERE avatar      LIKE '%cloudinary%'
UNION ALL
SELECT 'categories',  count(*), 0 FROM categories  WHERE image       LIKE '%cloudinary%'
UNION ALL
SELECT 'users',       count(*), 0 FROM users       WHERE profile_image LIKE '%cloudinary%'
UNION ALL
SELECT 'partners',    count(*), 0 FROM partners    WHERE meta::text  LIKE '%cloudinary%'
UNION ALL
SELECT 'subcategorias', count(*), 0 FROM default_subcategories WHERE icon LIKE '%cloudinary%'
 ORDER BY 1;
