# Changelog

## 1.1.4-106.1

- Merges GameCounter 1.1.4 member validation into the CientoSeis-specific
  branch.

## 1.1.3-106.1

- Adds the CientoSeis-specific 106-point visual buckets on the development-106
  branch.
- Shows overflow points next to each player before no-show/malqueda counters.

## 1.1.4

- Adds an optional admin setting to require game players to exist as activated
  forum members.
- Accepts SMF mention-style `@Name` values and stores the canonical display
  name when the member exists.
- Shows invalid-player warnings in point, no-show, and initialization tags.
- Bumps the internal cache version so existing topic scoreboards are rebuilt.

## 1.1.3

- Shows only the first 10 scoreboard rows by default and folds the remaining
  rows into an integrated expandable section.
- Makes the scoreboard table more compact.

## 1.1.2

- Makes the scoreboard metadata count total accumulated points from the final
  table, including initial scores and multi-point awards.
- Makes the no-show/malqueda metadata count the final accumulated total.
- Bumps the internal cache version so existing topic summaries are rebuilt.

## 1.1.1

- Treats `[gamepoint=Name points=0]` and negative point values as invalid tags
  instead of folding the suffix into the player name.
- Bumps the internal cache version so existing topic scoreboards are rebuilt.

## 1.1.0

- Adds `[malqueda=Name]` and `[noshow=Name]` secondary counters.
- Adds administrator-only `[initmalquedas]` import blocks.
- Shows no-show/malqueda counts next to player names in the scoreboard.

## 1.0.0

- Initial release.
- Adds `[gamepoint=Name]` BBCode with optional multi-point awards.
- Adds administrator-only `[initgamecounter]` import blocks.
- Adds topic header scoreboard rendering.
- Adds dedicated admin settings under Modifications.
- Adds per-topic cache invalidation hooks.
