@echo off
setlocal
echo ====================================================================
echo WhatsApp Bot Worker - Link WhatsApp Web Account
echo ====================================================================

if exist "venv\Scripts\activate.bat" (
    call venv\Scripts\activate.bat
)

python link_session.py
pause
