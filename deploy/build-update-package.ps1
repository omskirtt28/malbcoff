# Builds an INCREMENTAL deploy package: only files that are new or different from deploy/baseline-manifest.json
# (the state that is confirmed LIVE). Rule: malbcoff-vault/Plan/Deploy Rule.md
#
#   powershell -ExecutionPolicy Bypass -File deploy\build-update-package.ps1            # build
#   powershell -ExecutionPolicy Bypass -File deploy\build-update-package.ps1 -DryRun    # list only
#
# Never moves the baseline. Run deploy\confirm-deployed.ps1 only after the user says "deployed".
param([switch]$DryRun, [string]$OutputRoot = (Join-Path $env:USERPROFILE 'Desktop'))

. "$PSScriptRoot\_common.ps1"

if (-not (Test-Path -LiteralPath $script:BaselineFile)) {
    throw "deploy\baseline-manifest.json is missing. Rebuild the baseline first (see Plan/Deploy Rule.md)."
}
$baseline  = Read-Json $script:BaselineFile
$baseFiles = ConvertTo-Hashtable $baseline.files
$applied   = @($baseline.migrations_applied)

# ---- 1. which app files are new or changed vs. the live baseline ----
$app = Get-AppFiles
$new = New-Object Collections.Generic.List[string]
$changed = New-Object Collections.Generic.List[string]
$hashes = [ordered]@{}
foreach ($rel in $app.Keys) {
    $h = Get-FileSha256 $app[$rel]
    if (-not $baseFiles.Contains($rel))      { $new.Add($rel);     $hashes[$rel] = $h }
    elseif ($baseFiles[$rel] -ne $h)         { $changed.Add($rel); $hashes[$rel] = $h }
}
$gone = @($baseFiles.Keys | Where-Object { -not $app.Contains($_) })
$send = @($new) + @($changed) | Sort-Object

# ---- 2. migrations not yet applied on live ----
$migDir  = Join-Path $script:ProjectRoot 'database\migrations'
$newMigs = @()
if (Test-Path -LiteralPath $migDir) {
    $newMigs = @(Get-ChildItem -LiteralPath $migDir -Filter *.sql -File | Sort-Object Name | Where-Object { $applied -notcontains $_.Name })
}

Write-Host "Baseline version : $($baseline.version) (confirmed $($baseline.confirmed_at))"
Write-Host "New files        : $($new.Count)"
Write-Host "Changed files    : $($changed.Count)"
Write-Host "New migrations   : $($newMigs.Count) $(($newMigs | ForEach-Object Name) -join ', ')"
if ($gone.Count) { Write-Host "In baseline but no longer in project (NOT deleted on live): $($gone -join ', ')" }
if ($DryRun -or $send.Count -eq 0) {
    $send | ForEach-Object { $tag = if ($new.Contains($_)) { 'NEW    ' } else { 'CHANGED' }; Write-Host "  $tag $_" }
    if ($send.Count -eq 0 -and $newMigs.Count -eq 0) { Write-Host 'Nothing to deliver: the project equals the live baseline.' }
    if ($DryRun -or ($send.Count -eq 0 -and $newMigs.Count -eq 0)) { return }
}

