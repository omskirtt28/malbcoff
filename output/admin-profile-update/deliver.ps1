$ErrorActionPreference = 'Stop'
$taskRoot = 'C:\xampp\htdocs\malbcoff\output\admin-profile-update'
$record = Get-Content -LiteralPath (Join-Path $taskRoot 'package-manifest.json') -Raw | ConvertFrom-Json
$desktop = [IO.Path]::GetFullPath((Join-Path $env:USERPROFILE 'Desktop'))
foreach ($pair in @(@($record.staged_zip,$record.zip,$record.zip_sha256),@($record.staged_guide,$record.guide,$record.guide_sha256))) {
    $source = [IO.Path]::GetFullPath($pair[0])
    $destination = [IO.Path]::GetFullPath($pair[1])
    if ((Split-Path $destination) -ne $desktop -or !(Split-Path $source).StartsWith($taskRoot+'\delivery')) { throw 'Unexpected delivery path.' }
    if (Test-Path -LiteralPath $destination) {
        if ((Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash.ToLower() -ne $pair[2]) { throw 'Refusing to overwrite a different Desktop artifact.' }
    } else {
        Copy-Item -LiteralPath $source -Destination $destination
    }
    if ((Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash.ToLower() -ne $pair[2]) { throw 'Desktop copy hash mismatch.' }
    Get-Item -LiteralPath $destination | Select-Object FullName,Length
}
