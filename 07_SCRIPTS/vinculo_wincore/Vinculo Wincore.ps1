# 07_SCRIPTS/vinculo_wincore/Vinculo Wincore.ps1 - Abre el programa "Vínculo Wincore" (2026-11-19). Lo llama "Vinculo Wincore.cmd".
# La primera vez pide la dirección del SGS y la clave del programa (Consultar puertos → Vínculo con Wincore → Generar clave) y las
# guarda en %APPDATA%\SGS\vinculo_wincore.json (solo para este usuario de Windows). Cada vez pide usuario y contraseña de Wincore
# SIN mostrarlos, arranca el programa EN SEGUNDO PLANO (sin ventana) y le pasa los datos por la entrada estándar: la contraseña no
# se guarda ni aparece en la lista de procesos. Espera a que diga "Vinculado" (o el error) y esta ventana se cierra sola.
# Lo que hace el programa queda en %LOCALAPPDATA%\SGS\vinculo_wincore.log. Para detenerlo: "Detener Vinculo Wincore.cmd".
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [Text.UTF8Encoding]::new($false)
$Host.UI.RawUI.WindowTitle = 'Vínculo Wincore · SGS'
# PHP del paquete (carpeta php, en la laptop sin XAMPP) o, en una PC con XAMPP, el suyo.
$php = Join-Path $PSScriptRoot 'php\php.exe'
$propio = Test-Path $php
if (-not $propio) { $php = 'C:\xampp\php\php.exe' }
$programa = Join-Path $PSScriptRoot 'vinculo_wincore.php'
$config = Join-Path $env:APPDATA 'SGS\vinculo_wincore.json'
$local = Join-Path $env:LOCALAPPDATA 'SGS'
$registro = Join-Path $local 'vinculo_wincore.log'
$pidArchivo = Join-Path $local 'vinculo_wincore.pid'
New-Item -ItemType Directory -Force -Path (Split-Path $config), $local | Out-Null
function Salir($codigo) { Write-Host ''; Read-Host '  Pulsa Enter para cerrar'; exit $codigo }

Write-Host ''
Write-Host '  VÍNCULO WINCORE · SGS' -ForegroundColor DarkYellow
Write-Host '  Atiende las consultas de puertos de los supervisores en segundo plano, con la VPN conectada.'
Write-Host ''
if (-not (Test-Path $php)) { Write-Host "  No se encontró PHP en $php." -ForegroundColor Red; Salir 1 }

# ¿Ya está funcionando?
if (Test-Path $pidArchivo) {
    $anterior = Get-Process -Id ([int](Get-Content $pidArchivo -Raw)) -ErrorAction SilentlyContinue
    if ($anterior -and $anterior.ProcessName -eq 'php') {
        Write-Host '  El Vínculo Wincore ya está funcionando en segundo plano.' -ForegroundColor Green
        Write-Host '  Para detenerlo usa el acceso directo «Detener Vínculo Wincore» o Desactivar en el SGS.'
        Start-Sleep -Seconds 4; exit 0
    }
}

