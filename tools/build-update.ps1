param(
    [Parameter(Mandatory = $true)]
    [string] $Version,

    [Parameter(Mandatory = $true)]
    [string] $BaseUrl,

    [string] $ReleaseDate = (Get-Date -Format "yyyy-MM-dd"),
    [string[]] $Notes = @("Atualizacao do sistema"),
    [string[]] $Exclude = @(),
    [switch] $DeleteBeforeCopy
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$buildDir = Join-Path $root "build"
$stagingDir = Join-Path $buildDir "creator-outreach-$Version"
$zipPath = Join-Path $buildDir "creator-outreach-$Version.zip"
$manifestPath = Join-Path $buildDir "manifest.json"

if (Test-Path $stagingDir) {
    Remove-Item -LiteralPath $stagingDir -Recurse -Force
}
New-Item -ItemType Directory -Force $stagingDir | Out-Null
New-Item -ItemType Directory -Force $buildDir | Out-Null

$excludeDirs = @(
    ".git",
    "build",
    "storage",
    "node_modules"
)

$excludeFiles = @(
    ".env"
)

$excludeRelative = @()
foreach ($item in $Exclude) {
    $excludeRelative += ($item -replace "\\", "/").TrimStart("/")
}

Get-ChildItem -LiteralPath $root -Force | ForEach-Object {
    if ($excludeDirs -contains $_.Name -or $excludeFiles -contains $_.Name) {
        return
    }

    $target = Join-Path $stagingDir $_.Name
    if ($_.PSIsContainer) {
        Copy-Item -LiteralPath $_.FullName -Destination $target -Recurse -Force
    } else {
        Copy-Item -LiteralPath $_.FullName -Destination $target -Force
    }
}

foreach ($relative in $excludeRelative) {
    $target = Join-Path $stagingDir ($relative -replace "/", [System.IO.Path]::DirectorySeparatorChar)
    if (Test-Path $target) {
        Remove-Item -LiteralPath $target -Recurse -Force
    }
}

$versionFile = Join-Path $stagingDir "VERSION"
Set-Content -LiteralPath $versionFile -Value $Version -NoNewline -Encoding UTF8

if (Test-Path $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}

Compress-Archive -Path (Join-Path $stagingDir "*") -DestinationPath $zipPath -Force
$hash = (Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash.ToLowerInvariant()
$packageUrl = ($BaseUrl.TrimEnd("/") + "/creator-outreach-$Version.zip")
$deleteList = @()
if ($DeleteBeforeCopy) {
    Get-ChildItem -LiteralPath $stagingDir -Recurse -File | ForEach-Object {
        $relative = $_.FullName.Substring($stagingDir.Length + 1) -replace "\\", "/"
        if ($relative -ne ".env" -and -not $relative.StartsWith("storage/") -and -not $relative.StartsWith(".git/")) {
            $deleteList += $relative
        }
    }
}

$manifest = [ordered]@{
    version = $Version
    released_at = $ReleaseDate
    package_url = $packageUrl
    sha256 = $hash
    notes = $Notes
    delete = $deleteList
}

$manifestJson = $manifest | ConvertTo-Json -Depth 6
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($manifestPath, $manifestJson, $utf8NoBom)

Write-Host "Pacote gerado:"
Write-Host "ZIP:      $zipPath"
Write-Host "Manifest: $manifestPath"
Write-Host "SHA-256:  $hash"
