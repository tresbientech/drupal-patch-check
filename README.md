# Drupatch

A composer plugin that checks the patches a Drupal site declares. For each
patch it says whether the fix is already in the installed release, whether
the patch still applies, or whether it no longer applies and needs a re-roll.
Run it as a command in CI. It also runs after every `composer update` and
prints one line per patch that needs attention.

## What it answers, and what it does not

One question: what happens to your patches. For each one, whether it is
already in the release, still applies and is still needed, or no longer
applies and has to be re-rolled.

It does not scan your code for deprecated API use. [Upgrade
Status](https://www.drupal.org/project/upgrade_status) does that, and
[Drupal Rector](https://www.drupal.org/project/rector) rewrites much of
what it finds. It does not tell you which packages block a core upgrade;
`composer why-not drupal/core 11.4.5` answers that, and core's Update
Status lists what has a newer release.

Those tools say nothing about your patches. This one reads the site and
writes nothing unless you ask it to.

## Install

```
composer require --dev tresbientech/drupal-patch-check
```

## Composer update hook

After `composer update`, the hook lists the patches whose fix is already in
the release, and the patches with no release to judge them against.

```
drupatch: 1 unknown, 1 merged after this update
  ? unknown       drupal/domain   Domain content translations permissions
                  the lock does not install drupal/domain, so there is no release to judge this patch against
  ✓ merged        drupal/token 1.15.0  Cache tag on token replacement
  run `composer drupal-patch-check` for the detail, or `--target <version>` before a core upgrade
  Next:  composer drupal-patch-check --fix   drops the merged entry from composer.json
```

When every patch applies it prints nothing. An applying patch whose added
lines reference a core symbol the target removed, moved or re-signed still
gets a row, with one `core` line under it. The `Next:` line appears only
when a flag would clear something.

A package with no release for the target is left out; composer already
refused to move it during the update. A `!` line about a requirement you
could widen prints beside a patch that needs a decision, never on its own.

The hook is on by default. To turn it off, add this to your composer.json:

```json
{ "extra": { "drupatch": { "hook": false } } }
```

The command still works with the hook off. `composer update --no-plugins`
skips the hook for one run.

## The command

```
composer drupal-patch-check
```

Patches are grouped under their package, worst package first, so whatever
needs a person is at the top. Each row has a mark, the verdict, the patch
title and the file it came from.

```
Drupal Code Query: 5 patches for a move from core 11.3.2 to 11.4.5

  drupal/webform 6.2.9 → 6.3.2   1 conflicts, 1 applies
    ! conflicts  Allow numeric machine names in handlers      webform-numeric.patch
    · applies  Fix the alter hook                           webform-alter.patch

  ! drupal/domain 2.1.0 supports 11.4.5; the site requires ^2.0. Widen it to ^2.1.
  drupal/domain 2.0.1   1 unknown
    ? unknown       Domain access on entity clone                domain-clone.patch
                    drupal/domain has no release for 11.4.5

  drupal/paragraphs 1.17.0 → 1.19.0   1 applies
    · applies  Drag handle keyboard access                  paragraphs-a11y.patch

  drupal/token 1.15.0   1 merged
    ✓ merged        Cache tag on token replacement               token-cache.patch

  patches: 1 conflicts, 2 applies, 1 merged, 1 unknown

  Next:  composer drupal-patch-check --write   writes the re-roll
         composer drupal-patch-check --fix     drops the merged entry from composer.json
```

The report fits the terminal it prints to, between 80 and 120 columns.
Titles are shortened to fit; nothing wraps, so one patch is always one row.
The `Next:` lines repeat the `--target` and `--package` options of the run,
so the suggested command acts on what the report showed.

Options that change what is judged, and how the answer is printed:

| Option | What it does |
| --- | --- |
| `--target=11.4.5` | Judges the patches against the releases that core version would bring in. |
| `--target=latest` | The same, against the newest core release your own constraint allows. |
| `--strict` | Also fails on a patch that could not be judged, and on a run that judged none. |
| `--package=drupal/webform` | Narrows the report, `--write`, `--fix` and the exit code to this package; repeatable, and `webform` works too. |
| `--format=json` | Prints the plan as one JSON object; `--json` does the same. |
| `--format=github` | Prints each verdict as a workflow command, so GitHub Actions shows it as an annotation on the declaring line. |
| `--dry-run` | Prints the request that would be sent and stops, without asking the service or writing anything. |

Options that write to the site:

| Option | What it does |
| --- | --- |
| `--write` | Replaces each patch file whose patch no longer applies with its re-roll, and writes a conflicted merge beside it as `<name>.conflict.patch`. |
| `--fix` | Rewrites the patch declarations: drops what is already in the release, adopts the ones declared as a URL, and implies `--write`. |
| `--resolve` | Re-reads the `.conflict.patch` files, sends the regions you decided, writes back what the service verified, and implies `--write`. |
| `--force` | Lets `--write` replace a patch file git reports as changed or untracked, and lets `--fix` rewrite a declaration file with uncommitted changes. |

A run that writes prints only the rows a person still has to look at. An
`applies` row with nothing under it is left out; the package line keeps its
tally, and so does the `patches:` line. Below the tally, the run lists what
it wrote and what it would not, then what `--fix` rewrote:

```
  wrote patches/webform/fix.patch  (clean, verified against the release by the server)
  wrote patches/core/tx.conflict.patch  (conflicts, 2 regions to decide)
  1 re-roll left regions to decide; those files are not usable as patches

  not written:
    drupal/token: Cache tag on token replacement
      it has uncommitted changes

  composer.json:
    - drupal/pathauto: Menu cache (already in the release)
    ~ drupal/webform: Fix the alter hook → patches/webform/fix.patch
```

With `--target`, the plugin asks composer which release each patched package
would move to, using the site's own repositories, stability rules and
platform. That answer goes with the request. Every row says whether
`composer` or the service's daily copy of drupal.org decided it. A bare run
judges the releases the lock installs, so it resolves nothing.

Exit code 0 means nothing needs work. 1 means a patch or a package does.
2 means the plan could not be fetched.

`drupatch-check` is an alias of the command.

## Running it in CI

The useful run is scheduled and forward-looking: do the patches still work
against the releases this site could install today? `--target latest` asks
for the newest core your own constraint allows. Every package follows its
own constraint against it, so nothing needs a version typed in.

```yaml
# weekly
- run: composer drupal-patch-check --target latest --format json > patch-check.json
- run: composer drupal-patch-check --target latest --format github
- run: composer drupal-patch-check --target latest
- uses: actions/upload-artifact@v4
  if: always()
  with: { name: patch-check, path: patch-check.json }
```

`--format github` writes one `::error`, `::warning` or `::notice` line per
patch that needs a decision, anchored to the line of `composer.json` (or
your patches file) that declares it. A patch that still applies writes
nothing. Gitea Actions accepts the same commands and does not render them
yet.

| Exit | Meaning |
| --- | --- |
| 0 | Nothing needs a person. Patches already in the release, and patches that could not be judged, are reported and do not fail. |
| 1 | A patch will not apply against the release it was judged against, or has a verdict this plugin does not know. |
| 2 | The plan could not be fetched. A service outage, not a finding. The line gives the reason the service sent, when it sent one. |

`--strict` also fails on a patch that could not be judged, and on a run that
declared patches and checked none. Without it, a lagging mirror does not turn
a nightly job red.

A package with no release for the target never fails a run on its own. It
has no patch, or its patches were judged against the branch the site
installs and their verdicts already said so.

The `--json` output has a `summary` object for a notification step:

```json
{
  "summary": {
    "target_core": "11.4.5",
    "target_from": "drupal/core-recommended",
    "counts": { "conflicts": 1, "applies": 45 },
    "conflicts": ["drupal/webform"],
    "unclear": [], "merged": [], "blocked": ["drupal/autotitle"],
    "exit_code": 1,
    "next": [{ "flag": "--write", "effect": "writes the re-roll" }]
  }
}
```

`next` lists the commands the table footer would print. It is absent when
there is nothing to run.

## Verdicts

| Verdict | Meaning |
| --- | --- |
| `applies` | The patch applies to the release and its fix is not upstream. Keep it. |
| `merged` | The fix is already in the release. Drop the entry. |
| `conflicts` | The patch does not apply and its fix is not upstream. |
| `unknown` | No verdict, and the row says why. Some reasons are yours. The lock does not install the package, or it has no release for the target. The patch file could not be read, or the patch is malformed. Some are not yours: the patch URL could not be fetched, or the release tag is not on the service's mirror yet. |

A re-roll that merges cleanly is written as `.patch`. One that leaves
conflict markers is written as `.conflict.patch` and is never referenced
from the patch declarations.

## Conflict files

A conflict file holds the hunks that merged, then each open region between a
`# drupatch region N file` line and a `# drupatch end N file` line. Inside
are the release side and the patch side as merge markers. Replace the text
between the two sentinel lines with the code you want. Leave it empty to
drop the region. Then run `composer drupal-patch-check --resolve`. The
decided regions are sent, the service merges with them and apply-checks the
result. The finished patch replaces the file the site declares. A region
left undecided comes back in a new conflict file.

## Core references

A row can have `core` lines under it. The patch applies, and the lines it
adds reference something the target release changed: a removed class, a
class now under another name, or a call whose argument count no longer fits.
Each line says what changed and gives the change record that documents it.
Three lines print per patch; the rest are counted. `core deprecated` counts
references that still work at the target and are scheduled for removal. A
note replaces the lines when the references could not be checked. A
`conflicts` row prints no such note; its verdict already says the patch
does not apply. These lines never change the exit code.

In `--format=json` the same data is `plan.patches[].result.core_references`.

## Patch managers

drupatch reads the declaration and applies nothing. These shapes are read:

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
name, a version, the commit the release was cut from, and three fields that
identify a sub-module. That is all it is given.

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
      {
        "name": "drupal/webform", "version": "6.2.9",
        "source": { "reference": "3f2c1a9e7b0d4c6a8e1f2b3c4d5e6f7a8b9c0d1e" }
      },
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

`source.reference` is the commit the installed release was cut from, as the
lock records it. With `--target`, a re-roll tries that commit first as the
release the patch was made for.

The three lock fields beyond that identify a sub-module.
drupal.org holds no project for one: it packages a sub-module as a
`metapackage` built from its project's release, so there is nothing to look
up under its own name. `type` says which packages are which. `require` and
`datestamp` say which project provides it, and the service pairs them. Only
a metapackage has `require`, and only its `drupal/` requirements.

`installed_core` is what each installed release requires of core, read from
your own vendor directory. The service's copy of drupal.org's release data
can be months behind a project; your site cannot be. So this is what decides
whether the release you run supports the core you are moving to.

The two composer fields travel as JSON strings; they are shown expanded
here. Run `composer drupal-patch-check --dry-run` to print the real one for
your site, and read it before you install this anywhere.

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
repository has that name, and so does a private `drupal/acme_sso`. Neither
has a drupal.org release, so neither is sent, and neither could have been
judged. Each run lists the packages it skipped and how many of their patches
went with them:

```
drupatch: checked 53 patches; skipped 7 on 2 packages
  skipped  acme/private, 6 patches (not a drupal.org release)
  skipped  drupal/acme_sso, 1 patch (not a drupal.org release)
```

A patch is skipped with its package, text included, and its title is never
printed: a package the run never touched is one decision. A patch whose
source URL is not one the service fetches from is skipped too, so an
internal host is never printed. A package with no patch is not listed at
all: this report is about patches.

If your site installs from a repository that rewrites `notification-url`,
such as some Satis or Private Packagist setups, nothing is checked and the
run says so. Call `/v1/composer/scan` directly with your whole files for an
answer on that site.

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
```

PHPStan runs at level 6 with no baseline.

## License

MIT. See [LICENSE](LICENSE).
