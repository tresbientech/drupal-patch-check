# Changelog

What changed for someone running this plugin. Versions before 0.15.0 are
recorded in the repository history.

This project is pre-1.0. A minor version may remove an option.

## Unreleased

### Changed

- A rewrite of the declarations writes them one value per line, in the
  file's own indentation, the way `composer normalize` lays them out. It used
  to leave nested values on as few lines as it could.
- `add` puts a package the site did not patch before the first package that
  sorts after it, so sorted declarations stay sorted. It used to put it last.

## 0.22.0

Released 2026-09-21. Needs patchcheck deployed on 2026-09-21 or later.

### Removed

- `--drop-tests` and `--keep-tests`, on `reroll`, `add` and
  `upgrade-patch-manager`. A run passing either is told the option is gone.

### Added

- `--from-source`, on the same three commands. It keeps every hunk of a
  re-roll. Pass it when the site is installed from source, so it holds the
  files a release package leaves out.

### Changed

- A re-roll leaves out the hunks that touch a file your release does not
  ship, and says which. It read the release's own archive to decide, so the
  answer follows what each project excludes.
- The rule applies to every project. It used to apply to core 12 and later.
- The set is no longer guessed from a path named `tests`. Core ships test
  traits under `tests/src/`, and removes a PHPStan baseline that holds no
  test, so the old guess was wrong in both directions.
- A plan reads `dropped_paths`, and falls back to `dropped_tests` for a
  service deployed before this release.

No released core tag carries the file that excludes core's test paths, so a
core re-roll drops nothing today. A contrib project that excludes paths is
already affected.

## 0.21.0

- `drupatch:add <issue>` takes an issue, work item or merge request URL. It
  ranks the requests against the installed release, copies the chosen diff,
  asks for a verdict, re-rolls a failing patch, then declares and applies it.
- `drupatch:upgrade-patch-manager` moves a 1.x site to 2.x in one run.
- On a 2.x site, `drupatch:check` fails a patch only a lenient apply took,
  as `refused by 2.x`.
- Provenance moved from the `# drupatch` line into `extra.drupatch`.
- `--package drupal` selects `drupal/core`. Every core copy lands in
  `patch/core/`, named after the package.

## 0.20.0

- The merge request warning prints on every run, whatever the site
  configured. `--quiet` silences it.
- No opt-out key. JSON is unchanged, and still includes `summary.unpinned`.

## 0.19.0

- `--strict` is gone. It only ever added two failures, and the report says
  both either way. A run passing it is told so.
- An unclear verdict no longer fails a run.
- The README was rewritten.

## 0.18.0

- `extra.drupal-patch-check.private-paths` keeps the paths a site holds its
  patches at off the request. Off by default.
- A patch title never travels, and `extra.patches` never travels.

## 0.17.1

- Printed lines only. The merge request pointer moved under the file it is
  about. Seven other lines were shortened. JSON unchanged.

## 0.17.0

- An adopted URL patch lands under `patch/<project>/`, apart from a
  `patches` directory the site manages by hand.
- `extra.drupal-patch-check.patch-directory` still overrides it. A site on
  the old default keeps its files.

## 0.16.0

- The plugin refuses to write a re-roll the service could not parse. The
  refusal names the failing file and its line, and the run still exits
  non-zero.
- A conflict file a person still has to edit is never withheld.

## 0.15.0

- A patch that applies and leaves a file PHP cannot compile now says so.
  `result.failure_mode` reads `broken syntax`, and `result.syntax_errors`
  holds the failing files.
- The run exits non-zero, and `summary` gains a `broken` package list.
