# ==============================================================================
# Building CAD/PDF Parser Background Service Runner
# Port: 5005, local_cad_parser.py
# ==============================================================================
$ErrorActionPreference = 'SilentlyContinue'
$dir = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not $dir) { $dir = (Get-Location).Path }
Set-Location $dir

# 1. Exit if port 5005 is already listening
$existing = Get-NetTCPConnection -LocalPort 5005 -State Listen -ErrorAction SilentlyContinue
if ($existing) {
    exit 0
}

# 2. Find pythonw.exe
$pythonwCandidates = @(
    "$env:LOCALAPPDATA\Python\pythoncore-3.14-64\pythonw.exe",
    "$env:LOCALAPPDATA\Python\pythoncore-3.13-64\pythonw.exe",
    "$env:LOCALAPPDATA\Python\pythoncore-3.12-64\pythonw.exe",
    "$env:LOCALAPPDATA\Programs\Python\Python314\pythonw.exe",
    "$env:LOCALAPPDATA\Programs\Python\Python313\pythonw.exe",
    "$env:LOCALAPPDATA\Programs\Python\Python312\pythonw.exe"
)

$targetPython = $null
foreach ($cand in $pythonwCandidates) {
    if (Test-Path $cand) {
        $targetPython = $cand
        break
    }
}

if (-not $targetPython) {
    $cmd = Get-Command pythonw.exe -ErrorAction SilentlyContinue
    if ($cmd) { $targetPython = $cmd.Source }
}

if (-not $targetPython) {
    $targetPython = "pythonw.exe"
}

# 3. Launch pythonw silently using relative path to avoid unicode quoting issues
Start-Process -FilePath $targetPython -ArgumentList 'local_cad_parser.py' -WindowStyle Hidden
