# 07_SCRIPTS/vinculo_wincore/Instalar.ps1 - Instala "Vínculo Wincore" en la laptop (2026-11-19). Lo llama "Instalar.cmd" del paquete.
# Copia el programa (con su propio PHP) a %LOCALAPPDATA%\VinculoWincore y crea en el escritorio los accesos directos
# "Vínculo Wincore" y "Detener Vínculo Wincore". No necesita XAMPP ni permisos de administrador. Se puede repetir para actualizar.
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [Text.UTF8Encoding]::new($false)
$Host.UI.RawUI.WindowTitle = 'Instalar Vínculo Wincore · SGS'
$destino = Join-Path $env:LOCALAPPDATA 'VinculoWincore'
Write-Host ''
Write-Host '  INSTALAR VÍNCULO WINCORE · SGS' -ForegroundColor DarkYellow
Write-Host ''
try {
    # Si está funcionando, se detiene antes de reemplazar sus archivos
    $pidArchivo = Join-Path $env:LOCALAPPDATA 'SGS\vinculo_wincore.pid'
    if (Test-Path $pidArchivo) { Stop-Process -Id ([int](Get-Content $pidArchivo -Raw)) -Force -ErrorAction SilentlyContinue; Remove-Item $pidArchivo -ErrorAction SilentlyContinue }
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -like '*vinculo_wincore.php*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    New-Item -ItemType Directory -Force -Path $destino | Out-Null
    Get-ChildItem -Path $PSScriptRoot -Force | Where-Object { $_.Name -notin 'Instalar.cmd', 'Instalar.ps1' } | Copy-Item -Destination $destino -Recurse -Force
    Write-Host "  Programa copiado en $destino"

    # Prueba: el PHP del paquete arranca y tiene lo necesario para conectarse por https
    $php = Join-Path $destino 'php\php.exe'
    # Sin código entre comillas: PowerShell 5 se las quita al pasárselo a PHP. Basta con la lista de módulos (-m).
    $prueba = & $php -n -d "extension_dir=$(Join-Path $destino 'php\ext')" -d extension=curl -d extension=openssl -d extension=mbstring -m 2>&1
    $modulos = @($prueba | ForEach-Object { "$_".Trim().ToLower() })
    if (-not (($modulos -contains 'curl') -and ($modulos -contains 'openssl') -and ($modulos -contains 'mbstring'))) {
        Write-Host '  El PHP del paquete no arrancó en esta laptop:' -ForegroundColor Red
        Write-Host "  $(($prueba | Select-Object -Last 3) -join ' ')"
        Write-Host '  Instala "Microsoft Visual C++ Redistributable 2015-2022 (x64)" desde la página de Microsoft y vuelve a instalar.' -ForegroundColor Yellow
        Read-Host '  Pulsa Enter para cerrar'; exit 1
    }
    Write-Host '  Comprobado: el programa puede conectarse por https.'

    $escritorio = [Environment]::GetFolderPath('Desktop')
    $ws = New-Object -ComObject WScript.Shell
    foreach ($a in @(@('Vínculo Wincore', 'Vinculo Wincore.cmd', 13, 'Atiende las consultas de puertos del SGS con la VPN'),
                     @('Detener Vínculo Wincore', 'Detener Vinculo Wincore.cmd', 27, 'Detiene el Vínculo Wincore'))) {
        $acceso = $ws.CreateShortcut((Join-Path $escritorio ($a[0] + '.lnk')))
        $acceso.TargetPath = Join-Path $destino $a[1]
        $acceso.WorkingDirectory = $destino
        $acceso.IconLocation = "C:\Windows\System32\shell32.dll,$($a[2])"
        $acceso.Description = $a[3]
        $acceso.Save()
    }
    Write-Host '  Accesos directos creados en el escritorio: «Vínculo Wincore» y «Detener Vínculo Wincore».'
    # Al encender la laptop (2026-11-20): arranca solo, oculto y sin preguntar (si ya se configuró una vez).
    $inicio = [Environment]::GetFolderPath('Startup')
    $acceso = $ws.CreateShortcut((Join-Path $inicio 'Vínculo Wincore (al encender).lnk'))
    $acceso.TargetPath = Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe'
    $acceso.Arguments = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$(Join-Path $destino 'Vinculo Wincore.ps1')`" auto"
    $acceso.WorkingDirectory = $destino
    $acceso.WindowStyle = 7
    $acceso.Description = 'Arranca el Vínculo Wincore al encender la laptop'
    $acceso.Save()
    Write-Host '  Arranca solo al encender la laptop (para quitarlo: Windows + R → shell:startup y borra «Vínculo Wincore (al encender)»).'
    Write-Host ''
    Write-Host '  Listo. Ya puedes borrar la carpeta del paquete que copiaste.' -ForegroundColor Green
    Write-Host '  Para usarlo: conecta la VPN y abre «Vínculo Wincore» en el escritorio.'
} catch {
    Write-Host "  No se pudo instalar: $($_.Exception.Message)" -ForegroundColor Red
}
Write-Host ''
Read-Host '  Pulsa Enter para cerrar'
