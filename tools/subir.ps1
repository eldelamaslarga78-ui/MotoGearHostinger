#Requires -Version 5.1
<#
.SYNOPSIS
    Sube al FTP de Hostinger los archivos del sitio que hayan cambiado.

.DESCRIPTION
    Lee las credenciales de .vscode/sftp.json (nunca las imprime) y sube por FTP
    pasivo y binario. Normaliza los fines de línea a LF y quita el BOM antes de
    enviar los archivos de texto, para que el servidor Linux reciba lo mismo que
    ve Git. Omite los archivos cuyo tamaño remoto ya coincide.

.PARAMETER Todo
    Sube todo el arbol web, exista o no en el servidor.

.PARAMETER Solo
    Rutas relativas a la raiz del repo (archivos o carpetas) que se quieren subir.

.PARAMETER Forzar
    Sube aunque el tamano remoto coincida.

.PARAMETER ConDocs
    Incluye la carpeta docs/ (por defecto se omite).

.PARAMETER Seco
    Simulacion: muestra lo que haria sin tocar el servidor.

.EXAMPLE
    .\tools\subir.ps1 -Todo

.EXAMPLE
    .\tools\subir.ps1 -Solo backoffice\js\app.js, api\src\Metricas.php

.EXAMPLE
    .\tools\subir.ps1
    # Sube solo lo que git marca como modificado o nuevo.
#>
[CmdletBinding()]
param(
    [switch]$Todo,
    [switch]$Forzar,
    [switch]$ConDocs,
    [switch]$Seco,
    [string[]]$Solo = @()
)

$ErrorActionPreference = 'Stop'

# Permite -Solo "a,b" cuando se invoca con powershell -File.
$Solo = @($Solo | ForEach-Object { $_ -split '[,;]' } | ForEach-Object { $_.Trim() } | Where-Object { $_ })

# ---------------------------------------------------------------- configuracion

$raiz = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$cfgRuta = Join-Path $raiz '.vscode\sftp.json'
if (-not (Test-Path -LiteralPath $cfgRuta)) {
    throw "No existe .vscode/sftp.json; hacen falta host, usuario y contrasena."
}
$cfg = Get-Content -LiteralPath $cfgRuta -Raw | ConvertFrom-Json

$hostFtp = $cfg.host
$puerto = if ($cfg.port) { [int]$cfg.port } else { 21 }
$usuario = $cfg.username
$clave = $cfg.password
$baseRemota = if ($cfg.remotePath) { $cfg.remotePath } else { '/' }
if (-not $baseRemota.StartsWith('/')) { $baseRemota = '/' + $baseRemota }
if (-not $baseRemota.EndsWith('/')) { $baseRemota = $baseRemota + '/' }

$urlBase = 'ftp://{0}:{1}' -f $hostFtp, $puerto
$cred = New-Object System.Net.NetworkCredential($usuario, $clave)

# Extensiones que viajan como texto: se normalizan a LF y sin BOM.
$extTexto = @('.html', '.htm', '.css', '.js', '.mjs', '.php', '.json', '.svg',
    '.txt', '.xml', '.md', '.webmanifest', '.map', '.csv')

# Solo se sube lo que el sitio necesita. Todo lo demas se ignora.
$extWeb = $extTexto + @('.png', '.jpg', '.jpeg', '.webp', '.gif', '.ico', '.avif',
    '.woff', '.woff2', '.ttf', '.otf', '.pdf')

$carpetasExcluidas = @('.git/', '.vscode/', 'tools/', 'tests/', 'sql/', 'node_modules/')
$archivosExcluidos = @('prueba.js', 'api/config.local.php.ejemplo',
    'README.md', '.gitignore', '.gitattributes')
if (-not $ConDocs) { $carpetasExcluidas += 'docs/' }

# --------------------------------------------------------------------- utileria

function Es-Texto([string]$rel) {
    $nombre = ($rel -split '/')[-1]
    if ($nombre -eq '.htaccess' -or $nombre -eq '.htpasswd') { return $true }
    $ext = [System.IO.Path]::GetExtension($nombre).ToLowerInvariant()
    return ($extTexto -contains $ext)
}

function Normalizar-Texto([byte[]]$datos) {
    $ms = New-Object System.IO.MemoryStream
    $i = 0
    if ($datos.Length -ge 3 -and $datos[0] -eq 0xEF -and $datos[1] -eq 0xBB -and $datos[2] -eq 0xBF) {
        $i = 3
    }
    while ($i -lt $datos.Length) {
        $b = $datos[$i]
        if ($b -eq 13) {
            $ms.WriteByte(10)
            if ($i + 1 -lt $datos.Length -and $datos[$i + 1] -eq 10) { $i++ }
        }
        else {
            $ms.WriteByte($b)
        }
        $i++
    }
    $salida = $ms.ToArray()
    $ms.Dispose()
    return $salida
}

