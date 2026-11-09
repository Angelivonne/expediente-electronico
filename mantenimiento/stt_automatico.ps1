# Supervisor automático del dictado: servicio STT local + túnel Cloudflare + STT_URL en Railway.
# Se ejecuta oculto al iniciar sesión en Windows (stt_automatico.vbs en la carpeta Startup).
# No requiere intervención: reinicia lo que se caiga y actualiza Railway si cambia la URL del túnel.

$proyecto    = 'C:\xampp\htdocs\ExpedienteFEMECI'
$cloudflared = 'C:\Users\abelM\bin\cloudflared.exe'
$railway     = 'C:\Users\abelM\AppData\Roaming\npm\railway.cmd'
$registro    = Join-Path $proyecto 'privado\registros'
$urlArchivo  = Join-Path $proyecto 'privado\stt_url_actual.txt'
$tunnelLog   = Join-Path $registro 'cloudflared.log'

if (-not (Test-Path $registro)) { New-Item -ItemType Directory -Path $registro -Force | Out-Null }

function Test-STTActivo {
    [bool](Get-NetTCPConnection -LocalPort 8766 -State Listen -ErrorAction SilentlyContinue)
}

function Iniciar-STT {
    if (Test-STTActivo) { return }
    Start-Process -FilePath 'python' -ArgumentList 'app\servicios\ia\servicio_stt.py' -WorkingDirectory $proyecto -WindowStyle Hidden
    $espera = 0
    while (-not (Test-STTActivo) -and $espera -lt 180) { Start-Sleep 5; $espera += 5 }
}

function Obtener-UrlTunel {
    if (-not (Test-Path $tunnelLog)) { return $null }
    $coincidencia = Select-String -Path $tunnelLog -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' -ErrorAction SilentlyContinue | Select-Object -Last 1
    if ($coincidencia) { return $coincidencia.Matches[0].Value }
    return $null
}

function Sincronizar-UrlRailway([string]$url) {
    $anterior = ''
    if (Test-Path $urlArchivo) { $anterior = (Get-Content $urlArchivo -Raw).Trim() }
    if ($url -eq $anterior) { return }
    Push-Location $proyecto
    & $railway variables --service web --set "STT_URL=$url" *> $null
    $codigo = $LASTEXITCODE
    Pop-Location
    if ($codigo -eq 0) { Set-Content -Path $urlArchivo -Value $url -NoNewline }
}

function Probar-SaludTunel([string]$url) {
    try {
        $resp = Invoke-RestMethod -Uri "$url/salud" -TimeoutSec 6 -ErrorAction Stop
        return ($resp.ok -eq $true)
    } catch {
        return $false
    }
}

while ($true) {
    Iniciar-STT

    # Túnel quick con HTTP/2 forzado para evitar bloqueos de puertos UDP/QUIC
    if (Test-Path $tunnelLog) { Remove-Item $tunnelLog -Force -ErrorAction SilentlyContinue }
    $tunel = Start-Process -FilePath $cloudflared -ArgumentList 'tunnel','--url','http://localhost:8766','--protocol','http2','--no-autoupdate' -RedirectStandardError $tunnelLog -WindowStyle Hidden -PassThru

    $url = $null
    $espera = 0
    while (-not $url -and $espera -lt 90 -and -not $tunel.HasExited) {
        Start-Sleep 3; $espera += 3
        $url = Obtener-UrlTunel
    }

    if ($url) {
        Sincronizar-UrlRailway $url

        # Vigilante inteligente: prueba salud del túnel cada 30 segundos
        $fallosConsecutivos = 0
        while (-not $tunel.HasExited) {
            Start-Sleep 30
            if (-not (Test-STTActivo)) { Iniciar-STT }

            $salud = Probar-SaludTunel $url
            if ($salud) {
                $fallosConsecutivos = 0
            } else {
                $fallosConsecutivos++
                # Si falló 3 veces seguidas (ej. tras suspender la laptop o cambio de Wi-Fi), reiniciar túnel
                if ($fallosConsecutivos -ge 3) {
                    try { Stop-Process -Id $tunel.Id -Force -ErrorAction SilentlyContinue } catch {}
                    break
                }
            }
        }
    } else {
        try { Stop-Process -Id $tunel.Id -Force -ErrorAction SilentlyContinue } catch {}
    }

    Start-Sleep 5
}
