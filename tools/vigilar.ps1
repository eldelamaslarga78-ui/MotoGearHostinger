#Requires -Version 5.1
<#
.SYNOPSIS
    Vigila el repositorio y sube al FTP de Hostinger cada archivo que cambie.

.DESCRIPTION
    Cada segundo toma una instantanea (fecha de modificacion + tamano) de los
    archivos web del repo. Cuando un archivo lleva estable el intervalo indicado
    y difiere de lo ya subido, lo envia al FTP invocando tools/subir.ps1.
    Sin FileSystemWatcher ni hilos: robusto dentro de una sola sesion.

.PARAMETER IntervaloSegundos
    Segundos que debe permanecer estable un archivo antes de subirse (def. 3).

.PARAMETER Log
    Ruta del registro. Por defecto %TEMP%\motogear-subida.log.
#>
[CmdletBinding()]
param(
    [int]$IntervaloSegundos = 3,
    [string]$Log = (Join-Path $env:TEMP 'motogear-subida.log')
)

$ErrorActionPreference = 'Continue'

$raiz = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$subir = Join-Path $PSScriptRoot 'subir.ps1'

$carpetasIgnoradas = @('.git', '.vscode', 'tools', 'docs', 'sql', 'node_modules')
$extWeb = @('.html', '.htm', '.css', '.js', '.mjs', '.php', '.json', '.svg',
    '.txt', '.xml', '.md', '.webmanifest', '.map', '.csv', '.png', '.jpg',
    '.jpeg', '.webp', '.gif', '.ico', '.avif', '.woff', '.woff2', '.ttf',
    '.otf', '.pdf')

function Es-Web([string]$rel) {
    $nombre = ($rel -split '/')[-1]
    if ($nombre -eq '.htaccess') { return $true }
    if ($nombre.StartsWith('.')) { return $false }
    $ext = [System.IO.Path]::GetExtension($nombre).ToLowerInvariant()
    return ($extWeb -contains $ext)
}

function Rel([System.IO.FileInfo]$f) {
    return $f.FullName.Substring($raiz.Length).TrimStart('\') -replace '\\', '/'
}

function Tomar-Instantanea {
    $mapa = @{}
    foreach ($d in Get-ChildItem -LiteralPath $raiz -Directory -Force) {
        if ($carpetasIgnoradas -contains $d.Name) { continue }
        foreach ($f in Get-ChildItem -LiteralPath $d.FullName -Recurse -File -Force) {
            $rel = Rel $f
            if (Es-Web $rel) { $mapa[$rel] = '{0}:{1}' -f $f.LastWriteTimeUtc.Ticks, $f.Length }
        }
    }
    foreach ($f in Get-ChildItem -LiteralPath $raiz -File -Force) {
        $rel = Rel $f
        if (Es-Web $rel) { $mapa[$rel] = '{0}:{1}' -f $f.LastWriteTimeUtc.Ticks, $f.Length }
    }
    return $mapa
}

Add-Content -LiteralPath $Log -Value ("{0} vigia iniciado sobre {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $raiz) -Encoding UTF8
Write-Host ("Vigilando {0} -> subida automatica a FTP (estabilidad {1} s)" -f $raiz, $IntervaloSegundos) -ForegroundColor Cyan
Write-Host ("Registro: {0}" -f $Log) -ForegroundColor DarkGray
Write-Host 'Pulsa Ctrl+C para detener.'

# Estado = huella ya enviada al servidor. Arranca con lo que ya hay (baseline).
$estado = Tomar-Instantanea
# pendientes = rel -> @{ huella; desde }  (aun no estables o no enviadas)
$pendientes = @{}

while ($true) {
    Start-Sleep -Seconds 1

    $actual = Tomar-Instantanea
    $ahora = [datetime]::Now
    $aSubir = @()

    foreach ($k in $actual.Keys) {
        $huella = $actual[$k]
        if ($estado.ContainsKey($k) -and $estado[$k] -eq $huella) {
            if ($pendientes.ContainsKey($k)) { $pendientes.Remove($k) }
            continue
        }
        if ($pendientes.ContainsKey($k) -and $pendientes[$k].huella -eq $huella) {
            if (($ahora - $pendientes[$k].desde).TotalSeconds -ge $IntervaloSegundos) {
                $aSubir += $k
                $pendientes.Remove($k)
                $estado[$k] = $huella
            }
        }
        else {
            $pendientes[$k] = @{ huella = $huella; desde = $ahora }
        }
    }

    foreach ($k in @($estado.Keys)) {
        if (-not $actual.ContainsKey($k)) {
            $estado.Remove($k)
            $pendientes.Remove($k)
            Add-Content -LiteralPath $Log -Value ("{0} -> borrado localmente: {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $k) -Encoding UTF8
        }
    }

    if ($aSubir.Count -gt 0) {
        $rels = $aSubir | Sort-Object
        $hora = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
        Add-Content -LiteralPath $Log -Value ("{0} -> {1}" -f $hora, ($rels -join ', ')) -Encoding UTF8
        $salida = & powershell -NoProfile -ExecutionPolicy Bypass -File $subir -Solo @($rels) 2>&1
        $texto = ($salida | Out-String)
        if ($texto) { Add-Content -LiteralPath $Log -Value $texto.TrimEnd() -Encoding UTF8 }
    }
}
