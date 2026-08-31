# Enlaces de la landing al alta

El formulario vive en **`https://goveo.app/alta`**. Todo lo preconfigurable
viaja por query params, así que un enlace se pega tal cual en un botón y el
formulario arranca con lo que le llegue.

## Botones de tarifa

Un enlace por tarifa y periodicidad. Es lo único imprescindible: el resto de
parámetros son opcionales.

| Tarifa | Botón | Enlace |
|---|---|---|
| TOP 3 | Mensual | `https://goveo.app/alta?plan=top3-mensual` |
| | Semestral | `https://goveo.app/alta?plan=top3-semestral` |
| | Anual | `https://goveo.app/alta?plan=top3-anual` |
| PLATINUM | Mensual | `https://goveo.app/alta?plan=platinum-mensual` |
| | Semestral | `https://goveo.app/alta?plan=platinum-semestral` |
| | Anual | `https://goveo.app/alta?plan=platinum-anual` |
| PREMIUM | Mensual | `https://goveo.app/alta?plan=premium-mensual` |
| | Semestral | `https://goveo.app/alta?plan=premium-semestral` |
| | Anual | `https://goveo.app/alta?plan=premium-anual` |
| FREE | Empezar gratis | `https://goveo.app/alta?plan=free-mensual` |

**Sin `plan`** (`https://goveo.app/alta`) el formulario empieza enseñando las
tarifas disponibles. Sirve para un botón genérico de «Dar de alta mi negocio».

**ENTERPRISE no lleva aquí**: es a medida y su botón debe ir a contacto.

## Parámetros opcionales

Todos son opcionales y todos son editables después: precargar no es imponer.

| Parámetro | Para qué |
|---|---|
| `plan` | Tarifa preseleccionada. El código de la tabla de arriba. |
| `email` | Precarga el email del negocio, que es con el que se crea la cuenta. |
| `name` | Nombre del negocio. |
| `phone` | Teléfono. |
| `category` | Categoría, por su slug (`fashion`, `hostelry`, `gourmet`…). |
| `partner` | Atribución a un partner o convenio (`aupa`, `alpedrete`…). |
| `utm_source`, `utm_medium`, `utm_campaign` | Campaña de origen; se guardan con el negocio. |

Ejemplo de un enlace de campaña completo:

```
https://goveo.app/alta?plan=platinum-anual&category=hostelry&partner=aupa&utm_source=landing&utm_campaign=prelanzamiento
```

⚠️ Los nombres van **en inglés** aunque la landing esté en español, porque se
mapean uno a uno al cuerpo que recibe el BFF. Traducirlos aquí obligaría a
mantener una tabla de equivalencias que acabaría desincronizada.

⚠️ **No metas importes por query.** El precio sale siempre de la tarifa; si
viajara en la URL, cualquiera podría contratar TOP 3 por cero.

## Si cambian las tarifas

Los códigos (`platinum-anual`) son estables y sobreviven a recrear el plan; los
UUID no. Por eso el enlace lleva el código.

La lista viva está en `GET https://api.goveo.app/public/billing/plans`, que
devuelve el `code`, el importe y la periodicidad de cada tarifa ofertable.
