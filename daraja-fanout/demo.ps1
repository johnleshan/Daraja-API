param([Parameter(Mandatory)][string]$TunnelUrl, [string]$Php = "C:\xampp\php\php.exe", [string]$Root = "C:\Users\JOHN LESHAN\Documents\PROJECTS\Daraja-API\daraja-fanout")
$ErrorActionPreference = "Stop"
$tunnel = $TunnelUrl.TrimEnd('/')
$here = (Get-Item $Root).FullName

Write-Host "== 1. Patching .env files with tunnel URL =="
foreach ($s in "system-a", "system-b") {
    $envPath = Join-Path $here "$s\.env"
    if (-not (Test-Path $envPath)) { throw "$envPath missing" }
    $lines = Get-Content $envPath
    $lines = $lines | ForEach-Object {
        if ($_ -like "STK_CALLBACK_URL=*") { "STK_CALLBACK_URL=$tunnel/callback" } else { $_ }
    }
    Set-Content $envPath -Value $lines -Encoding ASCII
    Write-Host "   patched $s"
}

Write-Host "== 2. Starting Applications A(nothing change)/middleware at 9000, A at 9001, B at 9002 =="
foreach ($port in 9000, 9001, 9002) {
    $already = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue
    if ($already) { Write-Host "   port $port already listening - reusing"; continue }
    $app = switch ($port) { 9000 { "middleware" } 9001 { "system-a" } 9002 { "system-b" } }
    Start-Process -FilePath $Php -ArgumentList "-S", "0.0.0.0:$port", "index.php" -WorkingDirectory (Join-Path $here $app) -WindowStyle Minimized
    Write-Host "   started $app on :$port"
}
Start-Sleep -Seconds 2

Write-Host "== 3. Health checks =="
foreach ($port in 9000, 9001, 9002) {
    try { $h = Invoke-WebRequest -UseBasicParsing "http://localhost:$port/health" -TimeoutSec 5 } catch { Write-Host "   WARN: port $port not responding yet" }
}
Write-Host "== 4. Opening dashboards in browser =="
Start-Process "http://localhost:9000/health"
Start-Sleep -Seconds 1
Start-Process "http://localhost:9001/"
Start-Sleep -Seconds 1
Start-Process "http://localhost:9002/"

Write-Host ""
Write-Host "DEMO READY. Login for dashboards: admin / changeme"
Write-Host "Tunnel callbackURL in use: $tunnel/callback"
Write-Host "-> STK Push prompts sent from A or B dashboard now reach BOTH apps."
Write-Host "Keep the ssh (pinggy) window open for the whole demo!"

