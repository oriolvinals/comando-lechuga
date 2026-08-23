# Colores de los escudos de los equipos fantasy

Ahora viven en `season_teams.primary_color` / `secondary_color` (migración
`2026_08_23_190000_add_colors_to_season_teams_table`). Rellenados a mano el
2026-08-23 mirando cada escudo directamente (`public/images/teams/*.png`),
no por un script de clustering de píxeles — el primer intento automático
(descartar transparencia/casi-blanco/casi-negro y agrupar por tono) acertó
la mayoría de colores principales pero se equivocó en CID F.C (dio un
marrón como secundario cuando el escudo es claramente azul marino + dorado).

| Equipo | Principal | Secundario |
|---|---|---|
| Cruza FC | `#8a0607` (rojo) | `#171210` (negro, cinta/borde) |
| CID F.C | `#2f5fd8` (azul claro, rayos) | `#8a1228` (rojo, franjas) |
| Gauchitos F.C | `#f0c419` (dorado) | `#0f3d24` (verde oscuro) |
| DukeBlack9 | `#3d7dfd` (azul claro, calavera) | `#0a0a0a` (negro) |
| DUBI F.C | `#7a2fd6` (morado vivo) | `#0a0a0a` (negro) |
| Ariobretxa | `#5c1f8a` (morado) | `#f0c419` (amarillo) |
| planuky | `#12a0ad` (turquesa) | `#0d2b46` (azul marino) |

**Criterio:** el principal es el color más distintivo/de marca del escudo
(no necesariamente el que más superficie ocupa) — en los escudos negro +
un solo acento vivo (DukeBlack9, DUBI F.C) el acento es el principal y el
negro el secundario, no al revés.
