-- A qué negocios pertenecen los productos que quedan, y si el negocio sigue vivo.
-- Si la mayoría cuelga de negocios borrados, no hay nada que recuperar.
SELECT b.name,
       b.deleted_at IS NOT NULL AS negocio_borrado,
       count(p.id)              AS productos_pendientes
  FROM products p
  JOIN business b ON b.id = p.business_id
 WHERE p.images::text LIKE '%cloudinary%'
 GROUP BY b.name, b.deleted_at
 ORDER BY productos_pendientes DESC
 LIMIT 15;
