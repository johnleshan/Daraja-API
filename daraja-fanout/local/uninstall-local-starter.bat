@echo off
REM Removes the auto-start tasks (keyboard kill switch)
schtasks /Delete /TN "Daraja Sync Worker"  /F 2>NUL
schtasks /Delete /TN "Daraja AppB server"  /F 2>NUL
schtasks /Delete /TN "Daraja AppA server"  /F 2>NUL
schtasks /Delete /TN "Daraja Middleware local" /F 2>NUL
echo Tasks removed. Local stack will NOT auto-start anymore.
pause