$datos = $null
if (Test-Path $config) { try { $datos = Get-Content $config -Raw -Encoding UTF8 | ConvertFrom-Json } catch { $datos = $null } }
if (-not $datos -or -not $datos.sgs -or -not $datos.clave) {
    Write-Host '  Primera vez: configura el programa.' -ForegroundColor Cyan
    $sgs = (Read-Host '  Dirección del SGS (por ejemplo https://tudominio.com/ProviWIN)').Trim().TrimEnd('/')
    $clave = (Read-Host '  Clave del programa (Consultar puertos → Vínculo con Wincore → Generar clave)').Trim()
    if (-not $sgs -or -not $clave) { Write-Host '  Faltan datos.' -ForegroundColor Red; Salir 1 }
    [IO.File]::WriteAllText($config, (@{ sgs = $sgs; clave = $clave } | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
    Write-Host '  Configuración guardada.'
    Write-Host ''
}

$usuario = (Read-Host '  Usuario de Wincore').Trim()
$segura = Read-Host '  Contraseña de Wincore' -AsSecureString
Set-Content -Path $registro -Value '' -Encoding UTF8

# Certificado de Wincore (2026-11-19): se conecta con Windows, que valida el certificado igual que Chrome (con las entidades de
# la laptop y las intermedias que descarga), y guarda esa cadena para el programa. Solo si Windows la aceptó: nada se salta.
$wincoreUrl = if ($datos -and $datos.wincore) { "$($datos.wincore)" } else { 'https://wincore.win.pe' }
$cadenaPem = Join-Path $local 'wincore_ca.pem'
try {
    $servidor = ([Uri]$wincoreUrl).Host
    $tcp = New-Object Net.Sockets.TcpClient
    if ($tcp.ConnectAsync($servidor, 443).Wait(8000)) {
        $resultado = @{ pem = $null }
        $validar = [Net.Security.RemoteCertificateValidationCallback]{
            param($remitente, $certificado, $cadena, $errores)
            if ($errores -eq [Net.Security.SslPolicyErrors]::None) {
                $resultado.pem = ($cadena.ChainElements | ForEach-Object {
                    "-----BEGIN CERTIFICATE-----`n" + [Convert]::ToBase64String($_.Certificate.RawData, 'InsertLineBreaks') + "`n-----END CERTIFICATE-----"
                }) -join "`n"
            }
            return ($errores -eq [Net.Security.SslPolicyErrors]::None)
        }
        $ssl = New-Object Net.Security.SslStream($tcp.GetStream(), $false, $validar)
        try { $ssl.AuthenticateAsClient($servidor) } catch { }
        if ($resultado.pem) { [IO.File]::WriteAllText($cadenaPem, $resultado.pem + "`n", [Text.Encoding]::ASCII) }
        $ssl.Dispose()
    }
    $tcp.Dispose()
} catch { }

# Programa en segundo plano: sin ventana y sin depender de esta (sigue aunque se cierre).
$psi = New-Object Diagnostics.ProcessStartInfo $php
$extensiones = if ($propio) { "-n -d extension_dir=`"$(Join-Path $PSScriptRoot 'php\ext')`" -d extension=curl -d extension=openssl -d extension=mbstring " } else { '' }
$psi.Arguments = "$extensiones`"$programa`" `"$config`" `"$registro`""
$psi.WorkingDirectory = $PSScriptRoot
$psi.UseShellExecute = $false
$psi.CreateNoWindow = $true
$psi.RedirectStandardInput = $true
$proceso = [Diagnostics.Process]::Start($psi)
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($segura)
try {
    $bytes = [Text.UTF8Encoding]::new($false).GetBytes($usuario + "`n" + [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) + "`n")
    $proceso.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length)
    $proceso.StandardInput.Close()
    [Array]::Clear($bytes, 0, $bytes.Length)
} finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
Set-Content -Path $pidArchivo -Value $proceso.Id

Write-Host ''
Write-Host '  Iniciando sesión en Wincore…'
$fin = (Get-Date).AddSeconds(60)
while ((Get-Date) -lt $fin) {
    Start-Sleep -Milliseconds 500
    $texto = Get-Content $registro -Raw -Encoding UTF8 -ErrorAction SilentlyContinue
    if ($texto -match 'Vinculado con el SGS hasta las ([0-9:]+)') {
        Write-Host ''
        Write-Host "  Listo: Vínculo Wincore activo hasta las $($Matches[1])." -ForegroundColor Green
        Write-Host '  Sigue funcionando en segundo plano. Esta ventana se cierra sola.'
        Start-Sleep -Seconds 4; exit 0
    }
    if ($proceso.HasExited) {
        Remove-Item $pidArchivo -ErrorAction SilentlyContinue
        Write-Host ''
        Write-Host '  No se pudo activar:' -ForegroundColor Red
        (Get-Content $registro -Encoding UTF8 | Where-Object { $_ } | Select-Object -Last 2) | ForEach-Object { Write-Host "  $_" }
        if ($texto -match 'clave del programa') { Write-Host "  Si generaste otra clave, borra $config y vuelve a abrir el programa." -ForegroundColor DarkGray }
        Salir 1
    }
}
Write-Host '  Wincore está tardando. Revisa la VPN; el detalle queda en' $registro -ForegroundColor Yellow
Salir 1
