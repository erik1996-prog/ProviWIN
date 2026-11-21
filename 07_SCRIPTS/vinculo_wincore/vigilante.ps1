# 07_SCRIPTS/vinculo_wincore/vigilante.ps1 - Vigilante del programa "Vínculo Wincore" (2026-11-20, pedido del usuario: que no se corte).
# Corre oculto. Arranca vinculo_wincore.php con el usuario y la contraseña de Wincore guardados CIFRADOS en esta laptop
# (%APPDATA%\SGS\vinculo_wincore_acceso.json, cifrado de Windows: solo este usuario de Windows, en esta laptop, puede leerlos) y, si
# el programa se cae, lo vuelve a arrancar solo según su código de salida:
#   0 = lo detuvo el usuario (Detener, o Desactivar en el SGS)  → termina
#   2 = la clave del programa fue anulada o no tiene permiso      → termina
#   3 = Wincore no aceptó el usuario o la contraseña               → termina (hay que abrir «Vínculo Wincore» y escribirla de nuevo)
#   4 = se cerró la sesión de Wincore o venció el vínculo         → vuelve a entrar en 15 s
#   5 = sin VPN, sin internet o el SGS no responde                 → reintenta cada minuto, sin límite
# Además (2026-11-20) mantiene conectada la sesión de Phoenix del SGS de 05:55 a 21:00 (RevisarPhoenix), si se guardó su usuario,
# y (2026-11-21) entrega cada día al SGS el token del bot de Telegram (RevisarTelegram), si se guardó.
# Para detenerlo: «Detener Vínculo Wincore» (crea el archivo .detener). La contraseña solo se descifra en memoria para pasarla al
# programa por la entrada estándar: nunca queda en un archivo, en la lista de procesos ni en el registro.
$ErrorActionPreference = 'Stop'
$local = Join-Path $env:LOCALAPPDATA 'SGS'
$registro = Join-Path $local 'vinculo_wincore.log'
$detener = $registro + '.detener'
$pidArchivo = Join-Path $local 'vinculo_wincore.pid'
$estadoArchivo = Join-Path $local 'vinculo_wincore.estado'
$config = Join-Path $env:APPDATA 'SGS\vinculo_wincore.json'
$acceso = Join-Path $env:APPDATA 'SGS\vinculo_wincore_acceso.json'
$programa = Join-Path $PSScriptRoot 'vinculo_wincore.php'
$php = Join-Path $PSScriptRoot 'php\php.exe'
$propio = Test-Path $php
if (-not $propio) { $php = 'C:\xampp\php\php.exe' }
$extensiones = if ($propio) { "-n -d extension_dir=`"$(Join-Path $PSScriptRoot 'php\ext')`" -d extension=curl -d extension=openssl -d extension=mbstring " } else { '' }

function Anotar($texto) {
    $linea = "[$(Get-Date -Format 'dd/MM HH:mm:ss')] $texto"
    # El registro no crece sin fin: pasado 1 MB se empieza de nuevo
    if ((Test-Path $registro) -and (Get-Item $registro).Length -gt 1MB) { Set-Content -Path $registro -Value '' -Encoding UTF8 }
    Add-Content -Path $registro -Value $linea -Encoding UTF8
}
function Estado($texto) { Set-Content -Path $estadoArchivo -Value $texto -Encoding UTF8 }

# Phoenix (2026-11-20, pedido del usuario: "que se conecte solo, como Wincore"). Cada 2 min, de 05:55 a 21:00, pregunta al SGS si
# la sesión de Phoenix iniciada allí está activa; si no (pasó la medianoche o Phoenix la cerró), la inicia con el usuario y la
# contraseña de Phoenix guardados cifrados en esta laptop. La contraseña solo viaja (cifrada por https) para entrar: el SGS
# guarda solo la sesión. Phoenix está en internet: no necesita la VPN. Si Phoenix rechaza la contraseña, deja de intentar.
$estadoPhoenix = Join-Path $local 'vinculo_phoenix.estado'
$script:ultimaPhoenix = [datetime]::MinValue
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
function RevisarPhoenix {
    if (((Get-Date) - $script:ultimaPhoenix).TotalSeconds -lt 120) { return }
    $script:ultimaPhoenix = Get-Date
    $minuto = (Get-Date).Hour * 60 + (Get-Date).Minute
    if ($minuto -lt 355 -or $minuto -ge 1260) { return }                       # de 05:55 a 21:00
    try {
        $acc = Get-Content $acceso -Raw -Encoding UTF8 | ConvertFrom-Json
        if (-not $acc.phoenix_usuario -or -not $acc.phoenix_contrasena) { return }
        if ((Test-Path $estadoPhoenix) -and ((Get-Content $estadoPhoenix -Raw).Trim() -eq 'rechazada')) { return }
        $cfg = Get-Content $config -Raw -Encoding UTF8 | ConvertFrom-Json
        $url = "$($cfg.sgs.TrimEnd('/'))/api/puertos_agente.php?action="
        $e = Invoke-RestMethod -Uri ($url + 'phoenix_estado') -Method Post -Body @{ clave = $cfg.clave } -Headers @{ 'X-SGS-Clave' = $cfg.clave } -TimeoutSec 20
        if ($e.activa) { return }
        $segura = ConvertTo-SecureString $acc.phoenix_contrasena
        $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($segura)
        try {
            $r = Invoke-RestMethod -Uri ($url + 'phoenix_iniciar') -Method Post -TimeoutSec 60 -Headers @{ 'X-SGS-Clave' = $cfg.clave } `
                -Body @{ clave = $cfg.clave; phoenix_usuario = [string]$acc.phoenix_usuario; phoenix_clave = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) }
            Anotar "Phoenix conectado solo hasta las $($r.vence)."
            Set-Content -Path $estadoPhoenix -Value 'conectado' -Encoding UTF8
        } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
    } catch {
        $codigo = try { [int]$_.Exception.Response.StatusCode } catch { 0 }
        if ($codigo -eq 422) {
            Anotar 'Phoenix no aceptó el usuario o la contraseña guardados: abre «Vínculo Wincore» y escríbelos de nuevo.'
            Set-Content -Path $estadoPhoenix -Value 'rechazada' -Encoding UTF8
        } elseif ($codigo -ne 0) { Anotar "Phoenix: el SGS respondió $codigo; se reintenta en 2 minutos." }
    }
}

