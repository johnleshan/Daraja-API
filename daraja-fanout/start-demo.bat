@echo off
title Daraja Demo
echo ================================================
echo   DARAJA FANOUT DEMO - starting 3 services + tunnel
echo ================================================
start "App B :9002" /MIN /D "%~dp0system-b"   cmd /c "C:\xampp\php\php.exe -S 0.0.0.0:9002 index.php"
start "App A :9001" /MIN /D "%~dp0system-a"   cmd /c "C:\xampp\php\php.exe -S 0.0.0.0:9001 index.php"
start "Middleware :9000" /MIN /D "%~dp0middleware" cmd /c "C:\xampp\php\php.exe -S 0.0.0.0:9000 index.php"
start "TUNNEL - copy the https URL from here!"    cmd /k ssh -p 443 -R0:127.0.0.1:9000 free.pinggy.io
timeout /t 5 >nul
echo.
echo NEXT (one manual step): the TUNNEL window shows a public https URL.
echo Copy it and run:
echo.
echo   powershell -File "%~dp0demo.ps1" -TunnelUrl "https://xxxx.run.pinggy-free.link"
echo.
echo That patches callback URLs + opens both dashboards (login: admin / changeme).
echo Do NOT close the TUNNEL window during the demo!
pause

