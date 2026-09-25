$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
$phpExecutable = Join-Path (Get-Location) '.tools/php/php.exe'
$pythonExecutable = Join-Path (Get-Location) '.tools/python/python.exe'
$nodeExecutable = Join-Path (Get-Location) '.tools/node-v22.22.0-win-x64/node.exe'
Get-ChildItem app,database,scripts,public -Recurse -Filter *.php | ForEach-Object {
    & $phpExecutable -l $_.FullName
    if ($LASTEXITCODE -ne 0) { throw "Falha de sintaxe PHP: $($_.FullName)" }
}
& $phpExecutable tests/run.php
if ($LASTEXITCODE -ne 0) { throw 'Testes PHP falharam.' }
& $pythonExecutable tests/test_agent.py
if ($LASTEXITCODE -ne 0) { throw 'Testes do agente falharam.' }
& $nodeExecutable tests/browser.mjs
if ($LASTEXITCODE -ne 0) { throw 'Testes do navegador falharam.' }