# Telegram (2026-11-21, pedido del usuario: "el token como Phoenix"). Cada 2 min, de 05:55 a 21:00, pregunta al SGS si ya tiene el
# token del bot de hoy; si no (pasó la medianoche), se lo entrega. El token está guardado cifrado en esta laptop; el SGS lo comprueba
# con Telegram y lo guarda solo hasta las 23:59. Si Telegram lo rechaza, deja de intentar hasta que se escriba de nuevo.
$estadoTelegram = Join-Path $local 'vinculo_telegram.estado'
$script:ultimaTelegram = [datetime]::MinValue
function RevisarTelegram {
    if (((Get-Date) - $script:ultimaTelegram).TotalSeconds -lt 120) { return }
    $script:ultimaTelegram = Get-Date
    $minuto = (Get-Date).Hour * 60 + (Get-Date).Minute
    if ($minuto -lt 355 -or $minuto -ge 1260) { return }                       # de 05:55 a 21:00
    try {
        $acc = Get-Content $acceso -Raw -Encoding UTF8 | ConvertFrom-Json
        if (-not $acc.telegram_token) { return }
        if ((Test-Path $estadoTelegram) -and ((Get-Content $estadoTelegram -Raw).Trim() -eq 'rechazado')) { return }
        $cfg = Get-Content $config -Raw -Encoding UTF8 | ConvertFrom-Json
        $url = "$($cfg.sgs.TrimEnd('/'))/api/puertos_agente.php?action="
        $e = Invoke-RestMethod -Uri ($url + 'telegram_estado') -Method Post -Body @{ clave = $cfg.clave } -Headers @{ 'X-SGS-Clave' = $cfg.clave } -TimeoutSec 20
        if ($e.activo) { return }
        $segura = ConvertTo-SecureString $acc.telegram_token
        $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($segura)
        try {
            $r = Invoke-RestMethod -Uri ($url + 'telegram_token') -Method Post -TimeoutSec 30 -Headers @{ 'X-SGS-Clave' = $cfg.clave } `
                -Body @{ clave = $cfg.clave; telegram_token = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) }
            Anotar "Telegram: token entregado al SGS (bot @$($r.bot)) hasta las $($r.vence)."
            Set-Content -Path $estadoTelegram -Value 'entregado' -Encoding UTF8
        } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
    } catch {
        $codigo = try { [int]$_.Exception.Response.StatusCode } catch { 0 }
        if ($codigo -eq 422) {
            Anotar 'Telegram no aceptó el token guardado: abre «Vínculo Wincore» y escríbelo de nuevo.'
            Set-Content -Path $estadoTelegram -Value 'rechazado' -Encoding UTF8
        } elseif ($codigo -ne 0) { Anotar "Telegram: el SGS respondió $codigo; se reintenta en 2 minutos." }
    }
}
function RevisarSgs { RevisarPhoenix; RevisarTelegram }

# Certificado de Wincore: se conecta con Windows, que lo valida igual que Chrome (con las entidades de la laptop y las intermedias),
# y guarda esa cadena para el programa. Solo si Windows la aceptó: la verificación nunca se apaga.
function GuardarCadenaWincore {
    try {
        $datosCfg = Get-Content $config -Raw -Encoding UTF8 | ConvertFrom-Json
        $servidor = ([Uri]($(if ($datosCfg.wincore) { "$($datosCfg.wincore)" } else { 'https://wincore.win.pe' }))).Host
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
            if ($resultado.pem) { [IO.File]::WriteAllText((Join-Path $local 'wincore_ca.pem'), $resultado.pem + "`n", [Text.Encoding]::ASCII) }
            $ssl.Dispose()
        }
        $tcp.Dispose()
    } catch { }
}

