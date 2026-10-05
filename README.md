# Osmium Store index

`index.json` is the catalogue every Osmium site reads to list and install services. It holds, for each
public repo in this org with a `service.json` at its root: its latest `vX.Y.Z` release tag, the commit that tag points at,
and the `service.json` at that commit.

It is generated, never edited by hand. `.github/workflows/build-index.yml` runs `build-index.php` every
15 minutes (and on demand) and commits `index.json` when something changed.

## Adding a service

Create a public repo in this org with a `service.json` at its root, and push a `vX.Y.Z` tag that matches the
`version` in its `service.json`. It appears in the index on the next run (up to 15 minutes), then in each
site's Store within its cache (10 minutes) - the Refresh button skips the site's cache.

To publish sooner, run the "Build index" workflow from the Actions tab.

## Removing a service

Delete or archive the repo. It drops out on the next run.

## Safety

- The build never publishes an empty catalogue, or one less than half the size of the current one - it
  fails instead. Delete `index.json` and re-run to force a deliberate shrink.
- It fails (and publishes nothing) on any GitHub error other than a missing file.
- Sites only list repos under their configured owner, and only install the commit pinned here.

## Run it locally

    php build-index.php            # needs PHP 8 with curl
    php build-index.php my-org     # another owner
