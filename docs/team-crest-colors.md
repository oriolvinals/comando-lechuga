# Colores extraídos de los escudos de los equipos fantasy

Extraídos el 2026-08-23 analizando los píxeles de cada PNG directamente (sin librerías de imagen — decodificador PNG manual en Node usando `zlib`, descartando píxeles transparentes y agrupando por tono/saturación en vez de por rango RGB estrecho). Pensado para cuando estos colores se conviertan en un campo de `SeasonTeam` (p. ej. `crest_color_primary` / `crest_color_secondary`) y se usen en sitios como la franja de "quién tenía al jugador" en la ficha de jugador.

| Equipo | Principal | Secundario | Nota |
|---|---|---|---|
| Cruza FC | `#8a0607` (rojo) | — | un solo tono, sin secundario claro |
| CID F.C | `#021025` (azul marino) | `#2a1206` (marrón) | logo pequeño, paleta oscura y ambigua — revisar a mano |
| Gauchitos F.C | `#ecb21c` (dorado) | `#022c19` (verde oscuro) | el más "de escudo" clásico — dos colores claros |
| DukeBlack9 | `#0355f9` (azul) | — | un solo tono |
| DUBI F.C | `#441d70` (morado) | — | recalculado filtrando por tono (hue 250–300°, saturación ≥0.35) tras que el primer intento (agrupar por bucket RGB) lo perdiera entre los negros del fondo |
| Ariobretxa | `#571a78` (morado) | `#fde216` (amarillo) | dos colores claros |
| planuky | `#0a97a4` (turquesa) | `#021a34` (azul marino) | fondo blanco descartado del cálculo |

**Método:** decodificar el PNG (soporta color types 0/2/3/4/6), descartar píxeles con alpha bajo, descartar casi-blanco/casi-negro/gris (saturación baja) para el primer intento por bucket; para DUBI F.C se usó en su lugar un filtro directo por tono HSL ya que la paleta era muy oscura y el bucketing por rango RGB diluía el morado real entre los negros.

**Pendiente:** CID F.C sigue con paleta ambigua (logo pequeño, colores oscuros mezclados) — revisar a mano o re-extraer con el mismo método por tono usado en DUBI F.C si hace falta un color fiable.
