# 07_SCRIPTS/vinculo_wincore/Detener Vinculo Wincore.ps1 - Detiene el programa "Vínculo Wincore" que corre en segundo plano (2026-11-19).
# Le pide que termine (archivo .detener): así desactiva el vínculo en el SGS antes de cerrarse. Si no responde en 20 s, lo cierra.
[Console]::OutputEncoding = [Text.UTF8Encoding]::new($false)
$Host.UI.RawUI.WindowTitle = 'Detener Vínculo Wincore · SGS'
$local = Join-Path $env:LOCALAPPDATA 'SGS'
$registro = Join-Path $local 'vinculo_wincore.log'
$pidArchivo = Join-Path $local 'vinculo_wincore.pid'
$proceso = $null
if (Test-Path $pidArchivo) { $proceso = Get-Process -Id ([int](Get-Content $pidArchivo -Raw)) -ErrorAction SilentlyContinue }
Write-Host ''
if (-not $proceso -or $proceso.ProcessName -ne 'php') {
    Remove-Item $pidArchivo -ErrorAction SilentlyContinue
    Write-Host '  El Vínculo Wincore no está funcionando.' -ForegroundColor Yellow
    Start-Sleep -Seconds 3; exit 0
}
Write-Host '  Deteniendo el Vínculo Wincore…'
New-Item -ItemType File -Force -Path ($registro + '.detener') | Out-Null
if (-not $proceso.WaitForExit(20000)) { Stop-Process -Id $proceso.Id -Force; Write-Host '  No respondió: se cerró a la fuerza (el SGS lo da por apagado en 20 s).' }
Remove-Item $pidArchivo, ($registro + '.detener') -ErrorAction SilentlyContinue
Write-Host '  Vínculo Wincore detenido. Los supervisores ya no pueden consultar puertos hasta que lo vuelvas a abrir.' -ForegroundColor Green
Start-Sleep -Seconds 4
