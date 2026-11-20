# 07_SCRIPTS/vinculo_wincore/Detener Vinculo Wincore.ps1 - Detiene el programa "Vínculo Wincore" (2026-11-19; 2026-11-20 vigilante).
# Le pide que termine (archivo .detener): el programa desactiva el vínculo en el SGS como apagado a propósito y el vigilante ya no
# vuelve a arrancarlo. Si no responde en 25 s, se cierra a la fuerza. Al encender la laptop vuelve a arrancar solo (si está en el
# inicio de Windows); para que no, borra «Vínculo Wincore (al encender)» de la carpeta de inicio (Windows + R → shell:startup).
[Console]::OutputEncoding = [Text.UTF8Encoding]::new($false)
$Host.UI.RawUI.WindowTitle = 'Detener Vínculo Wincore · SGS'
$local = Join-Path $env:LOCALAPPDATA 'SGS'
$registro = Join-Path $local 'vinculo_wincore.log'
$pidArchivo = Join-Path $local 'vinculo_wincore.pid'
$vigilante = $null
if (Test-Path $pidArchivo) { $vigilante = Get-Process -Id ([int](Get-Content $pidArchivo -Raw)) -ErrorAction SilentlyContinue }
$programas = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -like '*vinculo_wincore.php*' })
Write-Host ''
if (-not $vigilante -and -not $programas) {
    Remove-Item $pidArchivo -ErrorAction SilentlyContinue
    Write-Host '  El Vínculo Wincore no está funcionando.' -ForegroundColor Yellow
    Start-Sleep -Seconds 3; exit 0
}
Write-Host '  Deteniendo el Vínculo Wincore…'
New-Item -ItemType File -Force -Path ($registro + '.detener') | Out-Null
$fin = (Get-Date).AddSeconds(25)
while ((Get-Date) -lt $fin) {
    $siguen = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -like '*vinculo_wincore.php*' })
    $vivo = $vigilante -and (Get-Process -Id $vigilante.Id -ErrorAction SilentlyContinue)
    if (-not $siguen -and -not $vivo) { break }
    Start-Sleep -Milliseconds 500
}
Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -like '*vinculo_wincore.php*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
if ($vigilante) { Stop-Process -Id $vigilante.Id -Force -ErrorAction SilentlyContinue }
Remove-Item $pidArchivo, ($registro + '.detener') -ErrorAction SilentlyContinue
Write-Host '  Vínculo Wincore detenido. Los supervisores ya no pueden consultar puertos hasta que lo vuelvas a abrir.' -ForegroundColor Green
Start-Sleep -Seconds 4
