# Drupal Patch Check

A composer plugin that checks the patches a Drupal site declares. Every
patch comes back with a verdict: the fix already shipped, the patch still
applies, or it needs a re-roll. A package's patches are judged as a stack, in the order composer applies
them. Each one is checked against the tree the patches before it left. 

Run it as a command in CI. It can also print one line per patch after every
`composer update`, once your composer.json turns that on.

## How it works

The plugin reads the patches your composer.json or your patches file
declares. It turns each one into text, from disk or from the URL you
declared, fetched over your own network. That text goes to the service
along with a trimmed view of your composer files.

The service applies them against the release each package installs, or
against the release your `--target` would bring in. It answers with one
[verdict](#verdicts) per patch. Nothing on your site changes unless you pass
a write flag.

It does not scan your code for deprecated API use. [Upgrade
Status](https://www.drupal.org/project/upgrade_status) does that, and
[Drupal Rector](https://www.drupal.org/project/rector) rewrites much of
what it finds.

## Install

```
composer require --dev tresbientech/drupal-patch-check
composer config allow-plugins.tresbientech/drupal-patch-check true
```

Installing it runs nothing. The plugin sends your patch data to
`api.tresbien.tech` only when you run the command, or when you turn the
update hook on. Read [What leaves the site](#what-leaves-the-site) first,
and run `composer drupatch:check --dry-run` to print the request your
own site would send.

## The commands

```
composer drupatch:check                       judges every patch, writes nothing
composer drupatch:reroll [--update] [--force] writes what merges
```

`composer drupal-patch-check` still runs the check. Both commands take the
same options for the target, the scope and the shape; only `drupatch:reroll`
takes the two that write.

Patches are grouped under their package, worst package first. Each row has
the patch's number in its package, a mark, the verdict, the patch title and
the file it came from. The number is the order composer applies the patches
in. A package the run skipped is listed before the groups; a re-roll run
leaves that list to `drupatch:check` and prints what it wrote.

```
Drupal Patch Check: 5 patches for a move from core 11.3.2 to 11.4.5

  acquia/cohesion 8.2.5   2 patches skipped (not a drupal.org project)

  drupal/webform 6.2.9 → 6.3.2   1 conflicts, 1 applies
     #1 ! conflicts  Allow numeric machine names in handlers      webform-numeric.patch
     #2 · applies    Fix the alter hook                           webform-alter.patch
                     judged with only the part of #1 that applied

  drupal/domain 2.0.1   1 unknown
        ! 2.1.0 supports 11.4.5; the site requires ^2.0. Widen it to ^2.1.
     #1 ? unknown    Domain access on entity clone                domain-clone.patch
                     drupal/domain has no release for 11.4.5

  drupal/paragraphs 1.17.0 → 1.19.0   1 applies
     #1 · applies    Drag handle keyboard access                  paragraphs-a11y.patch

  drupal/token 1.15.0   1 merged
     #1 ✓ merged     Cache tag on token replacement               token-cache.patch

  patches: 1 conflicts, 2 applies, 1 merged, 1 unknown

  Next:  composer drupatch:reroll            writes the re-roll
         composer drupatch:reroll --update   drops the merged entry from composer.json
```

Options both commands take:

| Option | What it does |
| --- | --- |
| `--target=11.4.5` | Judges the patches against the releases that core version would bring in. |
| `--target=latest` | The same, against the newest core release your own constraint allows. |
| `--strict` | Also fails on a patch that could not be judged, and on a run that judged none. |
| `--package=drupal/webform` | Narrows the report, what is written and the exit code to this package; repeatable, and `webform` works too. |
| `--patch=patches/fix.patch` | Narrows the same to one patch, named by the source the site declares, a path or a URL; repeatable, and combines with `--package`. |
| `--format=json` | Prints the plan as one JSON object; `--json` does the same. |
| `--format=github` | Prints each verdict as a GitHub Actions annotation on the line that declares the patch. |
| `--dry-run` | Prints the request that would be sent and stops, without asking the service or writing anything. |

`drupatch:reroll` replaces each patch file whose patch no longer applies
with its re-roll, and writes a conflicted merge beside it as
`<name>.conflict.patch`. On every run it also reads the conflict files it
finds: an untouched one changes nothing, an edited one sends the regions you
decided and the finished patch replaces the file. Its own options:

| Option | What it does |
| --- | --- |
| `--decisions=decisions.json` | Sends the regions a JSON document decides, `-` to read it from stdin. Merged with the conflict files; on a region both decide, the document wins and the run says so. |
| `--update` | Also rewrites the patch declarations: drops what is already in the release, adopts the ones declared as a URL, points the rest at their re-rolls. A URL patch is fetched only when its re-roll merged something; when no hunk merged, nothing is written and the run points at the merge request or the issue. |
| `--force` | Replaces a patch file git reports as changed or untracked, and lets `--update` rewrite a declaration file with uncommitted changes. |

A run that writes prints only the rows a person still has to look at, then
lists the files it wrote, the ones it would not, and any declaration it
rewrote. In `--format=json`, each row it wrote carries the file's path in
`reroll.path` and an empty `reroll.patch`: the diff is on disk, once.

With `--target`, the plugin asks composer which release each patched package
would move to. A bare run judges the releases the lock installs.

| Exit | Meaning |
| --- | --- |
| 0 | Nothing needs a person. Patches already in the release, and patches that could not be judged, are reported and do not fail. |
| 1 | A patch will not apply against the release it was judged against. |
| 2 | The plan could not be fetched. That reports a service outage rather than anything about your patches. |

## Composer update hook

The hook is off until you turn it on. Add this to your composer.json:

```json
{ "extra": { "drupal-patch-check": { "hook": true } } }
```

After `composer update`, the hook lists the patches whose fix is already in
the release, and the patches with no release to judge them against.

```
Drupal Patch Check: 1 unknown, 1 merged after this update
  ? unknown       drupal/domain   Domain content translations permissions
                  the lock does not install drupal/domain, so there is no release to judge this patch against
  ✓ merged        drupal/token 1.15.0  Cache tag on token replacement
  run `composer drupatch:check` for the detail, or `--target <version>` before a core upgrade
  Next:  composer drupatch:reroll --update   drops the merged entry from composer.json
```

When every patch applies it prints nothing. Only a literal `true` turns the
hook on. The command works either way, and `composer update --no-plugins`
skips the hook for one run.

## Where an adopted patch is written

`drupatch:reroll --update` can take a patch the site declares as a URL and
write it into the site, under `patches/<project>/`. Sites that keep their
patches somewhere else say so:

```json
{ "extra": { "drupal-patch-check": { "patch-directory": "patchs" } } }
```

An adopted patch then lands in `patchs/<project>/`. The value is used as
written, so a site with no such key gets `patches`.

## Running it in CI

The useful run is scheduled and forward-looking: do the patches still work
against the releases this site could install today? `--target latest` asks
for the newest core your own constraint allows.

```yaml
# weekly
- run: composer drupatch:check --target latest --format json > patch-check.json
- if: always()
  run: jq -r '.summary | "\(.counts.conflicts // 0) conflicts, exit \(.exit_code)"' patch-check.json
- uses: actions/upload-artifact@v4
  if: always()
  with: { name: patch-check, path: patch-check.json }
```

Each invocation is a separate scan, so read the JSON rather than running the
command again in another format. The `summary` object holds `counts`,
`conflicts`, `merged`, `blocked`, `exit_code` and the `next` commands the
table footer would print. On GitHub Actions, `--format github` puts the same
verdicts on the lines that declare the patches.

## Verdicts

| Verdict | Meaning |
| --- | --- |
| `applies` | The patch applies to the release and its fix is not upstream. Keep it. |
| `merged` | The fix is already in the release. Drop the entry. |
| `conflicts` | The patch does not apply and its fix is not upstream. |
| `unknown` | The patch was sent and came back without a verdict, and the row says why. The lock does not install the package, it has no release for the target, or the release tag is not on the service's mirror yet. |
| `skipped` | The patch was never sent, so it has no verdict. Its package has no drupal.org release, or the run could not turn its source into a patch. |

A re-roll that merges cleanly is written as `.patch`. One that leaves
conflict markers is written as `.conflict.patch` and is never referenced
from the patch declarations.

## Conflict files

A conflict file holds the hunks that merged, then each open region between a
`# drupatch region N file` line and a `# drupatch end N file` line. Inside
are the release side and the patch side as merge markers. Replace the text
between the two sentinel lines with the code you want, or leave it empty to
drop the region. Then run `composer drupatch:reroll` again. The finished
patch replaces the file the site declares.

The report prints the coordinates of every open region under the file it
wrote, one line per region, so a decision can be written without opening the
conflict file:

```
  re-rolled with conflicts:
    patches/webform/fix.conflict.patch  (2 regions to decide)
      src/Form.php region 0
      src/Batch.php region 0
```

When the release removed the file a patch changes, there is nothing to merge
into and no region to decide. The conflict file then holds the patch's hunks
and no markers, and the report names the file:

```
    patchs/claro.conflict.patch  (the release removed core/themes/claro/claro.theme)
```

The code is often still there under a new name. Core 11.4 moved the claro
theme's hooks from `claro.theme` into `src/Hook/ClaroHooks.php`, so that patch
needs aiming at the new file rather than dropping.

A script or an agent can decide the same regions without editing the file.
`--decisions` takes a JSON document, one object with a `decisions` list. Each
entry names the patch by the source the site declares, the file, the region
index the report printed, and either a `choice` of `release` or `patch` or
the `text` to put there.

```json
{"decisions": [
  {"source": "patches/webform/fix.patch", "file": "src/Form.php", "region": 0, "choice": "release"},
  {"source": "patches/webform/fix.patch", "file": "src/Form.php", "region": 1, "text": "  $x = 1;"}
]}
```

A decision naming a region the release no longer has in conflict decides
nothing, and the run stops before writing and says how many did.

## Drupal core references

A row can have `core` lines under it. The patch applies, and the lines it
adds use something Drupal core changed in the target release: a removed
class, a class now under another name, or a call whose argument count no
longer fits. Each line says what changed and gives the change record that
documents it. `core deprecated` counts what still works at the target and is
scheduled for removal. These lines never change the exit code.

## Patch managers

The plugin reads the declaration and applies nothing. These shapes are read:

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
them. It sends five keys of `composer.json`, a trimmed `composer.lock` entry
for each package that came from drupal.org, and the text of each patch. A
patch declared as a URL is fetched over your own network, so a patch kept on
a company host is checked like any other.

Run `composer drupatch:check --dry-run` to print the exact request your
own site would send. It prints the same body a real run posts.

## Requirements

PHP 8.1 or newer and Composer 2.3 or newer. The plugin adds no runtime
dependency beyond `ext-json`, because it runs inside the site's own composer
process.

## License

MIT. See [LICENSE](LICENSE).
