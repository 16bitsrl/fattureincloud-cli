# Install the Fatture in Cloud CLI (fic) as a single static binary on Windows.
#
#   irm https://raw.githubusercontent.com/16bitsrl/fattureincloud-cli/main/install.ps1 | iex
#
# Environment variables:
#   FIC_VERSION      version to install, e.g. 1.2.0 (default: latest release)
#   FIC_INSTALL_DIR  target directory (default: %LOCALAPPDATA%\fic\bin)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$repo = '16bitsrl/fattureincloud-cli'
$asset = 'fic-windows-x86_64.exe'
$version = "$env:FIC_VERSION".TrimStart('v')
$installDir = if ($env:FIC_INSTALL_DIR) { $env:FIC_INSTALL_DIR } else { Join-Path $env:LOCALAPPDATA 'fic\bin' }

$baseUrl = if ($version) {
    "https://github.com/$repo/releases/download/v$version"
} else {
    "https://github.com/$repo/releases/latest/download"
}

$tmp = Join-Path ([IO.Path]::GetTempPath()) ([Guid]::NewGuid())
New-Item -ItemType Directory -Path $tmp | Out-Null

try {
    Write-Host "Downloading $asset from github.com/$repo..."
    Invoke-WebRequest -UseBasicParsing -Uri "$baseUrl/$asset" -OutFile "$tmp\fic.exe"
    Invoke-WebRequest -UseBasicParsing -Uri "$baseUrl/checksums.txt" -OutFile "$tmp\checksums.txt"

    $line = Get-Content "$tmp\checksums.txt" | Where-Object { ($_ -split '\s+')[1] -replace '^\*', '' -eq $asset }
    if (-not $line) { throw "No checksum found for $asset" }

    $expected = ($line -split '\s+')[0]
    $actual = (Get-FileHash "$tmp\fic.exe" -Algorithm SHA256).Hash
    if ($actual -ne $expected) { throw "Checksum mismatch for $asset" }

    New-Item -ItemType Directory -Force -Path $installDir | Out-Null
    Move-Item -Force "$tmp\fic.exe" (Join-Path $installDir 'fic.exe')
} finally {
    Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue
}

$installed = & (Join-Path $installDir 'fic.exe') --version
Write-Host "Installed $installed to $installDir\fic.exe"

$userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
if (($userPath -split ';') -notcontains $installDir) {
    [Environment]::SetEnvironmentVariable('Path', "$installDir;$userPath", 'User')
    $env:Path = "$installDir;$env:Path"
    Write-Host "Added $installDir to your user PATH (open a new terminal to pick it up)."
}

Write-Host ''
Write-Host 'Next steps:'
Write-Host '  fic auth:login    # sign in with your Fatture in Cloud account'
Write-Host '  fic               # overview of commands and docs'
