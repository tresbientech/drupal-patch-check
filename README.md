# Drupal Patch Check

A composer plugin for sites that keep Drupal patches. It says which of your
patches still apply after an update, which ones a release already fixed, and
it re-rolls the ones that broke.

```
$ composer drupatch:check --target latest

Drupal Patch Check: 4 patches for a move from core 10.2.4 to 11.4.6

  acme/private_module 8.2.5   6 patches skipped (not a drupal.org project)

  drupal/addtoany 2.0.5 → 2.0.7   1 applies
     #1 · applies   Add SRI to script                               add_SRI_to_JS_file.patch
                    context drifted, your patch manager still applies it

  drupal/yoast_seo 2.0.0-alpha10 → 2.2.0   1 conflicts, 1 merged
     #1 ✓ merged    Uncaught DOMException: Failed to execute 'rem…  3394487-failed-to-execute-remov…
     #2 ! conflicts Metatags depending on URL cause errors for un…  yoast_seo-3110455-22.patch
                    src/EntityAnalyser.php:211: patch failed

  drupal/diff 1.1.0 → 1.10.0   1 conflicts
     #1 ! conflicts Display current revision                        3359192-display-current-revisio…
                    src/Form/RevisionOverviewForm.php:4: patch failed
                    src/Form/RevisionOverviewForm.php:95: patch failed

  patches: 1 applies, 2 conflicts, 1 merged

  Next:  composer drupatch:reroll --target 11.4.6   writes the 2 re-rolls and drops the shipped entry from composer.json
```

## Remote service call

