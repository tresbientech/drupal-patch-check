# drupatch

Composer plugin for Drupal sites that carry patches.

After every `composer update` it prints one line per patch that needs
attention. A patch is flagged when the release already carries its fix, or
when it no longer applies.

## Install

```
composer require --dev tresbientech/drupatch
```

## The update hook

The plugin subscribes to `post-update-cmd`. It prints and stops. It writes
no file, and a failure on its side never fails the update.

Your patch manager applies the patches before this runs, so a patch that
still applies is something it has already proved. The hook reports only what
composer cannot: patches whose fix is now upstream, ones it could not judge,
and packages with no release for the core you are on.

```
drupatch: 1 unclear, 2 can go after this update
  unknown       drupal/domain 3.0.1  Domain content translations permissions
  shipped       drupal/token 1.15.0  Cache tag on token replacement
  shipped       drupal/redis 1.11.0  Default null cache_prefix
  run `composer drupal-patch-check` for the detail, or `--target <version>` before a core upgrade
```

When every patch applies and nothing is blocked, it prints nothing.

## The command

```
composer drupal-patch-check
```

| Option | What it does |
| --- | --- |
| `--target=11.4.5` | Judge the patches against the releases that core version would bring in, before you move to it. |
| `--target=latest` | The same, against the newest core release your own constraint allows. Nothing to type, so a scheduled job stays correct. |
| `--strict` | Also fail on a patch that could not be judged and on a package with no release. |
| `--reroll` | Write a re-rolled patch file for each patch that no longer applies. |
| `--fix` | Rewrite the patch declarations: drop what shipped, point the rest at their re-rolls. Implies `--reroll`. |
| `--force` | Let `--fix` write a file that already has uncommitted changes. |
| `--package=drupal/webform` | Only this package. Repeatable, and `webform` works too. Narrows the report, `--reroll`, `--fix` and the exit code. |
| `--json` | Print the plan as one JSON object. |

Exit code 0 means nothing needs work, 1 means a patch or a package does,
2 means the plan could not be fetched.

`drupatch-check` is an alias of the command.

## Running it in CI

The useful run is scheduled and forward-looking: do the patches still work
against the releases this site could install today? `--target latest` asks
for the newest core your own constraint allows, and every package follows
its own constraint against it, so nothing needs a version typed into it.

```yaml
# weekly
- run: composer drupal-patch-check --target latest --json > patch-check.json
- run: composer drupal-patch-check --target latest
- uses: actions/upload-artifact@v4
  if: always()
  with: { name: patch-check, path: patch-check.json }
```

| exit | meaning |
| --- | --- |
| 0 | Nothing needs a person. Patches that shipped upstream, ones that could not be judged, and packages with no release are reported and do not fail. |
| 1 | A patch will not apply against the release it was judged against, or carries a verdict this plugin does not know. |
| 2 | The plan could not be fetched. A service outage, not a finding. |

`--strict` also fails on a patch that could not be judged and on a package
with no release for the target. Without it a lagging mirror will not turn a
nightly job red.

The `--json` output carries a `summary` object for a notification step:

```json
{
  "summary": {
    "target_core": "11.4.5",
    "target_from": "drupal/core-recommended",
    "counts": { "needs-reroll": 1, "still-needed": 45 },
    "needs_reroll": ["drupal/webform"],
    "unclear": [], "shipped": [], "blocked": ["drupal/autotitle"],
    "exit_code": 1
  }
}
```

## Verdicts

| Verdict | Meaning |
| --- | --- |
| `still-needed` | The patch applies to the release and its fix is not upstream. Keep it. |
| `shipped` | The release already carries the fix. Drop the entry. |
| `needs-reroll` | The patch does not apply and its fix is not upstream. |
| `unknown` | The release or the patch could not be resolved. The row carries the reason. |

A re-roll that merges cleanly is written as `.patch`. One that leaves
conflict markers is written as `.conflict.patch` and is never referenced
from the patch declarations.

## Patch managers

drupatch reads the declaration and applies nothing. These shapes are
covered:

- `extra.patches`, entries written as an object or as a list
- `extra.patches-file`, an external file
- `extra.patches-ignore`
- a per-package strip level, which is read and then dropped: the check
  sweeps `-p0`, `-p1`, `-p2` and `-p4` itself

That covers `cweagans/composer-patches` 1.x and 2.x,
`vaimo/composer-patches` and `szeidler/composer-patches-cli`. Another
manager with the same shape works. One with its own shape prints a note.

## What leaves the site

Every run sends `composer.json`, `composer.lock` and the text of the
declared patches to `https://api.tresbien.tech/v1/composer/scan`. The call
goes through composer's own HTTP client, so the site's proxy and
certificate settings apply. The answer is the plan.

The endpoint is configurable:

```json
{
    "extra": {
        "drupatch": {
            "endpoint": "https://api.tresbien.tech/v1/composer/scan"
        }
    }
}
```

`composer update --no-plugins` skips the hook for one run.

## Requirements

PHP 8.1 or newer and Composer 2. The plugin adds no runtime dependency
beyond `ext-json`, because it runs inside the site's own composer process.

## Development

```
composer install
composer qa        # formatting, static analysis, tests
composer qa:full   # adds mutation testing, needs pcov or xdebug
```

PHPStan runs at level `max` with strict rules and no baseline.

## License

MIT. See [LICENSE](LICENSE).
