<#
.SYNOPSIS
    Genera deploy/ con el backend listo para subir a Hostinger (estructura de docs/deployment.md).

.DESCRIPTION
    Crea, en la raiz del repositorio:

      deploy/
      |-- siiis-api/                 -> domains/<dominio>/siiis-api/ (FUERA de public_html)
      |   |-- bootstrap.php, composer.json, composer.lock, .htaccess (deniega todo)
      |   |-- src/, cron/, scripts/ (solo los de produccion)
      |   |-- vendor/                (composer install --no-dev --optimize-autoloader)
      |   |-- storage/logs, storage/ratelimit, storage/cache (vacias)
      |   `-- .env.produccion.ejemplo (copiar como .env y completar los secretos)
      `-- public_html/
          |-- index.html, assets/... (build de React: contenido de frontend/dist)
          |-- .htaccess              (/api -> PHP; resto -> SPA de React; el de frontend/dist si existe)
          `-- api/                   (nunca se pisa con el frontend)
              |-- index.php          (BOOTSTRAP_PATH -> ../../siiis-api/bootstrap.php)
              `-- .htaccess

    No copia .env, tests/, vendor de desarrollo, herramientas de calidad ni scripts de desarrollo
    (seed_demo.php, scripts/demo/, este script). Borra y vuelve a crear deploy/ en cada ejecucion.
    deploy/ esta en .gitignore.

    Uso (desde cualquier carpeta):
        powershell -ExecutionPolicy Bypass -File backend\scripts\empaquetar.ps1
        powershell -ExecutionPolicy Bypass -File backend\scripts\empaquetar.ps1 -Dominio siiis.com.co

    El archivo se mantiene en ASCII a proposito: Windows PowerShell 5.1 lee los .ps1 sin BOM como ANSI.

.PARAMETER Dominio
    Dominio del sitio; se usa en APP_URL y PASSWORD_RESET_URL del .env de ejemplo.

.PARAMETER SinFrontend
    No copia frontend/dist (solo el backend). Sin este parametro, frontend/dist/index.html debe existir y ser
    un build de produccion (npm run build); si no, el script se detiene.

.PARAMETER Zip
    Ademas crea deploy\siiis-api.zip y deploy\public_html.zip para subirlos y extraerlos en el
    Administrador de archivos de hPanel. Usa tar.exe de Windows (guarda las rutas con "/" y las carpetas
    vacias de storage/, a diferencia de Compress-Archive de PowerShell 5.1).
