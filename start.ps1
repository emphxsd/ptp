# Запуск сервиса в Docker: при первом запуске создаёт .env со случайными паролями, собирает и запускает
# контейнеры, ждёт готовности и открывает сайт. Запускается из start.bat.
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$envFile = Join-Path $PSScriptRoot '.env'

function Fail([string] $message) {
    Write-Host ''
    Write-Host $message -ForegroundColor Red
    Read-Host 'Нажмите Enter, чтобы закрыть окно'
    exit 1
}

function New-Secret([int] $bytes) {
    $buffer = New-Object byte[] $bytes
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($buffer)
    return $buffer
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Fail 'Docker не установлен. Установите Docker Desktop: https://www.docker.com/products/docker-desktop/ и запустите start.bat снова.'
}
docker info *> $null
if ($LASTEXITCODE -ne 0) {
    Fail 'Docker Desktop не запущен. Откройте Docker Desktop из меню «Пуск», дождитесь надписи «Engine running» и запустите start.bat снова.'
}

if (-not (Test-Path -LiteralPath $envFile)) {
    Write-Host 'Первый запуск: создаю .env со случайными паролями...'
    $lines = @(
        '# создан start.bat — не публикуйте этот файл'
        "APP_KEY=base64:$([Convert]::ToBase64String((New-Secret 32)))"
        "DB_PASSWORD=$(([BitConverter]::ToString((New-Secret 24))).Replace('-', '').ToLower())"
        "PROCESSOR_API_TOKEN=$(([BitConverter]::ToString((New-Secret 32))).Replace('-', '').ToLower())"
        'WEB_PORT=8000'
        'LLM_ENABLED=false'
        'LLM_MODEL=qwen2.5:3b'
    )
    [System.IO.File]::WriteAllLines($envFile, $lines, (New-Object System.Text.UTF8Encoding $false))
}

$settings = @{}
foreach ($line in Get-Content -LiteralPath $envFile) {
    if ($line -match '^\s*([A-Z_]+)\s*=\s*(.*)$') { $settings[$Matches[1]] = $Matches[2].Trim() }
}
$port = if ($settings['WEB_PORT']) { $settings['WEB_PORT'] } else { '8000' }
$profiles = if ($settings['LLM_ENABLED'] -eq 'true') { @('--profile', 'llm') } else { @() }

Write-Host 'Собираю и запускаю контейнеры. Первый раз это займёт 10–20 минут (скачиваются образы и библиотеки)...'
docker compose @profiles up -d --build
if ($LASTEXITCODE -ne 0) { Fail 'Не удалось запустить контейнеры — посмотрите сообщения выше.' }

$url = "http://localhost:$port"
Write-Host "Жду, пока сайт будет готов ($url)..."
$deadline = (Get-Date).AddMinutes(10)
do {
    Start-Sleep -Seconds 5
    try { $ready = (Invoke-WebRequest "$url/up" -UseBasicParsing -TimeoutSec 5).StatusCode -eq 200 } catch { $ready = $false }
} until ($ready -or (Get-Date) -gt $deadline)
if (-not $ready) { Fail 'Сайт не ответил за 10 минут. Подробности: docker compose logs web processor' }

Write-Host ''
Write-Host "Готово: $url" -ForegroundColor Green
Write-Host 'Остановить: stop.bat. Данные (база и документы) сохраняются между запусками.'
Start-Process $url