The plugin cannot judge a patch on its own. It posts your patches to
[`api.tresbien.tech/v1/composer/scan`](https://api.tresbien.tech/v1/composer/scan),
which holds a mirror of every drupal.org release and does the work. It is run
by [Très Bien Tech](https://tresbien.tech), a long time Drupal contributor.

Installing the plugin sends nothing. Four commands call the service: the check,
the re-roll, the add and the upgrade. With the `hook` setting on, every
`composer update`, `require` and `remove` calls it too. The pin sends it
nothing.

The `DRUPATCH_ENDPOINT` environment variable sends the call to another address.
Nothing in composer.json can change it.

[Settings](#settings) says what the request holds and what you can leave out.

## Install

```
composer require --dev tresbientech/drupal-patch-check

# composer loads the plugin's commands and its hook only once it is allowed
composer config allow-plugins.tresbientech/drupal-patch-check true
```

An interactive install prints this once per site:

```
Drupal Patch Check is installed. It sends your patch data to api.tresbien.tech.
Review what is sent by running composer drupatch:check --dry-run.
This site runs cweagans/composer-patches 1.x. 2.x is the line to be on.
It applies with git apply alone and locks a hash per patch.
See what moving costs: composer drupatch:upgrade-patch-manager --dry-run
```

The first two lines print when composer.json has no `hook` setting. The last
three print on a site running cweagans/composer-patches 1.x, and no setting
turns them off.

## The commands

```
composer drupatch:check                   judges every patch, writes nothing
composer drupatch:pin                     copies every patch declared as a URL into your site
composer drupatch:reroll                  writes what merges, and rewrites your declarations
composer drupatch:add <issue>             copies a merge request in, checks it and declares it
composer drupatch:upgrade-patch-manager   moves your site to cweagans/composer-patches 2.x
```

`composer drupal-patch-check` runs the check too.

They read your declarations where cweagans/composer-patches reads them:
`extra.patches` in composer.json, and the patches file your settings point at.
Both the title-to-file map and the expanded object form are read.

| Option | Commands | Effect |
| --- | --- | --- |
| `--package <name>` | check, pin, reroll | Acts on one package. Repeatable. |
| `--patch <source>` | check, pin, reroll | Acts on one patch, as the path or URL you declared. Repeatable. |
| `--target <core>` | check, reroll | Judges against this core. `latest` is the newest core your constraint allows. |
| `--format table\|json` | check, pin, reroll | `table` by default. |
| `--dry-run` | all five | The check and the re-roll print the request and stop. The others write nothing. |
| `--force` | pin, reroll, add, upgrade | Skips the git question, and replaces a patch file git reports as changed or untracked. |
| `--drop-tests` | reroll, add, upgrade | Leaves each patch's test files out of its re-roll. |
| `--keep-tests` | reroll, add, upgrade | Keeps them in. A run given both flags is refused. |
| `--decisions <file>` | reroll | Sends the regions a JSON document decides. `-` reads stdin. |
| `--mr <number>` | reroll, add | Takes this merge request of the issue without asking. |
| `--refresh` | pin | Takes the new commits of a merge request copied earlier. |
| `--vendor` | upgrade | Copies every patch into the site. Without it, only the ones a re-roll lands on. |

A re-roll of a core patch onto 12.0 or later leaves the patch's test files out,
and every other re-roll keeps them. `--drop-tests` and `--keep-tests` decide for
every re-roll in the run. The run prints a count, as in
`the re-roll left out 2 test files, so your patch holds the fix alone`.

The re-roll, the pin and the add ask git about composer.json and the patches
file before they ask the service or write anything. The run stops and prints the
file when:

- the patches declared there differ from the last commit
- the site is not in git

`--force` skips the question, and `--dry-run` never asks it.

## Moving to cweagans/composer-patches 2.x

2.x applies every patch with `git apply` alone. A patch that applied under 1.x
only with fuzz stops applying. One command moves the site:

```
$ composer drupatch:upgrade-patch-manager
```

It re-rolls each patch 2.x would refuse and moves your declarations into a
patches file. It moves your settings and requires `^2`. Then it runs
`composer update cweagans/composer-patches --with-dependencies`,
`composer patches-relock` and `composer patches-repatch`. The site ends on 2.x
with its patches applied.

The patches file is the one 1.x `extra.patches-file` points at, or
`patches.json` when that is unset. The settings change this way:

- `extra.patches-ignore` becomes `extra.composer-patches.ignore-dependency-patches`
- `extra.patches-file` becomes `extra.composer-patches.patches-file`
- `extra.patchLevel`, `extra.enable-patching` and
  `extra.composer-exit-on-patch-failure` are dropped, because 2.x reads none of
  them
- a patch records a depth only when it applies at a depth other than its
  package default: 2 for `drupal/core`, 1 for the rest

`--dry-run` prints what the run would change and the commands it would run. A
command that fails stops the run, and the report lists the commands left.
Running the upgrade again runs them.

The upgrade writes neither composer.json nor the patches file, and the site
stays on 1.x, when:

- a re-roll leaves regions to decide
- a patch 2.x would refuse has no clean re-roll
- a copy or a re-roll could not be written

The report lists those patches. For open regions, decide them, run
`composer drupatch:reroll`, then run the upgrade again.

The upgrade writes nothing when git reports composer.json or the patches file
changed, or the site is not in git. Commit them first, or pass `--force`.

## Adding a patch from an issue

`composer drupatch:add <issue>` takes a drupal.org issue URL, its GitLab
work-item form, or a merge request URL. It needs cweagans/composer-patches 2.x,
and a 1.x site is sent to `drupatch:upgrade-patch-manager`.

The run:

- takes the issue's only merge request, or lists them and asks which one
- copies the diff into the site and asks the service for a verdict
- re-rolls a patch that fails, over the copy
- removes the copy and declares nothing when the release already holds the fix
- declares nothing when a re-roll leaves regions to decide, and prints them
- otherwise declares the patch, then runs `composer patches-relock` and
  `composer patches-repatch`

## Patches declared as a URL

A patch declared as `https://git.drupalcode.org/project/webform/-/merge_requests/940.patch`
is downloaded on every install. On a site running cweagans/composer-patches
1.x, these runs warn about it:

- `composer install`, `update`, `require` and `remove`
- `composer drupatch:check`, `drupatch:reroll` and `drupatch:pin`

```
  1 patch is declared as a merge request URL. Anyone with a drupal.org
  account can push to a merge request, so what composer applies here can
  change between two installs. Run: composer drupatch:pin
```

A check run also prints `declared as a merge request URL` under each such row.
No setting turns the warning off, and it costs no network call. The add and the
upgrade do not print it. 2.x records a hash per patch and refuses bytes that
moved, so a 2.x site never sees it.

`composer drupatch:pin` copies the patch into your repository and points the
declaration at the file:

```
$ composer drupatch:pin
Drupal Patch Check: 1 patch copied into the site

  copied into the site:
    drupal/webform: 3521733: browser back/forward cache
      patch/webform/mr940.diff

  composer.json: 1 declaration now points at a file in the site
```

The copy is written under `patch/`, or wherever `patch-directory` says. It goes
in a folder named after the package: `webform` for `drupal/webform`, `core` for
`drupal/core`.

The file holds the diff and nothing else. Where the bytes came from goes on the
declaration, under `extra.drupatch`, which cweagans/composer-patches 2.x copies
into `patches.lock.json` untouched:

```json
"drupal/webform": [
    {
        "description": "3521733: browser back/forward cache",
        "url": "patch/webform/mr940.diff",
        "extra": {
            "drupatch": {
                "mr": "https://git.drupalcode.org/project/webform/-/merge_requests/940",
                "base": "e0f2f213bd2103d4d020d4800aed82643ec40b5f",
                "head": "ec708af86e4565bc55739e65dd203e26caaf4553",
                "fetched": "2026-09-10"
            }
        }
    }
]
```

Once the declaration points at a file, that record is the only link back to the
merge request. `pin --refresh` reads it, asks whether the request has new
commits, and takes them. A bare `pin` run asks nothing about a copy already in
place. Nothing else takes new bytes.

The record holds no hash. 2.x hashes every patch it locks, a local file
included, and refuses one whose bytes moved.

Pin does not overwrite a copied patch that git reports as changed. `--force`
does.

A commit URL is copied the same way, as
`commit-<first 12 characters of the sha>.diff`. Any other URL is copied under
the name it ends in.

## Re-rolls

A re-roll that merges cleanly replaces the patch file. One that leaves markers
is written as `.conflict.patch` beside it and is never referenced from your
declarations, so a half-merged patch never gets installed. The declaration of a
patch the release already holds is dropped.

Inside, each open region falls between a `# drupatch region N file` line and a
`# drupatch end N file` line. Replace the text between them, or leave it empty
to drop the region, then run `composer drupatch:reroll` again. The report gives
every region as its file and index.

`--decisions` decides regions without editing the file. Each entry gives the
patch as you declared it, the file and the region index. It then sets `choice`
to `release` or `patch`, or gives the `text` to put there:

```json
{
  "decisions": [
    {"source": "patches/webform/fix.patch", "file": "src/Entity/Webform.php", "region": 0, "choice": "release"},
    {"source": "patches/webform/fix.patch", "file": "src/Entity/Webform.php", "region": 1, "text": "return $build;\n"}
  ]
}
```

Where the document and a conflict file decide the same region, the document
wins.

A patch declared as a URL has no file to replace. The re-roll refuses it and
points at `composer drupatch:pin`.

On a 2.x site, a conflicting local patch can belong to a drupal.org issue. Its
title starts with the issue number, or its file name holds it. The re-roll
then takes one of that issue's merge requests, copies it in and merges from it.
`--mr <number>` picks the request for a run that cannot ask.

## Verdicts

| Verdict | Meaning |
| --- | --- |
| `applies` | The patch applies to the release and its fix is not upstream. Keep it. |
| `merged` | The fix is already in the release. Drop the entry. |
| `conflicts` | The patch does not apply and its fix is not upstream. |
| `unknown` | The patch was sent and came back without a verdict, and the row says why. |
| `skipped` | The patch was never sent, so it has no verdict. `drupatch:check` lists these. A re-roll run lists them only when it judged nothing. |

A patch can apply and still leave a file the site cannot load. PHP, YAML, JSON,
JavaScript and Twig are read for that. The verdict stays `applies`, and a line
under the row opens with `broken syntax` and gives the file and line. The check
headline counts every patch. The package tally, the `patches:` tally and the
hook headline count broken syntax apart.

On a 2.x site, a patch that only a lenient apply accepts is `refused by 2.x`.
The check, the re-roll and the add read it this way, and the hook prints the
verdict as the service sent it. The verdict stays `applies`, and both tallies
count it apart:

```
  drupal/webform 6.2.9   1 refused by 2.x
     #1 ! applies   Fix the alter hook                                                   alter.patch
                    your patch manager refuses it; `composer drupatch:reroll` writes a version it applies

  patches: 1 refused by 2.x
```

## Exit codes

- `0`: every patch in scope applies, is already in the release, or came back
  `unknown`
- `1`: a patch conflicts, has broken syntax, is refused by 2.x, or came back
  with a verdict the installed plugin does not know
- `2`: the check, the re-roll or the pin failed: no answer from the service, an
  unreadable site, or a failed write

The re-roll takes its exit code from the verdicts, so a run that wrote a clean
re-roll still exits 1. The pin exits 1 when it could not copy a patch. The add
and the upgrade exit 1 when they stop.

## JSON output

`--format=json` prints one JSON document on stdout, and every note on stderr.
Its top-level keys:

- check and re-roll: the service's answer as received, then `summary`,
  `written` and `refused`
- pin: `vendored`, `kept`, `moved`, `refused` and `rewritten`

## Settings

Every setting lives under `extra.drupal-patch-check` in your composer.json.

```json
{
  "extra": {
    "drupal-patch-check": {
      "hook": true,
      "patch-directory": "patches"
    }
  }
}
```

| Key | Default | Effect |
| --- | --- | --- |
| `hook` | `false` | Print the patch verdicts after every `composer update`, `require` and `remove`. Each of those runs then calls the service. |
| `patch-directory` | `patch` | Where a copied patch is written. |

`hook` covers the verdicts alone. The merge request warning prints either way.

A leftover `private-paths` setting prints
`drupatch: extra.drupal-patch-check.private-paths is no longer read: no path of your own leaves the site`.

### What the request holds

The request is about your drupal.org packages: the ones composer installed from
packages.drupal.org, plus core.

Sent:

- four keys from `composer.json`: `require`, `require-dev`,
  `minimum-stability` and `prefer-stable`
- one trimmed `composer.lock` entry per package
- one entry per patch you declare in `extra.patches` or in the patches file
- `client`: the plugin name and plugin version
- `target_core`: the core the run judges against, empty without `--target`
- `reroll`: whether the run asks for re-rolls
- `candidates`: with `--target`, the release composer would install for each
  patched package
- `installed_core`: the core requirement each installed release declares, read
  from your vendor directory
- `drop_tests`: whether a re-roll leaves test files out, sent only with
  `--drop-tests` or `--keep-tests`

Per declaration, the request holds:

- `package` and `patch`: the package and the patch text
- `merge_patch`: the `.diff` form of a merge request declared as `.patch`
- `upstream`: the merge request URL, for a patch declared as one
- `provenance`: for a copied patch, where its bytes came from, as `mr`,
  `commit` and `base`, plus `url` when that URL is on drupal.org or
  git.drupalcode.org
- `resolutions`: the regions you decided in a conflict file or a `--decisions`
  document

Not sent:

- every other package you have, so a private module or a path repository is
  never mentioned
- your `repositories`, `config`, `autoload` and `scripts`
- the titles you gave your patches
- the paths you keep your patches at, and every patch URL you declared other
  than a merge request URL
- a copied patch's head commit, fetch date and re-roll release
- a copied patch's URL on any host other than drupal.org and git.drupalcode.org

A path can give something away. `patch/acme_dam/CUP-1341_preview.patch` tells a
reader your client and your ticket number, so none of it travels. A URL on
another host can tell as much, so it stays home too. A merge request URL
travels, declared or copied, and so does the URL of a drupal.org attachment you
pinned. Both point at public pages. The service reads a merge request to say
whether a release already holds the fix.

The patch text always travels as written, because the service judges it. If a
patch repeats the ticket number in a comment, that comment travels with it.

`composer drupatch:check --dry-run` prints the request.

## Requirements

PHP 8.1 or newer and Composer 2.3 or newer. It adds no runtime dependency
beyond `ext-json`, because it runs inside your own composer process.

## License

MIT.
