@echo off
rem Local development server: http://127.0.0.1:8000
rem Uses PHP from PATH, or set PHP_BIN to a php.exe (e.g. set PHP_BIN=C:\Tools\php82\php.exe)
cd /d "%~dp0\.."
if "%PHP_BIN%"=="" set PHP_BIN=php
"%PHP_BIN%" -S 127.0.0.1:8000 -t public public/index.php
