@echo off
chcp 65001 >nul
cd /d "%~dp0"
where php >nul 2>nul
if errorlevel 1 (
  echo PHP nao foi encontrado. Instale o PHP e adicione a pasta dele ao PATH.
  echo Depois abra este arquivo novamente.
  pause
  exit /b 1
)
echo Iniciando o servidor local do SIGEP...
echo Mantenha esta janela aberta enquanto estiver usando o site.
start "" cmd /c "timeout /t 2 >nul & start http://localhost:8000/"
php -S localhost:8000 -t . backend/dev-router.php
pause
