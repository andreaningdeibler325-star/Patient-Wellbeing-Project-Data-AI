@echo off
title Patient Wellbeing AI Project

echo ==========================================
echo   Starting Patient Wellbeing AI Project
echo ==========================================
echo.

echo Starting AI server on port 3001...
start "Patient Wellbeing AI Server" powershell -NoExit -Command "Set-Location '%~dp0ai-agent'; node dist/server.js"

timeout /t 2 /nobreak >nul

echo Starting PHP server on port 8000...
start "Patient Wellbeing PHP Server" powershell -NoExit -Command "Set-Location '%~dp0'; PHP -S localhost:8000"

timeout /t 3 /nobreak >nul

echo.
echo ==========================================
echo   Both servers have been started.
echo ==========================================
echo.
echo AI Server:
echo http://localhost:3001
echo.
echo Web App:
echo http://localhost:8000
start http://localhost:8000/index.html
echo.
pause