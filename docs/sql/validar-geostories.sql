-- Validación de vídeos a mano, mientras no exista el backoffice.
--
-- Un vídeo sin `verified_at` no sale en los feeds ni en el perfil que visita un
-- tercero: sólo lo ve su autor, con el aviso de «pendiente de validación». Nada
-- lo valida solo —`GeoStory::verify()` no la llama ningún endpoint todavía—, así
-- que hasta que haya panel, lo que se suba se queda esperando aquí.

-- ── Qué hay pendiente ───────────────────────────────────────────────────────
-- `url` sirve para verlo antes de aprobarlo: se abre en el navegador.
SELECT g.id,
       g.title,
       COALESCE(i.name, b.name) AS autor,
       CASE WHEN g.influencer_id IS NOT NULL THEN 'influencer' ELSE 'negocio' END AS tipo,
       g.status,
       g.created_at,
       g.url
  FROM geostories g
  LEFT JOIN influencers i ON i.id = g.influencer_id
  LEFT JOIN business    b ON b.id = g.business_id
 WHERE g.verified_at IS NULL
   AND g.deleted_at IS NULL
   AND g.published_at IS NOT NULL
 ORDER BY g.created_at DESC;

-- Sólo el recuento, para mirarlo de pasada.
SELECT count(*) AS pendientes
  FROM geostories
 WHERE verified_at IS NULL
   AND deleted_at IS NULL
   AND published_at IS NOT NULL;

-- ── Aprobar ────────────────────────────────────────────────────────────────
-- Uno concreto.
UPDATE geostories
   SET verified_at = NOW(),
       updated_at  = NOW()
 WHERE id = '00000000-0000-0000-0000-000000000000'
   AND verified_at IS NULL;

-- Varios de una vez.
UPDATE geostories
   SET verified_at = NOW(),
       updated_at  = NOW()
 WHERE id IN (
         '00000000-0000-0000-0000-000000000000',
         '11111111-1111-1111-1111-111111111111'
       )
   AND verified_at IS NULL;

-- Todo lo de un autor. Ojo: aprueba a ciegas lo que tenga pendiente, incluido lo
-- que suba mientras se ejecuta.
UPDATE geostories g
   SET verified_at = NOW(),
       updated_at  = NOW()
  FROM influencers i
 WHERE i.id = g.influencer_id
   AND i.name = 'NOMBRE DEL INFLUENCER'
   AND g.verified_at IS NULL
   AND g.deleted_at IS NULL;

-- ── Deshacer ───────────────────────────────────────────────────────────────
-- Devuelve un vídeo a pendiente; deja de verse salvo para su autor. Es la forma
-- de retirar algo publicado sin borrarlo.
UPDATE geostories
   SET verified_at = NULL,
       updated_at  = NOW()
 WHERE id = '00000000-0000-0000-0000-000000000000';