function Esta-Excluido([string]$rel) {
    if ($archivosExcluidos -contains $rel) { return $true }
    foreach ($c in $carpetasExcluidas) {
        if ($rel.StartsWith($c)) { return $true }
    }
    $nombre = ($rel -split '/')[-1]
    if ($nombre.StartsWith('.')) { return $true }
    $ext = [System.IO.Path]::GetExtension($nombre).ToLowerInvariant()
    if ($ext -eq '' -and $nombre -ne '.htaccess') { return $false }
    return -not ($extWeb -contains $ext)
}

function Url-Remota([string]$rel) {
    return $urlBase + $baseRemota + $rel
}

function Nuevo-Peticion([string]$rel, $metodo) {
    $req = [System.Net.FtpWebRequest]::Create((Url-Remota $rel))
    $req.Credentials = $cred
    $req.Method = $metodo
    $req.UseBinary = $true
    $req.UsePassive = $true
    $req.EnableSsl = $false
    $req.KeepAlive = $false
    $req.Timeout = 60000
    $req.ReadWriteTimeout = 120000
    $req.Proxy = $null
    return $req
}

$dirsCreados = New-Object 'System.Collections.Generic.HashSet[string]'

function Asegurar-Directorio([string]$relDir) {
    if ([string]::IsNullOrWhiteSpace($relDir)) { return }
    if ($dirsCreados.Contains($relDir)) { return }

    $partes = $relDir.Trim('/').Split('/')
    $acumulado = ''
    foreach ($p in $partes) {
        if ($p -eq '') { continue }
        $acumulado = if ($acumulado -eq '') { $p } else { "$acumulado/$p" }
        if ($dirsCreados.Contains($acumulado)) { continue }
        if ($Seco) {
            Write-Host ("  [dir] " + $baseRemota + $acumulado) -ForegroundColor DarkGray
            $null = $dirsCreados.Add($acumulado)
            continue
        }
        try {
            $req = Nuevo-Peticion $acumulado ([System.Net.WebRequestMethods+Ftp]::MakeDirectory)
            $resp = $req.GetResponse()
            $resp.Close()
            Write-Host ("  [dir] " + $baseRemota + $acumulado) -ForegroundColor DarkGray
        }
        catch {
            # 550 = ya existe, es lo normal.
        }
        $null = $dirsCreados.Add($acumulado)
    }
}

$cacheListado = @{}

function Obtener-TamanosDirectorio([string]$relDir) {
    if ($cacheListado.ContainsKey($relDir)) { return $cacheListado[$relDir] }

    $mapa = @{}
    $ruta = if ($relDir -eq '') { '' } else { $relDir + '/' }
    try {
        $req = Nuevo-Peticion $ruta ([System.Net.WebRequestMethods+Ftp]::ListDirectoryDetails)
        $resp = $req.GetResponse()
        $sr = New-Object System.IO.StreamReader($resp.GetResponseStream())
        $texto = $sr.ReadToEnd()
        $sr.Close(); $resp.Close()
        foreach ($linea in ($texto -split "`n")) {
            $linea = $linea.TrimEnd("`r")
            if (-not $linea) { continue }
            $m = [regex]::Match($linea, '^\S+\s+\d+\s+\S+\s+\S+\s+(\d+)\s+\S+\s+\S+\s+\S+\s+(.+)$')
            if ($m.Success) {
                $mapa[$m.Groups[2].Value.Trim()] = [long]$m.Groups[1].Value
            }
        }
    }
    catch {
        # Sin listado no se puede comparar: se sube.
    }
    $cacheListado[$relDir] = $mapa
    return $mapa
}

function Obtener-TamanoRemoto([string]$rel) {
    $partes = $rel.Split('/')
    $dir = if ($partes.Count -gt 1) { ($partes[0..($partes.Count - 2)]) -join '/' } else { '' }
    $nombre = $partes[$partes.Count - 1]
    $mapa = Obtener-TamanosDirectorio $dir
    if ($mapa.ContainsKey($nombre)) { return [long]$mapa[$nombre] }
    return -1
}

