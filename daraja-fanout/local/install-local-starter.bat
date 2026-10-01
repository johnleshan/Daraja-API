@echo off
REM ============================================================
REM  ONE-TIME local setup: installs startup tasks so the whole
REM  stack runs at every boot, no one needs to remember anything.
REM  Run once, right-click > Run as administrator.
REM ============================================================
set ROOT=%~dp0..
set PHP=C:\xampp\php\php.exe
set PHPDIR=%~dp0..

schtasks /Create /F /TN "Daraja Sync Worker" /SC ONLOGON /DELAY 0000:15 ^
  /TR "\"%PHP%\" \"%~dp0sync-worker.php\""

schtasks /Create /F /TN "Daraja AppB server" /SC ONLOGON /DELAY 0000:15 ^
  /TR "cmd /c cd /d \"%~dp0..\system-b\" && \"%PHP%\" -S 0.0.0.0:9002 index.php"

schtasks /Create /F /TN "Daraja AppA server" /SC ONLOGON /DELAY 0000:15 ^
  /TR "cmd /c cd /d \"%~dp0..\system-a\" && \"%PHP%\" -S 0.0.0.0:9001 index.php"

schtasks /Create /F /TN "Daraja Middleware local" /SC ONLOGON /DELAY 0000:15 ^
  /TR "cmd /c cd /d \"%~dp0..middleware\" && \"%PHP%\" -S 0.0.0.0:9000 index.php"

echo.
echo Installed 4 startup tasks:
echo  - Daraja Sync Worker (pulls new payment records from the online ledger)
echo  - App A local dashboard on http://localhost:9001
echo  - App B local dashboard on http://localhost:9002
echo  - Middle-ware locally on http://localhost:9000 (optional fallback)
echo.
echo Every boot after this will start them automatically 15s after login.
pause
