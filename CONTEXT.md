# Comando Lechuga

Tracker web para una liga privada de LaLiga Fantasy: sincroniza datos desde la plataforma oficial y los muestra (clasificación, plantillas, mercado, actividad, partidos).

## Language

**Ficha**:
Página de detalle de una única entidad (un equipo fantasy, un jugador, un partido). "Ficha de equipo", "ficha de jugador", "ficha de partido".

**Plantilla actual**:
El conjunto de jugadores que un equipo fantasy posee ahora mismo, sin importar la jornada. Corresponde a `ManagerPlayer`: propiedad, cláusula de rescisión y blindaje, sin `week_number` asociado.
_Avoid_: Plantilla, roster (a secas, sin calificar) — ambiguo con "plantilla de jornada".

**Plantilla de jornada**:
El once inicial, la formación y los puntos que un equipo fantasy alineó en una jornada concreta ya jugada. Corresponde a `ManagerLineup` + `ManagerLineupPlayer` (con `week_number`).
_Avoid_: Alineación (a secas) — usar "de jornada" para no confundir con la plantilla actual.

**Actividad**:
El feed de eventos de una temporada (fichajes, ventas, blindajes, premios semanales, altas en la liga). Existe como feed global de toda la liga y también como bloque filtrado embebido en otras fichas.

**Mercado**:
El listado diario de jugadores sin equipo fantasy que la liga pone a disposición para pujar. Corresponde a `MarketPlayer`; se renueva cada día. Vive en Home, sin página propia.
_Avoid_: No confundir con "valor de mercado".

**Valor de mercado**:
El precio diario de un jugador, tenga o no equipo fantasy, registrado a lo largo del tiempo. Corresponde a `PlayerMarket`; se muestra como gráfico de evolución en la ficha del jugador.

**Clasificación general**:
El orden de los equipos fantasy de la liga por puntos totales acumulados en la temporada (`SeasonManager.position`/`total_points`). Vive en Home.
_Avoid_: "Clasificación" a secas cuando se pueda confundir con la de jornada.

**Clasificación de la jornada**:
El orden de los equipos fantasy solo por los puntos que sacaron en una jornada concreta (`ManagerLineup.points` de esa `week_number`), independiente de su posición en la clasificación general. Vive en Equipos, con selector de jornada.

**Cláusula de rescisión**:
La cantidad que otro equipo fantasy debe pagar para llevarse directamente un jugador que ya pertenece a otro equipo. Corresponde a `ManagerPlayer.buyout_clause`; se muestra en la plantilla actual de la ficha de equipo. Un jugador puede estar "blindado" (`shielded`), lo que bloquea esta operación.