function Subir-Archivo([string]$rel) {
    $local = Join-Path $raiz ($rel -replace '/', '\')
    if (-not (Test-Path -LiteralPath $local)) { return 'falta' }

    $datos = [System.IO.File]::ReadAllBytes($local)
    if (Es-Texto $rel) { $datos = Normalizar-Texto $datos }

    if (-not $Forzar) {
        $remoto = Obtener-TamanoRemoto $rel
        if ($remoto -eq $datos.Length) { return 'igual' }
    }

    if ($Seco) {
        Write-Host ("  [sim] {0,-46} {1,8:N0} B" -f $rel, $datos.Length) -ForegroundColor Yellow
        return 'subido'
    }

    $dir = ($rel -split '/')
    if ($dir.Count -gt 1) {
        Asegurar-Directorio (($dir[0..($dir.Count - 2)]) -join '/')
    }

    $req = Nuevo-Peticion $rel ([System.Net.WebRequestMethods+Ftp]::UploadFile)
    $req.ContentLength = $datos.Length
    $flujo = $req.GetRequestStream()
    $flujo.Write($datos, 0, $datos.Length)
    $flujo.Close()
    $resp = $req.GetResponse()
    $resp.Close()

    Write-Host ("  [ok]  {0,-46} {1,8:N0} B" -f $rel, $datos.Length) -ForegroundColor Green
    return 'subido'
}

function Listar-Arbol {
    $resultado = @()
    foreach ($f in Get-ChildItem -LiteralPath $raiz -Recurse -File -Force) {
        $rel = $f.FullName.Substring($raiz.Length).TrimStart('\') -replace '\\', '/'
        if (Esta-Excluido $rel) { continue }
        $resultado += $rel
    }
    return $resultado
}

function Listar-Git {
    $salida = & git -C $raiz status --porcelain 2>$null
    $resultado = @()
    foreach ($linea in @($salida)) {
        if (-not $linea -or $linea.Length -lt 4) { continue }
        $rel = $linea.Substring(3).Trim()
        if ($rel -match '^"(.*)"$') { $rel = $Matches[1] }
        if ($rel -match ' -> ') { $rel = ($rel -split ' -> ')[-1] }
        $rel = $rel -replace '\\', '/'
        if (Esta-Excluido $rel) { continue }
        $completo = Join-Path $raiz ($rel -replace '/', '\')
        if (Test-Path -LiteralPath $completo -PathType Container) {
            foreach ($f in Get-ChildItem -LiteralPath $completo -Recurse -File -Force) {
                $r = $f.FullName.Substring($raiz.Length).TrimStart('\') -replace '\\', '/'
                if (-not (Esta-Excluido $r)) { $resultado += $r }
            }
        }
        elseif (Test-Path -LiteralPath $completo) {
            $resultado += $rel
        }
    }
    return ($resultado | Select-Object -Unique)
}

function Expandir-Solo([string[]]$rutas) {
    $resultado = @()
    foreach ($r in $rutas) {
        $rel = $r -replace '\\', '/'
        while ($rel.StartsWith('./')) { $rel = $rel.Substring(2) }
        $rel = $rel.TrimStart('/')
        $completo = Join-Path $raiz ($rel -replace '/', '\')
        if (-not (Test-Path -LiteralPath $completo)) {
            Write-Host "  [aviso] no existe en local: $rel" -ForegroundColor Yellow
            continue
        }
        if (Test-Path -LiteralPath $completo -PathType Container) {
            foreach ($f in Get-ChildItem -LiteralPath $completo -Recurse -File -Force) {
                $x = $f.FullName.Substring($raiz.Length).TrimStart('\') -replace '\\', '/'
                if (-not (Esta-Excluido $x)) { $resultado += $x }
            }
        }
        else {
            $resultado += $rel
        }
    }
    return ($resultado | Select-Object -Unique)
}

# ------------------------------------------------------------------- ejecucion

$objetivos = @()
if ($Todo) {
    $objetivos = Listar-Arbol
}
elseif ($Solo.Count -gt 0) {
    $objetivos = Expandir-Solo $Solo
}
else {
    $objetivos = Listar-Git
}

if (-not $objetivos -or $objetivos.Count -eq 0) {
    Write-Host 'Nada que subir: el arbol esta igual que el servidor o no hay cambios en git.' -ForegroundColor Cyan
    exit 0
}

Write-Host ''
Write-Host ("MotoGear -> {0}{1}" -f $hostFtp, $baseRemota) -ForegroundColor Cyan
Write-Host ("Archivos candidatos: {0}{1}" -f $objetivos.Count, $(if ($Seco) { ' (simulacion)' } else { '' })) -ForegroundColor Cyan
Write-Host ''

$subidos = 0; $iguales = 0; $errores = 0; $faltan = 0

foreach ($rel in ($objetivos | Sort-Object)) {
    try {
        $estado = Subir-Archivo $rel
        switch ($estado) {
            'subido' { $subidos++ }
            'igual' { $iguales++ }
            'falta' { $faltan++; Write-Host ("  [x]   {0} no existe en local" -f $rel) -ForegroundColor Red }
        }
    }
    catch {
        $errores++
        $msg = $_.Exception.Message
        if ($_.Exception.InnerException) { $msg = $_.Exception.InnerException.Message }
        Write-Host ("  [x]   {0}" -f $rel) -ForegroundColor Red
        Write-Host ("        {0}" -f $msg) -ForegroundColor DarkRed
    }
}

Write-Host ''
Write-Host ("Subidos: {0}   Sin cambios: {1}   Errores: {2}" -f $subidos, $iguales, $errores) -ForegroundColor Cyan
if ($Seco) { Write-Host 'Simulacion: no se envio nada.' -ForegroundColor Yellow }
if ($errores -gt 0) { exit 1 }
exit 0
