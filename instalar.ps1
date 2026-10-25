# Instalação local no Windows: cria o .env, o banco e as tabelas, e abre o sistema.
#
# Como usar (PowerShell, dentro da pasta do projeto):
#   Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
#   .\instalar.ps1
#
# Requisitos: PHP 7.4+ (com pdo_mysql) e um MySQL/MariaDB ligado (XAMPP, Laragon, MySQL Installer...).

$ErrorActionPreference = 'Continue'
Set-Location $PSScriptRoot

# --- PHP ---
$php = $null
$cmd = Get-Command php -ErrorAction SilentlyContinue
if ($cmd) { $php = $cmd.Source }
elseif (Test-Path 'C:\php\php.exe') { $php = 'C:\php\php.exe' }
if (-not $php) {
    Write-Host 'PHP não encontrado. Instale o PHP 7.4+ (ex.: https://windows.php.net/download) e deixe o php.exe no PATH.' -ForegroundColor Red
    exit 1
}
Write-Host "PHP: $php"

# --- .env ---
if (-not (Test-Path '.env')) {
    Write-Host ''
    Write-Host 'Vamos configurar o acesso ao MySQL (Enter aceita o valor entre colchetes).'
    $servidor = Read-Host 'Servidor [127.0.0.1]'; if (-not $servidor) { $servidor = '127.0.0.1' }
    $porta    = Read-Host 'Porta [3306]';         if (-not $porta)    { $porta = '3306' }
    $usuario  = Read-Host 'Usuário [root]';       if (-not $usuario)  { $usuario = 'root' }
    $senha    = Read-Host 'Senha (pode ficar vazia)'
    $banco    = Read-Host 'Nome do banco [futebol]'; if (-not $banco) { $banco = 'futebol' }

    $texto = Get-Content '.env.example' -Raw
    $texto = $texto -replace '(?m)^SERVER=.*$', "SERVER=$servidor"
    $texto = $texto -replace '(?m)^PORTA=.*$',  "PORTA=$porta"
    $texto = $texto -replace '(?m)^USER=.*$',   "USER=$usuario"
    if ($senha.Contains("'")) {
        Write-Host "Senha com apóstrofo (') não é suportada pelo assistente. Copie .env.example para .env e preencha à mão." -ForegroundColor Yellow
        exit 1
    }
    $texto = $texto -replace '(?m)^PASS=.*$',   ("PASS='" + $senha.Replace('$', '$$') + "'")
    $texto = $texto -replace '(?m)^BANCO=.*$',  "BANCO=$banco"
    [System.IO.File]::WriteAllText((Join-Path $PSScriptRoot '.env'), $texto, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host '.env criado.'
}

# --- banco e tabelas ---
& $php cron\instalar.php
if ($LASTEXITCODE -ne 0) {
    Write-Host ''
    Write-Host 'A instalação parou por causa do erro acima. Corrija e rode .\instalar.ps1 de novo.' -ForegroundColor Red
    exit 1
}

# --- servidor local ---
Write-Host ''
Write-Host 'Abrindo http://localhost:8080  (Ctrl+C para parar o servidor)' -ForegroundColor Green
Start-Process 'http://localhost:8080/'
& $php -S localhost:8080 servidor.php
