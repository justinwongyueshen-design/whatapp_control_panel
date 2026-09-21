@echo off
setlocal
echo ====================================================================
echo WhatsApp Bot Worker - Daemon Starting
echo ====================================================================

if exist "venv\Scripts\activate.bat" (
    call venv\Scripts\activate.bat
)

python worker.py
pause
