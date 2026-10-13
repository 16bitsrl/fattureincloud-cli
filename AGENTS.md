# CLI for Fatture in Cloud

## Just want to use the CLI?

If you were pointed here to use Fatture in Cloud (not to develop this CLI), do not clone or build anything:

1. Install the single binary: `curl -fsSL https://raw.githubusercontent.com/16bitsrl/fattureincloud-cli/main/install.sh | sh` (Windows: `irm https://raw.githubusercontent.com/16bitsrl/fattureincloud-cli/main/install.ps1 | iex`)
2. Run `fic` and follow its instructions (`fic auth:login`, then `fic skill` for the full usage guide).

The rest of this file is for developing the CLI itself.

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
- `skills/fattureincloud/SKILL.md` — agent skill file, also bundled in the PHAR and printed by `fic skill`
- `app/Commands/GuideCommand.php` — default command (`fic` with no arguments, also `fic --help`): agent-oriented overview
- `config/oauth.php` — built-in OAuth app used by `fic auth:login` (overridable with `FIC_CLIENT_ID`/`FIC_CLIENT_SECRET`)
- `install.sh` / `install.ps1` — binary installers (`curl | sh`, `irm | iex`), tested by `tests/Install/install-test.sh` and `.github/workflows/test-install.yml`
- `llms.txt` — entry point for agents pointed at the repository
- `site/` — public website (integration page, privacy policy, terms, App Store assets) published to GitHub Pages by `.github/workflows/pages.yml`
- `marketing/` — sources of the App Store screenshots; `marketing/render.sh` renders them into `site/assets/store/`
- Naming: the app is called "CLI" and is presented as "CLI for Fatture in Cloud" / "CLI per Fatture in Cloud". Fatture in Cloud brand rules forbid the brand inside the app name: use it only referentially, never in all caps
- `app/Services/OAuthScopes.php` — scope catalog (read-only/full presets) and required-scope logic from the spec `security` blocks
- `app/OpenApi/ScopedEndpointCommand.php` + `app/Providers/ScopedCommandsServiceProvider.php` — wrap the generated `api:*` commands to check granted scopes before calling the API (provider registered after `OpenApiCliServiceProvider`; kept outside `app/Commands` because that folder is auto-loaded)
- `app/Commands/UpgradeCommand.php` + `app/Services/Installation.php` — `fic upgrade`: detects how fic was installed (static binary, plain PHAR, Composer, source) and replaces the running file from the GitHub release; it exits right after the swap because the running PHAR is gone
- `app/Services/Releases.php` — latest version lookup and the daily update notice (cached in `update-check.json` in the config dir, printed on stderr by a `CommandFinished` listener in `AppServiceProvider`; skipped from source, with `FIC_NO_UPDATE_CHECK` or `CI`)
- `bin/check-phar-sync.sh` — verifies that committed `builds/fic` matches the source version
- `bin/release.sh` — release helper that rebuilds `builds/fic`, commits it, tags, and pushes
- `bin/update-spec.sh` — script to update the OpenAPI spec from upstream

## Project docs

`docs/` holds project decisions and plans (in Italian), e.g. `docs/agent-integration.md`. `docs/` is never committed: it is excluded locally through `.git/info/exclude`.

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
- `resources/` and `skills/` directories MUST be in `box.json` and in the copy list of `BuildCommand` for PHAR builds
- Inside the PHAR use `scandir()`, not `glob()`: glob does not see `phar://` paths
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