New-Item -ItemType Directory -Force -Path $local | Out-Null
Remove-Item $detener -ErrorAction SilentlyContinue
Set-Content -Path $pidArchivo -Value $PID
$reinicios = New-Object System.Collections.Generic.List[datetime]
Anotar 'Vigilante iniciado.'
try {
    while (-not (Test-Path $detener)) {
        if (-not (Test-Path $config) -or -not (Test-Path $acceso)) { Anotar 'Falta la configuración o la contraseña guardada: abre «Vínculo Wincore».'; Estado 'sin_configurar'; break }
        $datos = Get-Content $acceso -Raw -Encoding UTF8 | ConvertFrom-Json
        try { $segura = ConvertTo-SecureString $datos.contrasena } catch { Anotar 'No se pudo leer la contraseña guardada (¿otro usuario de Windows?): abre «Vínculo Wincore» y escríbela de nuevo.'; Estado 'contrasena'; break }

        GuardarCadenaWincore
        $psi = New-Object Diagnostics.ProcessStartInfo $php
        $psi.Arguments = "$extensiones`"$programa`" `"$config`" `"$registro`""
        $psi.WorkingDirectory = $PSScriptRoot
        $psi.UseShellExecute = $false
        $psi.CreateNoWindow = $true
        $psi.RedirectStandardInput = $true
        $proceso = [Diagnostics.Process]::Start($psi)
        $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($segura)
        try {
            $bytes = [Text.UTF8Encoding]::new($false).GetBytes([string]$datos.usuario + "`n" + [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) + "`n")
            $proceso.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length)
            $proceso.StandardInput.Close()
            [Array]::Clear($bytes, 0, $bytes.Length)
        } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
        Estado 'funcionando'
        while (-not $proceso.WaitForExit(5000)) { RevisarSgs }   # mientras atiende Wincore, cada 2 min revisa Phoenix y Telegram
        $codigo = $proceso.ExitCode
        if (Test-Path $detener) { break }

        # (En PowerShell, break y continue dentro de un switch afectan al switch, no al bucle: se usa $seguir.)
        $seguir = $false
        switch ($codigo) {
            0 { Estado 'detenido' }
            2 { Anotar 'La clave del programa no es válida o fue anulada: genera otra en Consultar puertos y abre «Vínculo Wincore».'; Estado 'clave' }
            3 { Anotar 'Wincore no aceptó el usuario o la contraseña guardados: abre «Vínculo Wincore» y escríbelos de nuevo.'; Estado 'contrasena' }
            default {
                $espera = if ($codigo -eq 5) { 60 } else { 15 }
                # Si se cae una y otra vez (más de 10 veces en 10 minutos), se espera 5 minutos para no insistir sin sentido
                $ahora = Get-Date
                $reinicios.Add($ahora)
                $reinicios.RemoveAll({ param($x) ($ahora - $x).TotalMinutes -gt 10 }) | Out-Null
                if ($reinicios.Count -gt 10) { $espera = 300 }
                Anotar "Se vuelve a conectar solo en $espera s."
                Estado 'reconectando'
                for ($i = 0; $i -lt $espera -and -not (Test-Path $detener); $i++) { Start-Sleep -Seconds 1; RevisarSgs }
                $seguir = $true
            }
        }
        if (-not $seguir) { break }
    }
} finally {
    Remove-Item $pidArchivo, $detener -ErrorAction SilentlyContinue
    Anotar 'Vigilante detenido.'
}
