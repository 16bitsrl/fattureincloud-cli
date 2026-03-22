# Fatture in Cloud CLI

## Architecture

Laravel Zero 12 CLI application using `spatie/laravel-openapi-cli` to auto-generate commands from the FattureInCloud OpenAPI spec.

## Key files

- `fic` — development entry point (like `artisan`)
- `builds/fic` — compiled PHAR for distribution
- `resources/openapi/fattureincloud.yaml` — the enriched OpenAPI spec (flattened, all models inline)
- `app/Providers/AppServiceProvider.php` — registers the OpenAPI spec with `.auth()` for dynamic token, NOT `.bearer()`
- `app/Services/TokenStore.php` — reads/writes `~/.config/fattureincloud-cli/auth.json` and `config.json`
- `app/Commands/Auth/` — login, logout, status, refresh commands
- `app/Commands/Company/` — set/current company context commands
- `skills/fattureincloud/SKILL.md` — agent skill file
- `bin/check-phar-sync.sh` — verifies that committed `builds/fic` matches the source version
- `bin/release.sh` — release helper that rebuilds `builds/fic`, commits it, tags, and pushes
- `bin/update-spec.sh` — script to update the OpenAPI spec from upstream

## Development

```bash
# Run locally
php fic <command>

# Run tests
composer test

# Format code
composer format

# Build PHAR
php fic app:build fic --build-version=1.0.1

# Check PHAR sync
./bin/check-phar-sync.sh

# Prepare and push a release
./bin/release.sh 1.0.1
```

## Release process

Releasing has two phases: **prep** (your commit) and **release** (the script).

### Phase 1 — prep commit

1. Update `CHANGELOG.md` with the new version and changes
2. Update README and skill docs if commands or workflows changed
3. Run `composer test` to make sure tests pass
4. Run `composer format` (Pint) to fix code style
5. Commit everything: `git add -A && git commit -m "description of changes"`

Do **NOT** update `VERSION` or `builds/fic` manually — `release.sh` handles both.

### Phase 2 — release

```bash
./bin/release.sh 1.0.2            # or --no-push to skip pushing
```

The script (`bin/release.sh`) does the following automatically:
1. Checks the working tree is clean (fails if dirty)
2. Checks the tag doesn't already exist
3. Updates `VERSION` to the given version
4. Updates the `version:` field in `skills/fattureincloud/SKILL.md`
5. Builds the PHAR (`builds/fic`) with the correct version
6. Runs `bin/check-phar-sync.sh` to verify the build
7. Commits VERSION + builds/fic + SKILL.md as "Release vX.Y.Z"
8. Tags `vX.Y.Z`
9. Pushes main and the tag (unless `--no-push`)

### Summary

```
# 1. Make your changes, test, format, commit
composer test && composer format
git add -A && git commit -m "description of changes"

# 2. Release (updates VERSION, builds PHAR, tags, pushes)
./bin/release.sh X.Y.Z
```

## Important notes

- Provider registration is manual in `config/app.php` — Laravel Zero disables auto-discovery
- The `OpenApiCliServiceProvider` is registered in `config/app.php` providers array, AFTER `AppServiceProvider`
- Use `.auth()` callback, NOT `.bearer()` — the token is loaded dynamically from TokenStore
- `resources/` directory MUST be in `box.json` for PHAR builds
- `resource_path()` works in PHARs but paths must be relative
- All runtime deps are in `require-dev` because the distributed artifact is a compiled PHAR
- `box.json` has `"exclude-dev-files": false` so dev deps get bundled into the PHAR
- `composer.json` `require` only has `php: ^8.2` — this avoids dependency conflicts with `composer global require`
- `composer.json` `bin` points to `builds/fic` (the PHAR) for `composer global require` to work
- Never tag manually for a release; use `./bin/release.sh X.Y.Z` so `builds/fic` stays in sync
- When updating the agent skill, verify command names against actual `fic api:list` output

## Updating the spec

```bash
./bin/update-spec.sh              # latest from master
./bin/update-spec.sh v2.1.8       # specific tag
```

After updating, rebuild the PHAR and commit, or use `./bin/release.sh X.Y.Z` if this is part of a release.
