# 07_SCRIPTS/vinculo_wincore/Vinculo Wincore.ps1 - Abre el programa "Vínculo Wincore" (2026-11-19; 2026-11-20 siempre conectado).
# Lo llama "Vinculo Wincore.cmd" (con "auto" desde el inicio de Windows: sin preguntas).
# La primera vez pide la dirección del SGS, la clave del programa (Consultar puertos → Vínculo con Wincore → Crear favorito) y el
# usuario y la contraseña de Wincore. La contraseña se guarda CIFRADA solo en esta laptop (cifrado de Windows: solo este usuario
# de Windows puede leerla) para que el programa vuelva a entrar solo si Wincore cierra la sesión. Nunca sale de la laptop.
# Arranca el vigilante (vigilante.ps1) oculto, espera a que diga "Vinculado" (o el motivo si no pudo) y se cierra sola.
# Lo que pasa queda en %LOCALAPPDATA%\SGS\vinculo_wincore.log. Para detenerlo: "Detener Vinculo Wincore.cmd".
param([string]$modo = '')
$auto = $modo -eq 'auto'
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [Text.UTF8Encoding]::new($false)
$Host.UI.RawUI.WindowTitle = 'Vínculo Wincore · SGS'
$config = Join-Path $env:APPDATA 'SGS\vinculo_wincore.json'
$acceso = Join-Path $env:APPDATA 'SGS\vinculo_wincore_acceso.json'
$local = Join-Path $env:LOCALAPPDATA 'SGS'
$registro = Join-Path $local 'vinculo_wincore.log'
$pidArchivo = Join-Path $local 'vinculo_wincore.pid'
$estadoArchivo = Join-Path $local 'vinculo_wincore.estado'
$vigilante = Join-Path $PSScriptRoot 'vigilante.ps1'
New-Item -ItemType Directory -Force -Path (Split-Path $config), $local | Out-Null
function Salir($codigo) { if (-not $auto) { Write-Host ''; Read-Host '  Pulsa Enter para cerrar' }; exit $codigo }

# ¿Ya está funcionando? (el vigilante deja su número de proceso)
if (Test-Path $pidArchivo) {
    $anterior = Get-Process -Id ([int](Get-Content $pidArchivo -Raw)) -ErrorAction SilentlyContinue
    if ($anterior -and $anterior.ProcessName -like 'powershell*') {
        if (-not $auto) {
            Write-Host ''
            Write-Host '  El Vínculo Wincore ya está funcionando en segundo plano.' -ForegroundColor Green
            Write-Host '  Para detenerlo usa el acceso directo «Detener Vínculo Wincore» o Desactivar en el SGS.'
            Start-Sleep -Seconds 4
        }
        exit 0
    }
}

$estado = if (Test-Path $estadoArchivo) { (Get-Content $estadoArchivo -Raw -Encoding UTF8).Trim() } else { '' }
$datos = $null
if (Test-Path $config) { try { $datos = Get-Content $config -Raw -Encoding UTF8 | ConvertFrom-Json } catch { $datos = $null } }
$faltaConfig = -not $datos -or -not $datos.sgs -or -not $datos.clave -or $estado -eq 'clave'
$faltaAcceso = -not (Test-Path $acceso) -or $estado -eq 'contrasena'
if ($auto -and ($faltaConfig -or $faltaAcceso)) { exit 0 }      # al encender, sin datos completos no se pregunta nada

