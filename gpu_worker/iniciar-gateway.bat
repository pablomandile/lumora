@echo off
REM Arranca el gateway de Lumora en http://127.0.0.1:8766
REM (solo local: desde afuera se llega por el tunel de Cloudflare).
cd /d "%~dp0"
title Lumora GPU Gateway

if not exist .venv\Scripts\python.exe (
    echo Primero ejecuta instalar.bat
    pause
    exit /b 1
)

REM --timeout-keep-alive 120 evita 502 intermitentes detras de cloudflared.
.venv\Scripts\python -m uvicorn lumora_gateway.app:app --host 127.0.0.1 --port 8766 --timeout-keep-alive 120
