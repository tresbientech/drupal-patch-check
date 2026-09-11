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
  composer already applied these patches to your files

  3 patches are declared as merge request URLs. Anyone with a drupal.org
  account can push to a merge request, so what composer applies here can
  change between two installs. Run: composer drupatch:pin

  Next:  composer drupatch:reroll --target 11.4.6   writes the 2 re-rolls and drops the shipped entry from composer.json
```

## Remote service call

The plugin cannot judge a patch on its own. The check and the re-roll post your
patches to
[`api.tresbien.tech/v1/composer/scan`](https://api.tresbien.tech/v1/composer/scan),
which holds a mirror of every drupal.org release and does the work. It is run
by [Très Bien Tech](https://tresbien.tech), a long time Drupal contributor.

Installing the plugin sends nothing, and nothing is sent until you run a
command. Four commands reach the service: the check, the re-roll, the add and
the upgrade. The pin sends it nothing, and your composer.json, your lock file
and your patch text stay on your machine.

[Settings](#settings) says what the request holds and what you can leave out.

## Install

```
composer require --dev tresbientech/drupal-patch-check

# write access is needed to reroll
composer config allow-plugins.tresbientech/drupal-patch-check true
```

## The commands

```
composer drupatch:check                   judges every patch, writes nothing
composer drupatch:pin                     copies every patch declared as a URL into your site
composer drupatch:reroll                  writes what merges, and rewrites your declarations
composer drupatch:add <issue>             copies a merge request in, checks it and declares it
composer drupatch:upgrade-patch-manager   moves your site to cweagans/composer-patches 2.x
```

The check, the pin and the re-roll take `--package`, `--patch`, `--dry-run` and
`--format`. The check and the re-roll take `--target`; `--target latest` plans
against the newest core your own constraint allows.

The re-roll, the add and the upgrade take `--drop-tests` and `--keep-tests`. A
re-roll of a core patch onto 12.0 or later leaves the patch's test files out,
and every other re-roll keeps them. `--drop-tests` leaves them out of every
re-roll, and `--keep-tests` keeps them in every one. The run lists what it
left out.

They read your declarations where cweagans/composer-patches reads them:
`extra.patches` in composer.json, or the patches file your settings name. Both
the title-to-file map and the expanded object form are read.

The re-roll, the pin and the add ask git about composer.json or the patches file
before they ask the service or write anything. When the patches there have
uncommitted changes, the run stops and names the file. `--force` skips the
question.

## Moving to cweagans/composer-patches 2.x

2.x applies every patch with `git apply` alone. A patch that applied under 1.x
only with fuzz stops applying. One command moves the site:

```
$ composer drupatch:upgrade-patch-manager
```

It re-rolls each patch 2.x would refuse, moves your declarations into
`patches.json`, carries over the settings 2.x renamed, and requires `^2`. Then it
runs `composer update cweagans/composer-patches --with-dependencies`,
`composer patches-relock` and `composer patches-repatch`. The site ends on 2.x
with its patches applied.

`--dry-run` prints what the run would change and the commands it would run. A
command that fails stops the run, and the report names the commands left.
Running the upgrade again runs them.

The upgrade writes nothing when git reports composer.json or the patches file
changed. Commit them first, or pass `--force`.

## Patches declared as a URL

A patch declared as `https://git.drupalcode.org/project/webform/-/merge_requests/940.patch`
is downloaded on every install. Every run warns you about it: the four composer
commands that change your packages, and all three drupatch commands. No setting
turns the warning off, and it costs no network call. A site with no merge
request patch never sees it.

`composer drupatch:pin` copies the patch into your repository and points the
declaration at the file:

```
$ composer drupatch:pin
Drupal Patch Check: 1 patch copied into the site

  copied into the site:
    drupal/webform: 3521733: browser back/forward cache
      patch/webform/mr940.diff

  composer.json: 1 declaration now names a file in the site
```

The copy is written under `patch/<project>/`, or wherever `patch-directory`
says.

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

That record is the only thing still naming the merge request once the
declaration names a file, so `pin --refresh` reads it back, asks whether the
request has new commits, and takes them. A bare `pin` run asks nothing about a
copy already in place. Nothing else takes new bytes.

The record holds no hash. 2.x hashes every patch it locks, a local file
included, and refuses one whose bytes moved.

Pin does not overwrite a copied patch that git reports as changed. `--force`
does.

A commit URL is copied the same way, under `commit-<sha>.diff`. Any other URL is
copied under the name it ends in.

## Verdicts

| Verdict | Meaning |
| --- | --- |
| `applies` | The patch applies to the release and its fix is not upstream. Keep it. |
| `merged` | The fix is already in the release. Drop the entry. |
| `conflicts` | The patch does not apply and its fix is not upstream. |
| `unknown` | The patch was sent and came back without a verdict, and the row says why. |
| `skipped` | The patch was never sent, so it has no verdict. |

A patch can apply and still leave a file the site cannot load. PHP, YAML, JSON,
JavaScript and Twig are read for that. The verdict stays `applies`. A note opens with `broken syntax` and gives the
file and line, the headline counts those apart, and the run exits non-zero.

## Conflict files

A re-roll that merges cleanly replaces the patch file. One that leaves markers
is written as `.conflict.patch` beside it and is never referenced from your
declarations, so a half-merged patch never gets installed.

Inside, each open region falls between a `# drupatch region N file` line and a
`# drupatch end N file` line. Replace the text between them, or leave it empty
to drop the region, then run `composer drupatch:reroll` again. The report gives
every region as its file and index.

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
| `hook` | `false` | Print the patch verdicts after every `composer update`. |
| `patch-directory` | `patch` | Where a copied patch is written. |

`hook` covers the verdicts alone.

### What the request holds

The request is about your drupal.org packages: the ones composer installed from
packages.drupal.org, plus core.

Sent:

- four keys from `composer.json`: `require`, `require-dev`,
  `minimum-stability` and `prefer-stable`
- one trimmed `composer.lock` entry per package
- every patch you declare in `extra.patches`: its package and its text

Not sent:

- every other package you have, so a private module or a path repository is
  never mentioned
- your `repositories`, `config`, `autoload` and `scripts`
- the titles you gave your patches
- the paths you keep your patches at

A path can give something away. `patch/acme_dam/CUP-1341_preview.patch` tells a
reader your client and your ticket number, so none of it travels. A merge
request URL does travel when you declared one, because it names a public page
and the service reads it to say whether a release already holds the fix.

The patch text always travels as written, because the service judges it. If a
patch repeats the ticket number in a comment, that comment travels with it.

`composer drupatch:check --dry-run` prints the request.

## Requirements

PHP 8.1 or newer and Composer 2.3 or newer. It adds no runtime dependency
beyond `ext-json`, because it runs inside your own composer process.

## License

MIT.
