# CLI for Fatture in Cloud

A CLI for the [Fatture in Cloud](https://www.fattureincloud.it) API, with full API coverage, agent-oriented docs, and a practical XML import workflow for fattura elettronica files.

Website: [16bitsrl.github.io/fattureincloud-cli](https://16bitsrl.github.io/fattureincloud-cli/). See `CHANGELOG.md` for release notes starting from `1.0.1`.

CLI is developed by [16bit](https://16bit.it) and is not a Fatture in Cloud product.

## Install

### macOS and Linux

```bash
curl -fsSL https://raw.githubusercontent.com/16bitsrl/fattureincloud-cli/main/install.sh | sh
```

This downloads the single static binary for your platform from the latest GitHub release, verifies its checksum, and installs it to `~/.local/bin/fic`. No PHP or Composer needed.

Set `FIC_VERSION=1.2.0` to install a specific version, or `FIC_INSTALL_DIR=/usr/local/bin` to pick another directory.

### Windows (PowerShell)

```powershell
irm https://raw.githubusercontent.com/16bitsrl/fattureincloud-cli/main/install.ps1 | iex
```

### Manual download

Download `fic-<os>-<arch>` from the [latest release](https://github.com/16bitsrl/fattureincloud-cli/releases/latest), make it executable, and put it on your `PATH`:

```bash
chmod +x ./fic-linux-x86_64
mv ./fic-linux-x86_64 ~/.local/bin/fic
```

### Composer

If you already have PHP 8.2+:

```bash
composer global require 16bitsrl/fattureincloud-cli
```

### Upgrade

```bash
fic upgrade              # latest version
fic upgrade --check      # only tell whether a newer version is available
fic upgrade 1.2.0        # a specific version
```

The download is verified against the release checksums before replacing the binary. Composer installs upgrade with `composer global update 16bitsrl/fattureincloud-cli`.

Once a day fic checks whether a newer version exists and, if so, says so on stderr after the command output (JSON on stdout is untouched). Set `FIC_NO_UPDATE_CHECK=1` to turn this off; it is also off when `CI` is set.

## Quick start

```bash
# Sign in with your Fatture in Cloud account (opens the browser)
fic auth:login

# Set your default company
fic company:set

# Overview of commands and docs
fic

# Explore the generated API commands
fic api:list

# Search helpers
fic clients:search acme --company-id=12345
fic suppliers:search studio --company-id=12345
fic products:search consulting --company-id=12345
```

`fic auth:login` shows a link and a short code: open the link, sign in to Fatture in Cloud, enter the code and approve. No secrets are stored in the binary, nothing runs on a local port, and it works over SSH too. Tokens are refreshed automatically.

### Permissions

The first sign-in grants **read-only** access. Write access is asked for only when it is needed:

```bash
fic api:create-client --company-id=12345 --input='{"data":{"name":"Acme"}}'
# Missing permission: this command needs entity.clients:a.
# Grant it with: fic auth:grant entity.clients:a

fic auth:grant entity.clients:a     # approve in the browser, then run the command again
fic auth:grant --full               # or full access to everything, without signing in again
fic auth:status                     # current permissions
```

If you already know what you need, ask for it at sign-in with `fic auth:login --grant=issued_documents.invoices:a,entity.clients:a`, or use `fic auth:login --full` for full access to everything. Running `fic auth:login` again goes back to read-only.

Other ways to sign in:

- `fic auth:login --token=TOKEN` with a manual token from [secure.fattureincloud.it/api](https://secure.fattureincloud.it/api)
- `fic auth:login --client-id=ID --client-secret=SECRET` to use your own OAuth app (redirect URI `http://localhost:8511/callback`)
- `fic auth:login --no-browser` to print the link without opening a browser

## Using fic with AI agents

Point your agent at this repository, or tell it:

> Install the CLI for Fatture in Cloud with `curl -fsSL https://raw.githubusercontent.com/16bitsrl/fattureincloud-cli/main/install.sh | sh`, then run `fic` and follow its instructions.

Once installed, everything an agent needs is built into the binary:

- `fic` prints a short overview: sign-in status, key commands, where to learn more
- `fic skill` prints the full usage guide (filters, pagination, workflows, errors, quotas)
- `fic skill --list` and `fic skill TOPIC` print the detailed reference topics
- `fic api:<command> --help` documents each API command

The first sign-in needs a human: the agent runs `fic auth:login` and shows you the link and code it prints, you approve in the browser.

## XML e-invoice import

The official API does not ingest raw XML, so the CLI recreates documents through JSON APIs.

For issued XML imports, the recreated document is treated as an electronic invoice by default.

```bash
# Preview a single XML
fic einvoice:import /absolute/path/to/fattura.xml --company-id=12345 --dry-run

# Preview a signed XML.p7m file
fic einvoice:import /absolute/path/to/fattura.xml.p7m --company-id=12345 --dry-run

# Import a folder of XML files (direction is auto-detected from XML content)
fic einvoice:import /absolute/path/to/xml-dir --company-id=12345 --yes
```

The import supports recap before creation, dry runs, `.xml` and `.xml.p7m` inputs, client/supplier matching, embedded attachment carry-over, automatic direction inference (issued/received/self-invoice), and structured mapping for supported e-invoice fields before falling back to `ei_raw`. If neither XML party matches the selected company, the import is rejected.

## Agent skill

The usage guide is also packaged as an [agent skill](https://skills.sh), so coding agents load it automatically when relevant.

```bash
fic install-skill
```

Skill sources live in `skills/fattureincloud/`.

## Handy examples

```bash
fic api:list-issued-documents --company-id=12345 --type=invoice --sort=-date --json
fic api:list-clients --company-id=12345 --q="vat_number = 'IT01234567890'" --json
fic api:send-e-invoice --company-id=12345 --document-id=99
```

## API coverage

| Resource | Commands |
|---|---|
| Clients | list, get, create, modify, delete, info |
| Suppliers | list, get, create, modify, delete |
| Products | list, get, create, modify, delete |
| Issued documents | list, get, create, modify, delete, totals, email, transform, join |
| E-Invoices | send, verify XML, get XML, rejection reason |
| Received documents | list, get, create, modify, delete, totals, pending |
| Receipts | list, get, create, modify, delete, monthly totals |
| Taxes (F24) | list, get, create, modify, delete, attachments |
| Cashbook | list, get, create, modify, delete |
| Archive | list, get, create, modify, delete, attachments |
| Settings | payment accounts/methods, VAT types, tax profile, templates |
| Info | cities, countries, currencies, languages, categories, etc. |
| Webhooks | list, create, get, modify, delete, verify |
| User | info, companies |
| Companies | info, plan usage |

## Testing

```bash
composer test
```

## Releasing a new version

Use the release script:

```bash
./bin/release.sh X.Y.Z
```

It will:

- build `builds/fic` with the requested version
- verify that `builds/fic` matches the source version
- commit `builds/fic`
- create tag `vX.Y.Z`
- push branch and tag

Then the GitHub release workflow will build the PHAR and static binaries for Linux, macOS, and Windows.

If you only want to verify that the committed PHAR is still aligned with the source version:

```bash
./bin/check-phar-sync.sh
```

CI also runs the same sync check on pushes and pull requests.

Users install with the install script (or `composer global require 16bitsrl/fattureincloud-cli`) and upgrade with `fic upgrade`.

## Updating the OpenAPI spec

```bash
./bin/update-spec.sh              # latest from master
./bin/update-spec.sh v2.1.8       # specific tag
fic clear-cache                   # clear cached spec and temporary files
```

## Credits

- Built with [Laravel Zero](https://laravel-zero.com) and [Spatie Laravel OpenAPI CLI](https://spatie.be/docs/laravel-openapi-cli)
- API by [Fatture in Cloud](https://developers.fattureincloud.it)
- Made by [Mattia Trapani](https://github.com/zupolgec) at [16bit S.r.l.](https://16bit.it)

## License

The MIT License (MIT). Please see [LICENSE.md](LICENSE.md) for more information.
