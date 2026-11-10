[CmdletBinding()]
param(
    [switch]$Stop
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$project = 'C:\xampp\htdocs\ExpedienteFEMECI'
$launcher = Join-Path $project 'ExpedienteFEMECI.bat'
$localUrl = 'http://127.0.0.1/ExpedienteFEMECI/'
$stateDir = Join-Path $env:LOCALAPPDATA 'ExpedienteFEMECI'
$logPath = Join-Path $stateDir 'ngrok-supervisor.log'
$pidPath = Join-Path $stateDir 'ngrok.pid'
$stdoutPath = Join-Path $stateDir 'ngrok-agent.log'
$stderrPath = Join-Path $stateDir 'ngrok-agent-error.log'
$desktop = [Environment]::GetFolderPath('Desktop')
$linkPath = Join-Path $desktop 'ExpedienteFEMECI-link.txt'
$script:ngrokExe = $null

New-Item -ItemType Directory -Path $stateDir -Force | Out-Null

function Write-Log {
    param([Parameter(Mandatory)][string]$Message)
    $entry = '{0} {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    Add-Content -LiteralPath $logPath -Value $entry -Encoding UTF8
}

function Find-Ngrok {
    $command = Get-Command ngrok.exe, ngrok -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($command -and $command.Source -and (Test-Path -LiteralPath $command.Source)) {
        return $command.Source
    }

    $packagePattern = Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages\Ngrok.Ngrok_*\ngrok.exe'
    $files = @(Get-ChildItem -Path $packagePattern -File -ErrorAction SilentlyContinue)
    if ($files.Count -ne 1) {
        throw "Se esperaba un ngrok.exe instalado por WinGet; se encontraron $($files.Count)."
    }
    return $files[0].FullName
}

function Test-LocalApp {
    try {
        $response = Invoke-WebRequest -Uri $localUrl -MaximumRedirection 6 -TimeoutSec 8 -UseBasicParsing
        return [int]$response.StatusCode -ge 200 -and [int]$response.StatusCode -lt 400
    } catch {
        Write-Log ('No se pudo verificar la aplicación local: ' + $_.Exception.Message)
        return $false
    }
}

function Test-LocalDatabase {
    $client = [System.Net.Sockets.TcpClient]::new()
    try {
        $connect = $client.BeginConnect('127.0.0.1', 3306, $null, $null)
        if (-not $connect.AsyncWaitHandle.WaitOne(3000)) {
            Write-Log 'MySQL todavía no acepta conexiones en 127.0.0.1:3306.'
            return $false
        }
        $client.EndConnect($connect)
        return $true
    } catch {
        Write-Log ('No se pudo verificar MySQL: ' + $_.Exception.Message)
        return $false
    } finally {
        $client.Dispose()
    }
}

function Test-LoginGate {
    param([Parameter(Mandatory)][string]$Uri)
    try {
        $response = Invoke-WebRequest -Uri $Uri -Headers @{'ngrok-skip-browser-warning' = '1'} `
            -MaximumRedirection 6 -TimeoutSec 12 -UseBasicParsing
    } catch {
        return $false
    }

    $finalUri = $null
    try {
        $finalUri = $response.BaseResponse.RequestMessage.RequestUri
    } catch {
        try {
            $finalUri = $response.BaseResponse.ResponseUri
        } catch {
            return $false
        }
    }

    $status = [int]$response.StatusCode
    $path = [uri]$finalUri
    $body = [string]$response.Content
    $isLoginPath = $path.AbsolutePath -match '(?i)/(acceso|login)/?$'
    $hasPasswordField = $body -match '(?is)<input\b[^>]*\btype\s*=\s*(?:"password"|''password''|password)'
    $hasLoginForm = $body -match '(?is)<form\b' -and $body -match '(?i)iniciar sesi[oó]n|acceso'
    return $status -ge 200 -and $status -lt 400 -and $isLoginPath -and $hasPasswordField -and $hasLoginForm
}

function Get-NgrokApi {
    try {
        return Invoke-RestMethod -Uri 'http://127.0.0.1:4040/api/tunnels' -TimeoutSec 3
    } catch {
        return $null
    }
}

function Test-LocalHttp80 {
    param([Parameter(Mandatory)]$Tunnel)
    try {
        $address = [string]$Tunnel.config.addr
        if ($address -notmatch '^https?://') {
            $address = 'http://' + $address
        }
        $uri = [uri]$address
        return $uri.Port -eq 80 -and $uri.Host -in @('localhost', '127.0.0.1')
    } catch {
        return $false
    }
}

function Get-ConfiguredTunnel {
    param([Parameter(Mandatory)]$Api)
    foreach ($tunnel in @($Api.tunnels)) {
        if ($tunnel.proto -eq 'https' -and (Test-LocalHttp80 -Tunnel $tunnel)) {
            return $tunnel
        }
    }
    return $null
}

function Remove-PublicLink {
    if (Test-Path -LiteralPath $linkPath) {
        Remove-Item -LiteralPath $linkPath -Force
    }
}

function Confirm-And-PublishTunnel {
    param([Parameter(Mandatory)]$Tunnel)
    $publicUrl = [string]$Tunnel.public_url
    if ($publicUrl -notmatch '^https://') {
        return $false
    }

    $loginUrl = $publicUrl.TrimEnd('/') + '/ExpedienteFEMECI/'
    if (-not (Test-LoginGate -Uri $loginUrl)) {
        return $false
    }

    $contents = @(
        'Enlace verificado: ' + $publicUrl.TrimEnd('/') + '/ExpedienteFEMECI/'
        'Verificado: ' + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
        'El acceso a la aplicación requiere iniciar sesión.'
    )
    $currentContents = ''
    if (Test-Path -LiteralPath $linkPath) {
        $currentContents = Get-Content -LiteralPath $linkPath -Raw
    }
    if ($currentContents -match [regex]::Escape($contents[0])) {
        return $true
    }

    $tempPath = $linkPath + '.tmp'
    Set-Content -LiteralPath $tempPath -Value $contents -Encoding UTF8
    Move-Item -LiteralPath $tempPath -Destination $linkPath -Force
    Write-Log ('Túnel HTTPS verificado y protegido por el login de la aplicación: ' + $publicUrl)
    return $true
}

function Stop-OwnedNgrok {
    if (-not (Test-Path -LiteralPath $pidPath)) {
        return
    }

    $recordedPid = 0
    if (-not [int]::TryParse((Get-Content -LiteralPath $pidPath -Raw).Trim(), [ref]$recordedPid)) {
        Write-Log 'El archivo PID de ngrok es inválido; no se terminó ningún proceso.'
        Remove-Item -LiteralPath $pidPath -Force
        return
    }

    $processInfo = Get-CimInstance Win32_Process -Filter "ProcessId = $recordedPid" -ErrorAction SilentlyContinue
    if ($processInfo -and $script:ngrokExe -and $processInfo.ExecutablePath -and
        [string]::Equals($processInfo.ExecutablePath, $script:ngrokExe, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-Process -Id $recordedPid -Force -ErrorAction Stop
        Write-Log 'Se detuvo el agente ngrok iniciado por este supervisor.'
    }
    Remove-Item -LiteralPath $pidPath -Force
}

function Start-LocalServices {
    if (-not (Test-Path -LiteralPath $launcher)) {
        Write-Log "No se encontró el iniciador local: $launcher"
        return $false
    }

    $stdout = Join-Path $stateDir 'arranque-local.log'
    $stderr = Join-Path $stateDir 'arranque-local-error.log'
    $arguments = @('/d', '/c', ('""{0}" auto"' -f $launcher))
    Write-Log 'La aplicación local no responde; iniciando MySQL y Apache mediante el BAT en modo automático.'
    $process = Start-Process -FilePath $env:ComSpec -ArgumentList $arguments -WorkingDirectory $project `
        -WindowStyle Hidden -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru
    if (-not $process.WaitForExit(180000)) {
        Write-Log 'El arranque local excedió 180 segundos; se volverá a comprobar antes de reintentar.'
        return $false
    }
    if ($process.ExitCode -ne 0) {
        Write-Log "El BAT automático terminó con código $($process.ExitCode); se volverá a intentar."
        return $false
    }
    return (Test-LocalDatabase) -and (Test-LocalApp)
}

function Ensure-Tunnel {
    $api = Get-NgrokApi
    if ($api) {
        $existingTunnel = Get-ConfiguredTunnel -Api $api
        if ($existingTunnel) {
            if (Confirm-And-PublishTunnel -Tunnel $existingTunnel) {
                return $true
            }
            Write-Log 'El túnel local existe, pero no se pudo verificar el login remoto; no se publicó como listo.'
            return $false
        }
        Write-Log 'El panel local de ngrok está ocupado por otro túnel; no se iniciará un agente duplicado.'
        return $false
    }

    if (-not $script:ngrokExe) {
        $script:ngrokExe = Find-Ngrok
    }
    & $script:ngrokExe config check *> $null
    if ($LASTEXITCODE -ne 0) {
        Write-Log 'La configuración de ngrok no es válida; se reintentará sin iniciar un túnel.'
        return $false
    }

    Set-Content -LiteralPath $stdoutPath -Value '' -Encoding UTF8
    Set-Content -LiteralPath $stderrPath -Value '' -Encoding UTF8
    $process = Start-Process -FilePath $script:ngrokExe `
        -ArgumentList @('http', '80', '--log=stdout', '--log-format=json') `
        -WorkingDirectory $project -WindowStyle Hidden `
        -RedirectStandardOutput $stdoutPath -RedirectStandardError $stderrPath -PassThru
    Set-Content -LiteralPath $pidPath -Value $process.Id -Encoding ASCII
    Write-Log 'Agente ngrok iniciado; esperando la conexión HTTPS y verificando el control de acceso.'

    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        Start-Sleep -Seconds 2
        if ($process.HasExited) {
            Write-Log "El agente ngrok terminó con código $($process.ExitCode); se reintentará."
            Remove-Item -LiteralPath $pidPath -Force -ErrorAction SilentlyContinue
            return $false
        }
        $api = Get-NgrokApi
        if ($api) {
            $tunnel = Get-ConfiguredTunnel -Api $api
            if ($tunnel -and (Confirm-And-PublishTunnel -Tunnel $tunnel)) {
                return $true
            }
        }
    }

    Write-Log 'Ngrok no confirmó un túnel HTTPS con login en 60 segundos; se reiniciará en el siguiente intento.'
    Stop-OwnedNgrok
    return $false
}

if ($Stop) {
    $script:ngrokExe = Find-Ngrok
    Stop-OwnedNgrok
    Remove-PublicLink
    Write-Log 'Solicitud manual de detención del túnel.'
    exit 0
}

$mutex = [System.Threading.Mutex]::new($false, 'Local\ExpedienteFEMECI-NgrokSupervisor')
$ownsMutex = $false
try {
    $ownsMutex = $mutex.WaitOne(0)
} catch [System.Threading.AbandonedMutexException] {
    $ownsMutex = $true
}
if (-not $ownsMutex) {
    exit 0
}

try {
    $script:ngrokExe = Find-Ngrok
    Write-Log 'Supervisor iniciado.'
    while ($true) {
        try {
            $localReady = (Test-LocalDatabase) -and (Test-LocalApp)
            if (-not $localReady) {
                Remove-PublicLink
                Stop-OwnedNgrok
                [void](Start-LocalServices)
            }

            $localReady = (Test-LocalDatabase) -and (Test-LocalApp)
            if ($localReady) {
                [void](Ensure-Tunnel)
            } else {
                Write-Log 'MySQL o la aplicación local aún no están listos; se volverá a comprobar.'
            }
        } catch {
            Write-Log ('Error explícito del supervisor: ' + $_.Exception.Message)
        }
        Start-Sleep -Seconds 30
    }
} finally {
    Write-Log 'Supervisor detenido.'
    if ($ownsMutex) {
        $mutex.ReleaseMutex()
    }
    $mutex.Dispose()
}
