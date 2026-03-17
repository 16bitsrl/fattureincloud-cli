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
php fic app:build fic --build-version=1.0.0
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
- When updating the agent skill, verify command names against actual `fic fic:list` output

## Updating the spec

```bash
./bin/update-spec.sh              # latest from master
./bin/update-spec.sh v2.1.8       # specific tag
```

After updating, rebuild the PHAR and commit.
