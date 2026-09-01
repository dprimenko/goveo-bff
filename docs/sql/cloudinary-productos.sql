-- Detalle de los productos que siguen apuntando a Cloudinary.
--
-- `publicado` importa tanto como `borrado`: un producto sin `published_at` no se
-- ve en la app aunque no esté dado de baja.
SELECT count(*)                                          AS total,
       count(*) FILTER (WHERE deleted_at IS NOT NULL)     AS dados_de_baja,
       count(*) FILTER (WHERE published_at IS NULL)       AS sin_publicar,
       count(*) FILTER (WHERE deleted_at IS NULL
                          AND published_at IS NOT NULL)   AS visibles_en_la_app
  FROM products
 WHERE images::text LIKE '%cloudinary%';
