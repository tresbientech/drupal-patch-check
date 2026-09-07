# Drupal Patch Check

A composer plugin that tells you which of your site's Drupal patches still apply
after an update, and which ones a release has already fixed. It re-rolls
the ones that broke.

## Remote service call

The plugin cannot judge a patch on its own. Every run posts to
[`api.tresbien.tech/v1/composer/scan`](https://api.tresbien.tech/v1/composer/scan), which holds a mirror of every
drupal.org release and does the work. That service is owned and run by 
[Très Bien Tech](https://tresbien.tech), a long time Drupal contributor. 

What it sends, from `composer.json`: `require`, `require-dev`,
`minimum-stability` and `prefer-stable`. From the two require blocks it
takes only the packages your lock says came from packages.drupal.org, plus
core. From the lock it takes one trimmed entry each for those same packages.
Then the text of every patch you declare, with the package and the path each
one was declared against.

So a private module, a company-hosted vendor package or a path repository is
never named, and your `repositories`, `config`, `autoload` and `scripts`
blocks are not sent at all. A patch declared as a URL is fetched over your
own network, so a patch kept on a company host is checked like any other.

Your patch titles never go. The service echoes a title back and reads
nothing of it, so the plugin keeps yours and puts them on the report itself.

The paths do go, and one like `patch/acme_dam/CUP-1341_preview.patch` says
more than you may want. Set `private-paths` and the request holds `p0`, `p1`
instead, for a local path and a company-hosted URL alike. A drupal.org URL
is left as written, because the service reads the merge request in it to
find the diff a re-roll starts from.

The patch text itself is what the service judges, so it is still sent as
written, comments and all. Hiding a path is worth less than it looks when
the diff itself repeats the same words.

Run this in any site to print the exact request yours would send:

```
composer drupatch:check --dry-run
```

## Install

```
composer require --dev tresbientech/drupal-patch-check

# write access is needed to reroll
composer config allow-plugins.tresbientech/drupal-patch-check true
```

Installing it runs nothing and sends nothing.

## The commands

```
composer drupatch:check             judges every patch, writes nothing
composer drupatch:reroll [--update] writes what merges
```

Both take `--target`, `--package`, `--patch`, `--strict`, `--dry-run` and
`--format`; only the re-roll takes the two that write. `--target latest`
plans against the newest core your own constraint allows.

```
Drupal Patch Check: 3 patches against the releases this site installs

  acme/private_module 8.2.5   6 patches skipped (not a drupal.org project)

  drupal/pathauto 1.12.0   2 applies
     #1 · applies   Add path alias to translated node when saved    node_translated_2973478-4.patch
     #2 · applies   Make automatic URL alias state language-aware   pathauto_multilanguage_state_fi…
                    context drifted, needed: git apply -p1 -C1 --ignore-whitespace --recount

  drupal/content_sync 3.0.0-beta1   1 conflicts
        ! 4.0.0-rc2 supports 10.2.4; the site requires ^3.0@beta. Widen it to ^4.0@RC.
     #1 ! conflicts Drupal 10 compatibility                         content_sync_d10_compatiblity.p…
                    content_sync.info.yml:2: patch failed

  patches: 2 applies, 1 conflicts
  composer already applied these patches to your files

  Next:  composer drupatch:reroll   writes the re-roll
```

## Verdicts

| Verdict | Meaning |
| --- | --- |
| `applies` | The patch applies to the release and its fix is not upstream. Keep it. |
| `merged` | The fix is already in the release. Drop the entry. |
| `conflicts` | The patch does not apply and its fix is not upstream. |
| `unknown` | The patch was sent and came back without a verdict, and the row says why. |
| `skipped` | The patch was never sent, so it has no verdict. |

A patch can apply and still leave a file the site cannot load, which a merge
that keeps a duplicate import does for example. PHP, YAML, JSON, JavaScript and Twig are
read. The verdict stays `applies`, because the patch applied. A note under
the row opens with `broken syntax` and gives the file and the line, the
headline counts those apart from the ones that work, and the run exits
non-zero.

## Conflict files

A re-roll that merges cleanly replaces the patch file. One that leaves
markers is written as `.conflict.patch` beside it and is never referenced
from your declarations, so a half-merged patch never gets installed.

Inside, each open region falls between a `# drupatch region N file` line and
a `# drupatch end N file` line, with the release side and the patch side as
merge markers. Replace the text between the two sentinels with the code you
want, or leave it empty to drop the region, then run `composer
drupatch:reroll` again. The report gives every region as its file and index,
so you can decide one from the report alone.

An adopted URL patch is written under `patch/<project>/`, or wherever
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

Exit 0 means nothing needs work, 1 means a patch or a package does, 2 means
the plan could not be fetched. `--strict` also fails on a patch that could
not be judged. `--format=json` and `--format=github` keep stdout
machine-readable and put every person-facing note on stderr.

## Settings

Every setting lives under `extra.drupal-patch-check` in your composer.json.

```json
{
  "extra": {
    "drupal-patch-check": {
      "hook": true,
      "patch-directory": "patches",
      "private-paths": true
    }
  }
}
```

| Key | Default | What it does |
| --- | --- | --- |
| `hook` | `false` | Check patches after every `composer update`. |
| `patch-directory` | `patch` | Where an adopted URL patch is written. |
| `private-paths` | `false` | Sends a placeholder for every patch path of your own. |

`composer drupatch:check --dry-run` prints the request, so you can see any of
these took effect.

## Requirements

PHP 8.1 or newer and Composer 2.3 or newer. It adds no runtime dependency
beyond `ext-json`, because it runs inside your own composer process.

## License

MIT.
