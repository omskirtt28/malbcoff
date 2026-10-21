# Shared helpers for the patch-delivery scripts (see malbcoff-vault/Plan/Deploy Rule.md).
# Dot-source this file: . "$PSScriptRoot\_common.ps1"

$ErrorActionPreference = 'Stop'
$script:ProjectRoot  = Split-Path -Parent $PSScriptRoot
$script:BaselineFile = Join-Path $PSScriptRoot 'baseline-manifest.json'
$script:LastPkgFile  = Join-Path $PSScriptRoot 'last-package.json'
$script:NotesFile    = Join-Path $PSScriptRoot 'package-notes.txt'

# Folders that are never part of a deploy package (matched on any path segment, exact name).
$script:ExcludeDirs  = @('.git', '.github', '.vscode', '.idea', 'malbcoff-vault', 'node_modules', 'backups', 'logs',
                         'uploads', 'cache', 'caches', 'tmp', 'tests', 'test', 'output', '.playwright-cli', 'deploy', 'database', 'design-reference')
# Files that are never part of a deploy package (wildcards, matched on the file name).
$script:ExcludeFiles = @('CLAUDE.md', '*.md', '.gitignore', 'preview.html', 'NGINX_SECURITY_RULES.example.conf',
                         '*.zip', '*.sql', '*.log', '*.bak', '*.backup', '*.local.php', '.env', '.env.*',
                         'Thumbs.db', '.DS_Store', 'desktop.ini')

function Get-FileSha256([string]$Path) {
    (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLower()
}

# Returns an ordered map: relative path (forward slashes) -> full path, for every deployable app file.
function Get-AppFiles {
    $root = $script:ProjectRoot.TrimEnd('\')
    $map  = [ordered]@{}
    Get-ChildItem -LiteralPath $root -Recurse -File -Force | Sort-Object FullName | ForEach-Object {
        $rel  = $_.FullName.Substring($root.Length + 1).Replace('\', '/')
        $segs = $rel.Split('/')
        $skip = $false
        for ($i = 0; $i -lt $segs.Length - 1; $i++) { if ($script:ExcludeDirs -contains $segs[$i]) { $skip = $true; break } }
        if (-not $skip) { foreach ($pat in $script:ExcludeFiles) { if ($_.Name -like $pat) { $skip = $true; break } } }
        if (-not $skip) { $map[$rel] = $_.FullName }
    }
    $map
}

function Read-Json([string]$Path) { Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json }

function Write-Json($Object, [string]$Path) {
    $json = $Object | ConvertTo-Json -Depth 8
    [IO.File]::WriteAllText($Path, $json, (New-Object Text.UTF8Encoding($false)))
}

# Baseline "files" is a PSCustomObject after ConvertFrom-Json; turn it into a hashtable.
function ConvertTo-Hashtable($Obj) {
    $h = [ordered]@{}
    if ($null -ne $Obj) { foreach ($p in $Obj.PSObject.Properties) { $h[$p.Name] = $p.Value } }
    $h
}