if (-not $auto) {
    Write-Host ''
    Write-Host '  VÍNCULO WINCORE · SGS' -ForegroundColor DarkYellow
    Write-Host '  Atiende las consultas de puertos de los supervisores en segundo plano, con la VPN conectada.'
    Write-Host ''
}
if ($faltaConfig) {
    if ($estado -eq 'clave') { Write-Host '  La clave del programa fue anulada o cambió: pega la nueva.' -ForegroundColor Yellow }
    else { Write-Host '  Primera vez: configura el programa.' -ForegroundColor Cyan }
    $sgs = if ($datos -and $datos.sgs -and $estado -eq 'clave') { "$($datos.sgs)" } else { ([string](Read-Host '  Dirección del SGS (la del navegador, sin index.php)')).Trim().TrimEnd('/') }
    $clave = ([string](Read-Host '  Clave del programa (Consultar puertos → Vínculo con Wincore → Crear mi favorito → la clave)')).Trim()
    if (-not $sgs -or -not $clave) { Write-Host '  Faltan datos.' -ForegroundColor Red; Salir 1 }
    [IO.File]::WriteAllText($config, (@{ sgs = $sgs; clave = $clave } | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
    Write-Host '  Configuración guardada.'
    Write-Host ''
}
if ($faltaAcceso) {
    if ($estado -eq 'contrasena') { Write-Host '  Wincore no aceptó la contraseña guardada: escríbela de nuevo.' -ForegroundColor Yellow }
    $usuario = ([string](Read-Host '  Usuario de Wincore')).Trim()
    $segura = Read-Host '  Contraseña de Wincore' -AsSecureString
    if (-not $usuario -or -not $segura -or $segura.Length -eq 0) { Write-Host '  Faltan el usuario o la contraseña.' -ForegroundColor Red; Salir 1 }
    # Cifrado de Windows (DPAPI) del usuario actual: el texto guardado no sirve en otra laptop ni con otro usuario de Windows.
    [IO.File]::WriteAllText($acceso, (@{ usuario = $usuario; contrasena = (ConvertFrom-SecureString $segura) } | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
    $segura.Dispose()
    Write-Host '  Contraseña guardada cifrada en esta laptop: el programa volverá a entrar solo si Wincore cierra la sesión.' -ForegroundColor DarkGray
}

# Phoenix en el mismo programa (2026-11-20): se pregunta una vez si también se conecta Phoenix solo (de 05:55 a 21:00). Su usuario
# y contraseña se guardan igual, cifrados solo en esta laptop. Si Phoenix los rechazó, se vuelven a pedir.
$estadoPhoenix = Join-Path $local 'vinculo_phoenix.estado'
if (-not $auto -and (Test-Path $acceso)) {
    $acc = Get-Content $acceso -Raw -Encoding UTF8 | ConvertFrom-Json
    $rechazada = (Test-Path $estadoPhoenix) -and ((Get-Content $estadoPhoenix -Raw).Trim() -eq 'rechazada')
    if ($rechazada -or (-not $acc.phoenix_usuario -and -not $acc.phoenix_no)) {
        Write-Host ''
        if ($rechazada) { Write-Host '  Phoenix no aceptó el usuario o la contraseña guardados: escríbelos de nuevo.' -ForegroundColor Yellow; $quiere = 'S' }
        else { $quiere = ([string](Read-Host '  ¿Conectar también Phoenix automáticamente cada día? (S/N)')).Trim().ToUpper() }
        $nuevo = @{}
        $acc.PSObject.Properties | ForEach-Object { $nuevo[$_.Name] = $_.Value }
        if ($quiere -eq 'S') {
            $pu = ([string](Read-Host '  Usuario de Phoenix')).Trim()
            $ps = Read-Host '  Contraseña de Phoenix' -AsSecureString
            if ($pu -and $ps -and $ps.Length -gt 0) {
                $nuevo['phoenix_usuario'] = $pu
                $nuevo['phoenix_contrasena'] = ConvertFrom-SecureString $ps
                $nuevo.Remove('phoenix_no')
                $ps.Dispose()
                Remove-Item $estadoPhoenix -ErrorAction SilentlyContinue
                Write-Host '  Phoenix guardado cifrado en esta laptop: se conectará solo cada día desde las 05:55.' -ForegroundColor DarkGray
            }
        } else { $nuevo['phoenix_no'] = $true }
        [IO.File]::WriteAllText($acceso, ($nuevo | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
    }
}

Set-Content -Path $estadoArchivo -Value '' -Encoding UTF8
$desde = if (Test-Path $registro) { (Get-Item $registro).Length } else { 0 }
Start-Process -FilePath (Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe') -WindowStyle Hidden `
    -ArgumentList '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$vigilante`""
if ($auto) { exit 0 }

Write-Host ''
Write-Host '  Iniciando sesión en Wincore…'
$fin = (Get-Date).AddSeconds(75)
while ((Get-Date) -lt $fin) {
    Start-Sleep -Milliseconds 700
    $nuevo = ''
    if (Test-Path $registro) {
        $fs = [IO.File]::Open($registro, 'Open', 'Read', 'ReadWrite')
        try { if ($fs.Length -gt $desde) { $fs.Seek($desde, 'Begin') | Out-Null; $nuevo = (New-Object IO.StreamReader($fs, [Text.Encoding]::UTF8)).ReadToEnd() } } finally { $fs.Dispose() }
    }
    $estado = if (Test-Path $estadoArchivo) { (Get-Content $estadoArchivo -Raw -Encoding UTF8).Trim() } else { '' }
    if ($nuevo -match 'Vinculado con el SGS hasta las ([0-9:]+)') {
        Write-Host ''
        Write-Host "  Listo: Vínculo Wincore activo hasta las $($Matches[1])." -ForegroundColor Green
        Write-Host '  Funciona en segundo plano y vuelve a entrar solo si Wincore cierra la sesión. Esta ventana se cierra sola.'
        Start-Sleep -Seconds 5; exit 0
    }
    if ($estado -in 'contrasena', 'clave', 'sin_configurar') {
        Write-Host ''
        Write-Host '  No se pudo activar:' -ForegroundColor Red
        ($nuevo -split "`r?`n" | Where-Object { $_ } | Select-Object -Last 2) | ForEach-Object { Write-Host "  $_" }
        Write-Host '  Vuelve a abrir «Vínculo Wincore»: te pedirá de nuevo lo que falta.' -ForegroundColor DarkGray
        Salir 1
    }
    if ($nuevo -match 'Se vuelve a conectar solo en 60') {
        Write-Host ''
        Write-Host '  Todavía no llega a Wincore o al SGS (¿VPN conectada?). Seguirá intentando solo cada minuto, en segundo plano.' -ForegroundColor Yellow
        Start-Sleep -Seconds 6; exit 0
    }
}
Write-Host '  Wincore está tardando. Seguirá intentando solo en segundo plano; el detalle queda en' $registro -ForegroundColor Yellow
Salir 0