#>
[CmdletBinding()]
param(
    [string]$Dominio = 'siiis.com.co',
    [switch]$Zip,
    [switch]$SinFrontend
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$Backend = Split-Path -Parent $PSScriptRoot
$Raiz = Split-Path -Parent $Backend
$Deploy = Join-Path $Raiz 'deploy'
$Api = Join-Path $Deploy 'siiis-api'
$PublicHtml = Join-Path $Deploy 'public_html'
$Utf8SinBom = New-Object System.Text.UTF8Encoding($false)

function Write-Paso([string]$Texto) {
    Write-Host "==> $Texto" -ForegroundColor Cyan
}

function Stop-Empaquetado([string]$Texto) {
    Write-Host "ERROR: $Texto" -ForegroundColor Red
    exit 1
}

# Escribe texto en UTF-8 SIN BOM y con saltos LF: un BOM antes de "<?php" enviaria bytes antes de las
# cabeceras HTTP y romperia la API.
function Write-Archivo([string]$Ruta, [string]$Texto) {
    $Texto = $Texto -replace "`r`n", "`n"
    [System.IO.File]::WriteAllText($Ruta, $Texto, $Utf8SinBom)
}

function Read-Archivo([string]$Ruta) {
    return [System.IO.File]::ReadAllText($Ruta, $Utf8SinBom)
}

# --- Requisitos ---------------------------------------------------------------------------------------
Write-Paso 'Comprobando PHP y Composer'
foreach ($comando in @('php', 'composer')) {
    if ($null -eq (Get-Command $comando -ErrorAction SilentlyContinue)) {
        Stop-Empaquetado "No se encontro '$comando' en el PATH."
    }
}
foreach ($archivo in @('bootstrap.php', 'composer.json', 'composer.lock', 'public\index.php', 'public\.htaccess', '.env.example')) {
    if (-not (Test-Path (Join-Path $Backend $archivo))) {
        Stop-Empaquetado "Falta backend\$archivo."
    }
}

# --- Carpeta limpia -----------------------------------------------------------------------------------
Write-Paso "Preparando $Deploy"
if (Test-Path $Deploy) {
    # Solo se borra la carpeta deploy de este repositorio.
    if ((Split-Path -Leaf $Deploy) -ne 'deploy' -or (Split-Path -Parent $Deploy) -ne $Raiz) {
        Stop-Empaquetado "Ruta de salida inesperada: $Deploy"
    }
    Remove-Item -LiteralPath $Deploy -Recurse -Force
}
New-Item -ItemType Directory -Path $Api, (Join-Path $PublicHtml 'api') -Force | Out-Null

# --- siiis-api: codigo ---------------------------------------------------------------------------------
Write-Paso 'Copiando codigo (src, cron, scripts de produccion)'
foreach ($archivo in @('bootstrap.php', 'composer.json', 'composer.lock')) {
    Copy-Item -LiteralPath (Join-Path $Backend $archivo) -Destination $Api
}
Copy-Item -LiteralPath (Join-Path $Backend 'src') -Destination $Api -Recurse
Copy-Item -LiteralPath (Join-Path $Backend 'cron') -Destination $Api -Recurse

# Scripts que se usan en produccion (docs/deployment.md, paso 8). Los de desarrollo no se copian.
$Scripts = Join-Path $Api 'scripts'
New-Item -ItemType Directory -Path (Join-Path $Scripts 'data') -Force | Out-Null
foreach ($script in @('cli.php', 'crear_indices.php', 'crear_superadmin.php', 'seed_multimedia.php', 'cargar_multimedia.php')) {
    Copy-Item -LiteralPath (Join-Path $Backend "scripts\$script") -Destination $Scripts
}
Copy-Item -LiteralPath (Join-Path $Backend 'scripts\data\multimedia_seed.example.json') -Destination (Join-Path $Scripts 'data')
Copy-Item -LiteralPath (Join-Path $Backend 'scripts\multimedia') -Destination $Scripts -Recurse

# Defensa extra: si alguien sube siiis-api dentro de public_html, el servidor no la entrega.
Write-Archivo (Join-Path $Api '.htaccess') @"
# siiis-api/ vive FUERA de public_html. Si por error queda accesible desde la web, no se sirve nada.
Require all denied
"@

# --- siiis-api: storage vacio --------------------------------------------------------------------------
Write-Paso 'Creando storage/ (logs, ratelimit, cache) vacio'
foreach ($carpeta in @('logs', 'ratelimit', 'cache')) {
    New-Item -ItemType Directory -Path (Join-Path $Api "storage\$carpeta") -Force | Out-Null
}

# --- siiis-api: vendor sin dependencias de desarrollo ---------------------------------------------------
# Se parte del vendor/ local (ya instalado con composer.lock): asi Composer solo quita los paquetes de
# desarrollo y regenera el autoload, sin descargar nada. Evita depender de la extension zip o de unzip/7z,
# que suelen faltar en Windows. Si un paquete falta o cambio de version, Composer lo descarga.
$VendorLocal = Join-Path $Backend 'vendor'
if (Test-Path (Join-Path $VendorLocal 'composer\installed.json')) {
    Write-Paso 'Copiando backend/vendor como punto de partida'
    Copy-Item -LiteralPath $VendorLocal -Destination $Api -Recurse
}
Write-Paso 'composer install --no-dev --optimize-autoloader'
& composer install --working-dir="$Api" --no-dev --optimize-autoloader --no-interaction --no-progress
if ($LASTEXITCODE -ne 0) {
    Stop-Empaquetado "composer install fallo (codigo $LASTEXITCODE)."
}

# --- public_html/api ------------------------------------------------------------------------------------
Write-Paso 'Generando public_html/api/index.php y .htaccess'
$Index = Read-Archivo (Join-Path $Backend 'public\index.php')
$LineaOriginal = "const BOOTSTRAP_PATH = __DIR__ . '/../bootstrap.php';"
$LineaHostinger = "const BOOTSTRAP_PATH = __DIR__ . '/../../siiis-api/bootstrap.php';"
if (-not $Index.Contains($LineaOriginal)) {
    Stop-Empaquetado "No se encontro la linea BOOTSTRAP_PATH en backend\public\index.php."
}
Write-Archivo (Join-Path $PublicHtml 'api\index.php') ($Index.Replace($LineaOriginal, $LineaHostinger))
Copy-Item -LiteralPath (Join-Path $Backend 'public\.htaccess') -Destination (Join-Path $PublicHtml 'api')

# --- public_html: build del frontend ---------------------------------------------------------------------
# Se copia el CONTENIDO de frontend/dist (index.html, assets/, .htaccess...) junto a api/. Nunca se borra ni
# se pisa public_html/api/: si el build trajera una carpeta api/, el empaquetado se detiene.
$Dist = Join-Path $Raiz 'frontend\dist'
$HayFrontend = $false
if ($SinFrontend) {
    Write-Paso 'Sin frontend (-SinFrontend): public_html solo lleva api/ y .htaccess'
} else {
    Write-Paso 'Copiando el build del frontend (frontend/dist) a public_html/'
    if (-not (Test-Path (Join-Path $Dist 'index.html'))) {
        Stop-Empaquetado 'No existe frontend\dist\index.html. Compila antes: cd frontend; npm run build (o usa -SinFrontend).'
    }
    # El build debe ser el de produccion: sin la URL local de la API ni la ruta que Git Bash inventa con
    # VITE_API_URL=/api/v1 (D:/Program Files/Git/api/v1).
    foreach ($js in Get-ChildItem -LiteralPath (Join-Path $Dist 'assets') -Filter '*.js' -File) {
        $contenido = Read-Archivo $js.FullName
        if ($contenido.Contains('localhost:8000/api')) {
            Stop-Empaquetado "frontend\dist es un build de desarrollo ($($js.Name) apunta a localhost:8000). Compila con: npm run build"
        }
        if ($contenido -match 'Program Files/Git/api') {
            Stop-Empaquetado 'frontend\dist se compilo desde Git Bash con VITE_API_URL=/api/v1 y la URL quedo mal. Compila desde PowerShell o con npm run build sin VITE_API_URL.'
        }
    }
    foreach ($item in Get-ChildItem -LiteralPath $Dist -Force) {
        if ($item.Name -eq 'api') {
            Stop-Empaquetado 'frontend\dist trae una carpeta api/: pisaria public_html/api/ del backend.'
        }
        Copy-Item -LiteralPath $item.FullName -Destination $PublicHtml -Recurse
    }
    $HayFrontend = $true
}

# .htaccess de la raiz del sitio (docs/deployment.md): /api va a PHP y el resto a la SPA de React. Si el build
# del frontend ya trae uno (frontend/public/.htaccess), se usa ese.
if (Test-Path (Join-Path $PublicHtml '.htaccess')) {
    Write-Paso 'public_html/.htaccess: se usa el del build del frontend'
} else {
    Write-Archivo (Join-Path $PublicHtml '.htaccess') @"
# Raiz del sitio: /api/ la atiende public_html/api/; los archivos que existen se sirven tal cual y
# cualquier otra ruta (p. ej. /restablecer) va a index.html, el build de React.
RewriteEngine On
RewriteRule ^api/ - [L]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^ index.html [L]
"@
}

# --- .env de produccion de ejemplo ---------------------------------------------------------------------
Write-Paso 'Generando siiis-api/.env.produccion.ejemplo desde backend/.env.example'
$Fijos = [ordered]@{
    'APP_ENV'            = 'production'
    'APP_DEBUG'          = 'false'
    'APP_URL'            = "https://$Dominio"
    'FRONTEND_ORIGIN'    = "https://$Dominio"
    'CORS_ORIGIN'        = ''
    'MONGO_DB_NAME'      = 'siiis'
    'PASSWORD_RESET_URL' = "https://$Dominio/restablecer"
}
# Secretos: siempre vacios en el ejemplo, aunque .env.example llegara a tener algun valor.
$Secretos = @(
    'MONGO_URI', 'JWT_SECRET', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS',
    'CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET', 'CONTACTO_CORREO_DESTINO',
    'INSCRIPCION_CORREO_AVISO'
)
$Encabezado = @(
    '# .env de PRODUCCION (Hostinger). Copialo como siiis-api/.env (fuera de public_html) y completa los valores',
    '# vacios. Nunca lo subas a Git ni lo dejes dentro de public_html. Ver docs/checklist-manana.md.',
    '# Secretos obligatorios: MONGO_URI (usuario de la app, contrasena fuerte) y JWT_SECRET NUEVO de 32+ caracteres,',
    '# p. ej.: php -r "echo bin2hex(random_bytes(32));"',
    '# Correo y Cloudinary: por ahora las credenciales de desarrollo (DEC-B55).'
)
$Salida = New-Object System.Collections.Generic.List[string]
$Salida.AddRange([string[]]$Encabezado)
$Vistas = @{}
$EnEncabezado = $true
foreach ($linea in (Read-Archivo (Join-Path $Backend '.env.example')) -split "`r?`n") {
    # Se reemplazan los comentarios iniciales del .env.example (hablan del entorno de desarrollo).
    if ($EnEncabezado -and $linea.StartsWith('#')) { continue }
    $EnEncabezado = $false
    if ($linea -match 'TODO_SPEC') { continue }
    if ($linea -match '^([A-Z0-9_]+)=') {
        $clave = $Matches[1]
        $Vistas[$clave] = $true
        if ($Fijos.Contains($clave)) {
            $linea = "$clave=$($Fijos[$clave])"
        } elseif ($Secretos -contains $clave) {
            $linea = "$clave="
        }
    }
    $Salida.Add($linea)
}
foreach ($clave in @($Fijos.Keys) + $Secretos) {
    if (-not $Vistas.ContainsKey($clave)) {
        Stop-Empaquetado "La variable $clave no esta en backend\.env.example."
    }
}
Write-Archivo (Join-Path $Api '.env.produccion.ejemplo') (($Salida -join "`n").TrimEnd() + "`n")

# --- Verificaciones -------------------------------------------------------------------------------------
Write-Paso 'Verificando el paquete'
$Problemas = New-Object System.Collections.Generic.List[string]
if (Test-Path (Join-Path $Api '.env')) { $Problemas.Add('siiis-api/.env no debe existir.') }
if (Test-Path (Join-Path $Api 'tests')) { $Problemas.Add('siiis-api/tests no debe existir.') }
foreach ($dev in @('phpunit', 'phpstan', 'friendsofphp')) {
    if (Test-Path (Join-Path $Api "vendor\$dev")) { $Problemas.Add("vendor/$dev es de desarrollo.") }
}
foreach ($dev in @('seed_demo.php', 'demo', 'empaquetar.ps1')) {
    if (Test-Path (Join-Path $Scripts $dev)) { $Problemas.Add("scripts/$dev es de desarrollo.") }
}
$Ejemplo = Read-Archivo (Join-Path $Api '.env.produccion.ejemplo')
foreach ($clave in $Secretos) {
    if ($Ejemplo -match "(?m)^$clave=.+$") { $Problemas.Add("$clave tiene valor en .env.produccion.ejemplo.") }
}
if ($Ejemplo -notmatch '(?m)^APP_ENV=production$' -or $Ejemplo -notmatch '(?m)^APP_DEBUG=false$') {
    $Problemas.Add('APP_ENV/APP_DEBUG no quedaron en production/false.')
}
$IndexApi = Join-Path $PublicHtml 'api\index.php'
$bytes = [System.IO.File]::ReadAllBytes($IndexApi)
if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
    $Problemas.Add('public_html/api/index.php tiene BOM.')
}
& php -l $IndexApi | Out-Null
if ($LASTEXITCODE -ne 0) { $Problemas.Add('public_html/api/index.php tiene errores de sintaxis.') }
# El autoload de produccion carga el codigo y la ruta de BOOTSTRAP_PATH apunta a un archivo real.
$Bootstrap = [System.IO.Path]::GetFullPath((Join-Path $PublicHtml 'api\..\..\siiis-api\bootstrap.php'))
if (-not (Test-Path $Bootstrap)) { $Problemas.Add("BOOTSTRAP_PATH no resuelve a un archivo: $Bootstrap") }
$Autoload = (Join-Path $Api 'vendor\autoload.php') -replace '\\', '/'
& php -r "require '$Autoload'; exit(class_exists('Siiis\Config\Application') && !class_exists('PHPUnit\Framework\TestCase') ? 0 : 1);"
if ($LASTEXITCODE -ne 0) { $Problemas.Add('El autoload de produccion no carga Siiis\Config\Application o incluye PHPUnit.') }

