@echo off
title BRIS Launcher
echo Starting MySQL...
start "BRIS MySQL" /min cmd /c "cd /d C:\xampp\mysql && bin\mysqld.exe --defaults-file=bin\my.ini --standalone"
timeout /t 5 /nobreak >nul

echo Starting the local AI model (Ollama) for the AI Assistant...
tasklist /FI "IMAGENAME eq ollama.exe" | find /I "ollama.exe" >nul || start "" "%LOCALAPPDATA%\Programs\Ollama\ollama app.exe"
rem Preload the AI model and keep it in memory so the first question is fast.
timeout /t 5 /nobreak >nul
echo Loading the AI model (about 10 seconds)...
curl -s -m 300 http://127.0.0.1:11434/api/generate -d "{\"model\":\"qwen2.5:3b\",\"keep_alive\":-1}" >nul

echo Starting backend (Laravel)...
start "BRIS Backend" cmd /k "cd /d %~dp0backend && php artisan serve --host=127.0.0.1 --port=8000"

echo Starting the scheduler (nightly backup at 1:00 AM, nightly analysis at 2:00 AM)...
start "BRIS Scheduler" /min cmd /k "cd /d %~dp0backend && php artisan schedule:work"

echo Starting frontend (React)...
start "BRIS Frontend" cmd /k "cd /d %~dp0frontend && npm run dev -- --port 5173"

timeout /t 8 /nobreak >nul
start http://localhost:5173
echo.
echo BRIS is running. Keep the Backend and Frontend windows open.
echo To stop BRIS, close those windows.
pause