# ---- 3. output folder ----
$stamp  = Get-Date -Format 'yyyy-MM-dd-HHmm'
$name   = "malbcoff-deploy-update-$stamp"
$folder = Join-Path $OutputRoot $name
$zip    = "$folder.zip"
if (Test-Path -LiteralPath $folder) { Remove-Item -LiteralPath $folder -Recurse -Force }
if (Test-Path -LiteralPath $zip)    { Remove-Item -LiteralPath $zip -Force }
New-Item -ItemType Directory -Path (Join-Path $folder 'deploy') -Force | Out-Null
foreach ($rel in $send) {
    $dest = Join-Path $folder $rel.Replace('/', '\')
    New-Item -ItemType Directory -Path (Split-Path -Parent $dest) -Force | Out-Null
    Copy-Item -LiteralPath $app[$rel] -Destination $dest
}

# ---- 4. deploy/migrations.sql (only NEW statements, original order) ----
$sql = New-Object Text.StringBuilder
[void]$sql.AppendLine("-- Malbcoff POS - NEW database changes since the live baseline $($baseline.version)")
[void]$sql.AppendLine("-- Package $name. Every section can be run more than once. Run in phpMyAdmin > SQL tab (select the database first).")
if ($newMigs.Count -eq 0) {
    [void]$sql.AppendLine('-- NO NEW DATABASE CHANGES in this package. Nothing to run.')
}
foreach ($m in $newMigs) {
    [void]$sql.AppendLine('')
    [void]$sql.AppendLine("-- ===== $($m.Name) =====")
    [void]$sql.AppendLine([IO.File]::ReadAllText($m.FullName).TrimEnd())
}
[IO.File]::WriteAllText((Join-Path $folder 'deploy\migrations.sql'), $sql.ToString(), (New-Object Text.UTF8Encoding($false)))

# ---- 5. deploy/README.txt (plain English) ----
$has  = { param($p) $send -contains $p }
$swChanged    = ($send | Where-Object { $_ -in @('sw.js', 'assets/js/sw-register.js', 'partials/pwa-register.php', 'partials/pwa-head.php', 'manifest.webmanifest', 'offline.html') }).Count -gt 0
$assetChanged = ($send | Where-Object { $_ -like 'assets/*' -or $_ -eq 'sw.js' }).Count -gt 0
$loginChanged = ($send | Where-Object { $_ -in @('login.php', 'change-password.php', 'core/Security.php') }).Count -gt 0
$htChanged    = & $has '.htaccess'
$notes = ''
if (Test-Path -LiteralPath $script:NotesFile) { $notes = [IO.File]::ReadAllText($script:NotesFile).Trim() }

$r = New-Object Collections.Generic.List[string]
function Add-Line([string]$t = '') { $r.Add($t) }
$sep = '=' * 70
Add-Line "MALBCOFF POS - UPDATE PACKAGE $stamp"
Add-Line $sep
Add-Line ''
Add-Line 'WHAT THIS IS'
Add-Line '------------'
Add-Line "A small update. It contains ONLY the files that are new or changed since the last update you confirmed"
Add-Line "(live baseline: $($baseline.version), confirmed $($baseline.confirmed_at)). Files that are already live are not in here."
Add-Line "Do NOT upload the 'deploy' folder (this README and migrations.sql) to the website."
Add-Line "Files: $($new.Count) new, $($changed.Count) changed.   Database changes: $(if ($newMigs.Count) { $newMigs.Count.ToString() + ' (see Step 1)' } else { 'NONE' })."
Add-Line ''
Add-Line 'BEFORE YOU START - BACKUP'
Add-Line '------------------------'
Add-Line "1. Copy the live versions of the files listed at the bottom into a folder OUTSIDE the website, for example"
Add-Line "   'old-files-$stamp'. You need them for a rollback."
if ($newMigs.Count) {
    Add-Line "2. Export the database: phpMyAdmin > select the database > Export > Quick > SQL > Go. Keep the .sql file."
} else {
    Add-Line "2. No database backup is needed for this update (no database change), but keep your normal backup."
}
Add-Line ''
Add-Line 'STEP 1 - DATABASE'
Add-Line '-----------------'
if ($newMigs.Count) {
    Add-Line "Do this BEFORE uploading the files."
    Add-Line "phpMyAdmin > select the Malbcoff database > SQL tab > open deploy/migrations.sql in a text editor, copy everything,"
    Add-Line "paste it into the box > Go. It is safe to run it twice. New sections in this package:"
    foreach ($m in $newMigs) { Add-Line "   - $($m.Name)" }
    Add-Line "If it shows an error, STOP, do not upload the files, and send the error text to your developer."
} else {
    Add-Line 'Nothing to do. This update has no database change.'
}
Add-Line ''
Add-Line 'STEP 2 - UPLOAD THE FILES'
Add-Line '-------------------------'
Add-Line "Upload everything in this folder EXCEPT 'deploy' to the website root, keeping the same folders"
Add-Line "(actions/, assets/, config/, core/, pages/, partials/ and the files in the root). Overwrite when asked."
if ($htChanged) {
    Add-Line ''
    Add-Line ".htaccess is in this update. If your live .htaccess has lines that belong to your host, do NOT replace it blindly:"
    Add-Line "open both files side by side and copy over only the new rules. See 'EXTRA NOTES' below if present."
}
Add-Line ''
Add-Line 'STEP 3 - CONFIG LINES (by hand, live server only)'
Add-Line '-------------------------------------------------'
Add-Line "Open config/app.local.php ON THE LIVE SERVER (never overwrite it with a file from this package) and check:"
if ($assetChanged -or ($send -contains 'config/app.php')) {
    Add-Line "   - 'version' => '<a value you have not used before>',   (the exact value for this update is in the EXTRA NOTES, if given)"
    Add-Line "     This makes every phone and tablet drop its old saved files."
}
if ($swChanged) { Add-Line "   - 'enable_service_worker' => true," }
if (-not ($assetChanged -or ($send -contains 'config/app.php') -or $swChanged)) { Add-Line "   - No config change is needed for the files in this update." }
if ($notes) {
    Add-Line ''
    Add-Line 'EXTRA NOTES FOR THIS UPDATE'
    Add-Line '---------------------------'
    foreach ($ln in ($notes -split "`r?`n")) { Add-Line $ln }
}
if ($swChanged -or $loginChanged) {
    Add-Line ''
    Add-Line 'STEP 4 - TABLET: CLEAR CACHE (once per tablet, only if a tablet still misbehaves)'
    Add-Line '------------------------------------------------------------------------------'
    Add-Line "Normally the new files replace the old ones by themselves a few seconds after the login page is opened."
    Add-Line "If a tablet still goes back to the login page or shows old screens, do this once on that tablet:"
    Add-Line "  Android (Chrome):     three dots > Settings > Privacy and security > Delete browsing data > tick 'Cookies and site"
    Add-Line "                        data' and 'Cached images and files' > Delete. Open the site and sign in again."
    Add-Line "  Android (Samsung):    Menu > Settings > Personal browsing data > Delete browsing data > Cookies and site data +"
    Add-Line "                        Cached images and files > Delete."
    Add-Line "  iPad (Safari):        Settings > Safari > Advanced > Website Data > search for the site name > swipe left > Delete."
    Add-Line "  Home-screen app:      remove the icon, clear the data as above, open the site in the browser, sign in, add it to"
    Add-Line "                        the home screen again."
}
Add-Line ''
Add-Line 'TEST CHECKLIST (about 10 minutes)'
Add-Line '---------------------------------'
$tests = New-Object Collections.Generic.List[string]
$tests.Add('Sign in on a PC and on a phone. You land on the Dashboard and stay signed in when you open POS and Inventory.')
if ($loginChanged) { $tests.Add('Sign in on a real tablet (portrait and landscape). It must stay signed in. A wrong password shows a red message.') }
$tests.Add('POS: add an item, Checkout, type the prices, Confirm Sale. The sale appears in Sales Records with the same total.')
$tests.Add('Receive Stock: receive one item. Stock Monitoring shows it.')
if ($newMigs.Count) { $tests.Add('Open the owner Needs Action page and Sales Records. They must open with no error.') }
if ($swChanged) { $tests.Add('Phone, airplane mode ON: a sale is kept on the device and sent when the internet is back.') }
$tests.Add('Sign out and sign in again. It works.')
for ($i = 0; $i -lt $tests.Count; $i++) { Add-Line ("{0}. {1}" -f ($i + 1), $tests[$i]) }
Add-Line ''
Add-Line 'ROLLBACK'
Add-Line '--------'
Add-Line "- Put the old files back from your 'old-files-$stamp' folder and raise 'version' in config/app.local.php again."
if ($newMigs.Count) {
    Add-Line "- The database changes only ADD things (columns, tables, triggers). The old code ignores them, so you normally do not need to"
    Add-Line "  undo them. If you must, import the database backup you exported before you started."
} else {
    Add-Line '- There was no database change, nothing to restore there.'
}
if ($swChanged) {
    Add-Line "- Emergency switch without touching files: config/app.local.php  'enable_service_worker' => false  and raise 'version'."
    Add-Line "  Every device removes its saved files on the next page load."
}
Add-Line ''
Add-Line "FILES IN THIS UPDATE ($($send.Count))"
Add-Line '-----------------------'
foreach ($f in $send) { Add-Line ("  {0,-8} {1}" -f $(if ($new.Contains($f)) { 'NEW' } else { 'CHANGED' }), $f) }
if ($gone.Count) {
    Add-Line ''
    Add-Line 'NO LONGER IN THE PROJECT (nothing is deleted for you; leave them on the website unless your developer says so):'
    foreach ($g in $gone) { Add-Line "  $g" }
}
[IO.File]::WriteAllText((Join-Path $folder 'deploy\README.txt'), (($r -join "`r`n") + "`r`n"), (New-Object Text.UTF8Encoding($false)))

# ---- 6. real .zip (forward-slash entry names) ----
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$fs = [IO.File]::Open($zip, [IO.FileMode]::CreateNew)
$za = New-Object IO.Compression.ZipArchive($fs, [IO.Compression.ZipArchiveMode]::Create)
Get-ChildItem -LiteralPath $folder -Recurse -File -Force | ForEach-Object {
    $entry = $_.FullName.Substring($folder.Length + 1).Replace('\', '/')
    [void][IO.Compression.ZipFileExtensions]::CreateEntryFromFile($za, $_.FullName, $entry, [IO.Compression.CompressionLevel]::Optimal)
}
$za.Dispose(); $fs.Dispose()

# ---- 7. remember exactly what was delivered (baseline moves only on confirm-deployed.ps1) ----
Write-Json ([ordered]@{
    built_at         = (Get-Date).ToString('s')
    folder           = $folder
    zip              = $zip
    baseline_version = $baseline.version
    files            = $hashes
    migrations       = @($newMigs | ForEach-Object Name)
}) $script:LastPkgFile

Write-Host ''
Write-Host "Folder : $folder"
Write-Host "Zip    : $zip"
Write-Host "Files  : $($send.Count) ($($new.Count) new, $($changed.Count) changed) + deploy/migrations.sql + deploy/README.txt"
foreach ($f in $send) { Write-Host ("  {0,-8} {1}" -f $(if ($new.Contains($f)) { 'NEW' } else { 'CHANGED' }), $f) }
Write-Host "Baseline used: $($baseline.version). Reply 'deployed' after uploading so the baseline can be updated."