if (-not (Test-Path (Join-Path $Scripts 'cargar_multimedia.php')) -or -not (Test-Path (Join-Path $Scripts 'multimedia\PlanMultimedia.php'))) {
    $Problemas.Add('Falta scripts/cargar_multimedia.php o scripts/multimedia/.')
}
# El frontend no piso la API.
if ((Read-Archivo (Join-Path $PublicHtml 'api\index.php')) -notmatch [regex]::Escape($LineaHostinger)) {
    $Problemas.Add('public_html/api/index.php no es el del backend.')
}
if ($HayFrontend -and -not (Test-Path (Join-Path $PublicHtml 'index.html'))) { $Problemas.Add('Falta public_html/index.html del frontend.') }

if ($Problemas.Count -gt 0) {
    foreach ($p in $Problemas) { Write-Host "  - $p" -ForegroundColor Red }
    Stop-Empaquetado 'El paquete no paso las verificaciones.'
}

if ($Zip) {
    Write-Paso 'Creando siiis-api.zip y public_html.zip'
    if ($null -eq (Get-Command 'tar.exe' -ErrorAction SilentlyContinue)) {
        Stop-Empaquetado 'No se encontro tar.exe (viene con Windows 10/11). Sube las carpetas sin comprimir.'
    }
    # siiis-api.zip contiene la carpeta siiis-api/; public_html.zip, el CONTENIDO de public_html (frontend,
    # .htaccess y api/), para extraerlo directamente dentro de public_html.
    & tar.exe -a -c -f (Join-Path $Deploy 'siiis-api.zip') -C $Deploy 'siiis-api'
    if ($LASTEXITCODE -ne 0) { Stop-Empaquetado 'No se pudo crear siiis-api.zip.' }
    $Contenido = @(Get-ChildItem -LiteralPath $PublicHtml -Force | ForEach-Object { $_.Name })
    & tar.exe -a -c -f (Join-Path $Deploy 'public_html.zip') -C $PublicHtml @Contenido
    if ($LASTEXITCODE -ne 0) { Stop-Empaquetado 'No se pudo crear public_html.zip.' }
}

$Tamano = (Get-ChildItem -LiteralPath $Deploy -Recurse -File | Measure-Object -Property Length -Sum).Sum
Write-Host ''
Write-Host ("Listo: {0} ({1:N1} MB)" -f $Deploy, ($Tamano / 1MB)) -ForegroundColor Green
Write-Host '  1. Sube deploy\siiis-api\ a domains/<dominio>/siiis-api/ (fuera de public_html).'
Write-Host '  2. Sube el contenido de deploy\public_html\ a public_html/ (sin borrar lo que ya haya).'
Write-Host '  3. En el servidor, copia .env.produccion.ejemplo como .env y completa los secretos.'
Write-Host '  Pasos completos: docs\checklist-manana.md'
