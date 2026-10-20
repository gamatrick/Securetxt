@echo off
rem ==== Securetxt ====
rem Mets ici le chemin complet de ton php.exe 8.5 (celui de PhpStorm) :
set "PHP85=C:\Users\valentin.lamouche\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"

if not exist "%PHP85%" (
    echo PHP 8.5 introuvable : %PHP85%
    echo Ouvre securetxt.bat avec un editeur et corrige la ligne PHP85.
    pause
    exit /b 1
)
chcp 65001 >nul
title Securetxt
"%PHP85%" "%~dp0securetxt.php" %*
if errorlevel 1 pause
