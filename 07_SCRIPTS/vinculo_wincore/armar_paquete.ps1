# 07_SCRIPTS/vinculo_wincore/armar_paquete.ps1 - Arma VinculoWincore.zip para la laptop sin XAMPP (2026-11-19).
# Junta el programa, sus lanzadores, el instalador y un PHP mínimo sacado de C:\xampp\php (solo curl, openssl y mbstring, con
# sus bibliotecas y los certificados de C:\xampp\apache\bin). Uso: powershell -File armar_paquete.ps1 [carpeta de salida]
param([string]$salida = (Join-Path $env:USERPROFILE 'Downloads'))
$ErrorActionPreference = 'Stop'
$xampp = 'C:\xampp\php'
$tmp = Join-Path $env:TEMP 'VinculoWincore'
Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path "$tmp\php\ext" | Out-Null

foreach ($n in 'vinculo_wincore.php', 'vigilante.ps1', 'Vinculo Wincore.ps1', 'Vinculo Wincore.cmd', 'Detener Vinculo Wincore.ps1', 'Detener Vinculo Wincore.cmd', 'Instalar.ps1', 'Instalar.cmd', 'LEEME.txt') {
    Copy-Item (Join-Path $PSScriptRoot $n) $tmp
}
foreach ($n in 'php.exe', 'php8ts.dll', 'libcrypto-3-x64.dll', 'libssl-3-x64.dll', 'libssh2.dll', 'nghttp2.dll', 'libsasl.dll') { Copy-Item (Join-Path $xampp $n) "$tmp\php" }
foreach ($n in 'php_curl.dll', 'php_openssl.dll', 'php_mbstring.dll') { Copy-Item (Join-Path "$xampp\ext" $n) "$tmp\php\ext" }
# Ejecución de Visual C++ junto al programa (despliegue local permitido), por si la laptop no la tiene instalada
foreach ($n in 'vcruntime140.dll', 'vcruntime140_1.dll') { $f = Join-Path $env:WINDIR "System32\$n"; if (Test-Path $f) { Copy-Item $f "$tmp\php" } }
Copy-Item 'C:\xampp\apache\bin\curl-ca-bundle.crt' "$tmp\php\cacert.pem"

$zip = Join-Path $salida 'VinculoWincore.zip'
Remove-Item $zip -ErrorAction SilentlyContinue
Compress-Archive -Path "$tmp\*" -DestinationPath $zip
Remove-Item -Recurse -Force $tmp
"Paquete listo: $zip ({0:N1} MB)" -f ((Get-Item $zip).Length / 1MB)
