# Supervisor automático del dictado: servicio STT local (puerto 8766).
# Se ejecuta oculto al iniciar sesión en Windows (stt_automatico.vbs en la carpeta Startup).
# No requiere intervención: reinicia el transcriptor si se cae.
#
# Actualización local: antes también abría un túnel Cloudflare y sincronizaba
# la URL con Railway, pero el sistema ahora corre 100% en local (XAMPP),
# así que esa parte ya no es necesaria y se retiró.

$proyecto = Split-Path -Parent $PSScriptRoot

function Test-STTActivo {
    [bool](Get-NetTCPConnection -LocalPort 8766 -State Listen -ErrorAction SilentlyContinue)
}

function Iniciar-STT {
    if (Test-STTActivo) { return }
    Start-Process -FilePath 'python' -ArgumentList 'app\servicios\ia\servicio_stt.py' -WorkingDirectory $proyecto -WindowStyle Hidden
    $espera = 0
    while (-not (Test-STTActivo) -and $espera -lt 180) { Start-Sleep 5; $espera += 5 }
}

while ($true) {
    Iniciar-STT
    Start-Sleep 30
}
