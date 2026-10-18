# Run ONLY after the user says "deployed" / "na-deploy ko na" / "live na".
# Moves deploy/baseline-manifest.json to the exact state of the files in the LAST package delivered
# (deploy/last-package.json), not to whatever the project looks like now.
# Rule: malbcoff-vault/Plan/Deploy Rule.md

. "$PSScriptRoot\_common.ps1"

if (-not (Test-Path -LiteralPath $script:LastPkgFile)) { throw 'deploy\last-package.json not found: no unconfirmed package to confirm.' }
$baseline = Read-Json $script:BaselineFile
$last     = Read-Json $script:LastPkgFile
$files    = ConvertTo-Hashtable $baseline.files
foreach ($p in $last.files.PSObject.Properties) { $files[$p.Name] = $p.Value }

$applied = New-Object Collections.Generic.List[string]
foreach ($m in @($baseline.migrations_applied)) { $applied.Add($m) }
foreach ($m in @($last.migrations)) { if (-not $applied.Contains($m)) { $applied.Add($m) } }

$n       = [int]($baseline.version -replace '\D', '') + 1
$version = "v$n"
$today   = Get-Date -Format 'yyyy-MM-dd'
$history = @($baseline.history) + @([ordered]@{ version = $version; confirmed_at = $today; package = (Split-Path -Leaf $last.folder); files_in_package = @($last.files.PSObject.Properties).Count })

Write-Json ([ordered]@{
    version            = $version
    confirmed_at       = $today
    files              = $files
    migrations_applied = @($applied)
    history            = $history
}) $script:BaselineFile

Remove-Item -LiteralPath $script:LastPkgFile -Force
if (Test-Path -LiteralPath $script:NotesFile) { Remove-Item -LiteralPath $script:NotesFile -Force }
Write-Host "Baseline moved to $version ($today) from package $(Split-Path -Leaf $last.folder): $(@($last.files.PSObject.Properties).Count) file(s), $(@($last.migrations).Count) migration(s)."
