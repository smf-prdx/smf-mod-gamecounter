# Game Counter for SMF 2.1

Game Counter adds visible scoreboards to selected Simple Machines Forum topics.
It is designed for forum games where users award points in replies and the topic
header shows the current ranking.

## Usage

Award one point:

```bbc
[gamepoint=Paradox]
```

Award multiple points, up to the admin configured limit:

```bbc
[gamepoint=Paradox +3]
[gamepoint=Paradox points=3]
```

Add a custom visible note:

```bbc
[gamepoint=Paradox]for solving it on the first clue[/gamepoint]
```

Mark a player as a no-show, known in Spanish as a "malqueda":

```bbc
[malqueda=Paradox]
[malqueda=Paradox +2]
[noshow=Paradox]
```

Initialize a legacy scoreboard. Only blocks posted by administrators are used
for scoring:

```bbc
[initgamecounter]
85 puntos: Orestes
75 puntos: Vandemar
51 puntos: Aliena, Deke, cadavre
[/initgamecounter]
```

Initialize legacy no-shows. This is optional; for small lists it is usually
enough to post one or more `[malqueda=Name]` tags.

```bbc
[initmalquedas]
2: Paradox
1: prdx, prdx_mod
[/initmalquedas]
```

If several valid initialization blocks exist in a topic, the last one by message
order is used as the base score, and later game points or no-shows are added on
top.

## Administration

Open:

```text
?action=admin;area=modsettings;sa=gamecounter
```

Configure active topic IDs, blocked point authors, the scoreboard title, cache
lifetime, and the maximum number of points that one tag may award.

## Implementation Notes

The module is hook-only and does not patch SMF files. Scores are calculated from
approved topic messages and cached per topic. Post creation, post edits, message
removal, topic removal, and approval changes invalidate the affected topic cache.
