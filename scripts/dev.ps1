$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
$phpExecutable = Join-Path (Get-Location) '.tools/php/php.exe'
if (-not (Test-Path -LiteralPath $phpExecutable)) { $phpExecutable = 'php' }
& $phpExecutable -S 127.0.0.1:8080 -t public public/router.php
