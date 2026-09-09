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
                    context drifted, needed: git apply -p1 -C1 --ignore-whitespace --recount

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

The plugin cannot judge a patch on its own. Every run posts your patches to
[`api.tresbien.tech/v1/composer/scan`](https://api.tresbien.tech/v1/composer/scan),
which holds a mirror of every drupal.org release and does the work. It is run
by [Très Bien Tech](https://tresbien.tech), a long time Drupal contributor.

[Settings](#settings) says what the request holds and what you can leave out.

## Install

```
composer require --dev tresbientech/drupal-patch-check

# write access is needed to reroll
composer config allow-plugins.tresbientech/drupal-patch-check true
```

Installing it runs nothing and sends nothing.

## The commands

```
composer drupatch:check    judges every patch, writes nothing
composer drupatch:pin      copies every patch declared as a URL into your site
composer drupatch:reroll   writes what merges, and rewrites your declarations
```

All three take `--package`, `--patch`, `--dry-run` and `--format`. The check and
the re-roll take `--target`; `--target latest` plans against the newest core
your own constraint allows.

They read `extra.patches` in your composer.json, the map
cweagans/composer-patches applies: one block per package, and inside it one
entry per patch, its title as the key and its file or URL as the value.

## Patches declared as a URL

A patch declared as `https://git.drupalcode.org/project/webform/-/merge_requests/940.patch`
is downloaded on every install, and anyone with a drupal.org account can push to
that merge request. What composer applies then changes while your site does not.

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

The first line of the file records where the bytes came from, the two commits
the diff was taken between, and a hash of the rest of the file. A later run
reads it back: `check` says when the file was edited, and `pin` says when the
merge request has new commits. `pin --refresh` takes those new commits, and
nothing else does.

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

A copied patch is written under `patch/<project>/`, or wherever
`patch-directory` says.

## Running it in CI

The useful run is scheduled and forward-looking: do the patches still work
against the releases this site could install today?

```yaml
# weekly
- run: composer drupatch:check --target latest --format json > patch-check.json
- if: always()
  run: jq -r '.summary | "\(.counts.conflicts // 0) conflicts, exit \(.exit_code)"' patch-check.json
- uses: actions/upload-artifact@v4
  if: always()
  with: { name: patch-check, path: patch-check.json }
```

Exit 0 means nothing needs work, 1 means a patch or a package does, 2 means the
plan could not be fetched. A patch the service could not judge does not fail
the run on its own. `--format=json` keeps stdout machine-readable and puts
every person-facing note on stderr.

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
| `hook` | `false` | Check patches after every `composer update`. |
| `patch-directory` | `patch` | Where a copied patch is written. |

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
