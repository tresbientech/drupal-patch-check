# Drupatch

Composer plugin for Drupal sites that carry patches.

A command to add to your CI that checks if your patches are still needed and 
apply. The check also run after every `composer update` and prints one line per 
patch that needs attention: patch is not necessary anymore or no longer applies.

## Install

```
composer require --dev tresbientech/drupatch
```

## Composer update hook

On `post-update-cmd` it show patches that were already shipped and are safe
to delete, or patches on modules that are not required anymore.

```
drupatch: 1 unclear, 1 can go after this update
  unknown       drupal/domain   Domain content translations permissions
                the lock does not install drupal/domain, so there is no release to judge this patch against
  shipped       drupal/token 1.15.0  Cache tag on token replacement
```
When every patch applies and nothing is blocked, it prints nothing.

Enabled by default, to turn the hook off add to your composer.json:

```json
{ "extra": { "drupatch": { "hook": false } } }
```

The command is unaffected, so `composer drupal-patch-check` still works.
`composer update --no-plugins` skips it for one run without any config.

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
| `--dry-run` | Print the request that would be sent and stop. Nothing is asked of the service, nothing is written. |

With `--target`, the plugin asks composer itself which release each patched
package would move to, using the site's own repositories, stability rules and
platform. That answer is sent with the request, and every row says whether it
was decided by `composer` or by the service's daily copy of drupal.org. A bare
run judges the releases the lock installs, so it resolves nothing and makes no
repository request.

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

`--strict` also fails on a patch that could not be judged, on a package with
no release for the target, and on a run that declared patches and checked
none. Without it a lagging mirror will not turn a nightly job red.

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
| `unknown` | No verdict could be reached, and the row says why. Some reasons are yours: the lock does not install the package, it has no release for the target, the patch file could not be read, or the patch is malformed. Some are not: the patch URL could not be fetched, or the release tag has not reached the service's mirror yet. |

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

The plugin builds a request from your composer files rather than sending
them. The service reads five keys of `composer.json` and, per lock entry, a
name and a version plus three fields that identify a sub-module. That is all
it is given.

```json
{
  "composer_json": {
    "require": { "drupal/core-recommended": "^10.6", "drupal/webform": "^6.2" },
    "require-dev": { "drupal/devel": "^5" },
    "minimum-stability": "dev",
    "prefer-stable": true,
    "extra": { "patches": {
      "drupal/webform": { "Fix the alter hook": "patches/webform-alter.patch" }
    }}
  },
  "composer_lock": {
    "packages": [
      { "name": "drupal/core", "version": "10.6.9" },
      { "name": "drupal/webform", "version": "6.2.9" },
      {
        "name": "drupal/domain", "version": "3.0.1", "type": "drupal-module",
        "extra": { "drupal": { "datestamp": "1778231514" } }
      },
      {
        "name": "drupal/domain_access", "version": "3.0.1", "type": "metapackage",
        "require": { "drupal/core": "^10.2 || ^11", "drupal/domain": "*" },
        "extra": { "drupal": { "datestamp": "1778231514" } }
      }
    ],
    "packages-dev": [ { "name": "drupal/devel", "version": "5.3.2" } ]
  },
  "patch_files": { "patches/webform-alter.patch": "diff --git a/… " },
  "target_core": "11.4.5",
  "candidates": { "drupal/webform": "6.3.1" },
  "installed_core": { "drupal/webform": "^10.3 || ^11" }
}
```

The three lock fields beyond name and version are there to identify a
sub-module. drupal.org holds no project for one: it packages a sub-module as
a `metapackage` built from its project's release, so there is nothing to
look up under its own name. `type` says which packages are which, `require`
and `datestamp` say which project provides it, and the service pairs them.
Only a metapackage carries `require`, and only its `drupal/` requirements.

`installed_core` is what each installed release requires of core, read from
your own vendor directory. The service's copy of drupal.org's release data
can be months behind a project; your site cannot, so this is what decides
whether the release you run supports the core you are moving to.

The two composer fields travel as JSON strings; they are shown expanded
here. Run `composer drupal-patch-check --dry-run` to print the real one for
your site and read it before you install this anywhere.

### What is never sent

From `composer.json`: `repositories`, `config`, `scripts`, `autoload`,
`name`, `description`, `license`, `authors`, `conflict`, `type`, and every
`extra` key but `patches`. From `composer.lock`: every package that is not a
drupal.org release, and for the ones that are, everything but the five
fields above. That means no `dist` or `source` URL, no `content-hash`, no
authors, no funding, and no requirement outside `drupal/`.

On a 320-package site the two documents go from 831 KB to 15 KB.

### Which packages are sent

A package is sent when `composer.lock` records its `notification-url` as
`https://packages.drupal.org/8/downloads`, or when it is one of core's own
packages. That is the set the service has a release for.

A `drupal/` name is not enough. A fork of `drupal/webform` kept in a company
repository carries that name, and so does a private `drupal/acme_sso`.
Neither has a drupal.org release, so neither is sent, and neither could have
been judged. Each run names what it held back:

```
drupatch: checked 101 packages and 53 patches; held back 9
  held back  drupal/coder (not a drupal.org release)
  held back  acme/private "In-house fix"
```

A patch is held back with its package, text included. A patch whose source
URL is not one the service fetches from is held back too, so an internal
host is never named.

If your site installs from a repository that rewrites `notification-url`,
such as some Satis or Private Packagist setups, nothing will be checked and
the run will say so. Call `/v1/composer/scan` directly with your whole files
if you want an answer for that site.

A patch source that is a URL is sent as the URL, and the service fetches it.
Local patch files are read up to 16 MB each, 100 files per run, and only
while the request stays inside the 32 MB the service accepts.

The call goes through composer's own HTTP client, so the site's proxy and
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
