@echo off
setlocal
echo ====================================================================
echo WhatsApp Bot Worker - Environment Setup
echo ====================================================================

:: Check Python availability
python --version >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Python was not found in your system PATH.
    echo Please install Python 3.11 or 3.12 from https://www.python.org/downloads/
    echo Make sure to check "Add Python to PATH" during installation.
    pause
    exit /b 1
)

echo [1/4] Found Python:
python --version

:: Create virtual environment
if not exist "venv" (
    echo [2/4] Creating virtual environment (venv)...
    python -m venv venv
) else (
    echo [2/4] Virtual environment already exists.
)

:: Activate virtualenv
call venv\Scripts\activate.bat

:: Upgrade pip and install requirements
echo [3/4] Installing dependencies from requirements.txt...
python -m pip install --upgrade pip
pip install -r requirements.txt

:: Install Playwright Chromium
echo [4/4] Installing Playwright Chromium browser binaries...
playwright install chromium

echo ====================================================================
echo Setup complete!
echo Next step: Run "2_LINK_WHATSAPP.bat" to link your WhatsApp account.
echo ====================================================================
pause
