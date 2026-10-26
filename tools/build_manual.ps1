# Build the user manual as Word (.docx) and PDF into docs/
#   1) PHP exports manual content (includes/manual_data.php) to JSON
#   2) Python (python-docx + pythainlp) builds the .docx with Thai line breaking
#   3) Microsoft Word updates the table of contents / page numbers and exports PDF
# Usage (from the lms folder):  powershell -ExecutionPolicy Bypass -File tools\build_manual.ps1
# Requires: PHP (XAMPP), Python 3 with python-docx and pythainlp, Microsoft Word, font TH Sarabun New
# (This file is ASCII-only on purpose: Windows PowerShell 5.1 misreads UTF-8 scripts without BOM.)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = 'C:\xampp\php\php.exe'
$python = (Get-Command python -ErrorAction SilentlyContinue | Where-Object { $_.Source -notmatch 'WindowsApps' } | Select-Object -First 1).Source
if (-not $python) { $python = "$env:LOCALAPPDATA\Programs\Python\Python314\python.exe" }

$docs = Join-Path $root 'docs'
New-Item -ItemType Directory -Force $docs | Out-Null
$json = Join-Path $env:TEMP 'vt-manual.json'
$docx = Join-Path $docs 'manual-vocational-teacher.docx'
$pdf = Join-Path $docs 'manual-vocational-teacher.pdf'

Write-Host '[1/3] Export manual content (PHP)'
$out = & $php (Join-Path $root 'tools\export_manual.php')
if ($LASTEXITCODE -ne 0) { throw 'export_manual.php failed' }
[System.IO.File]::WriteAllText($json, ($out -join "`n"), (New-Object System.Text.UTF8Encoding $false))

Write-Host '[2/3] Build .docx (Python)'
& $python (Join-Path $root 'tools\build_manual_docx.py') $json $docx
if ($LASTEXITCODE -ne 0) { throw 'build_manual_docx.py failed' }

Write-Host '[3/3] Update TOC and export PDF (Microsoft Word)'
$word = New-Object -ComObject Word.Application
$word.Visible = $false
$word.DisplayAlerts = 0
try {
    $doc = $word.Documents.Open($docx)
    foreach ($toc in $doc.TablesOfContents) { $toc.Update() }
    $doc.Fields.Update() | Out-Null
    $doc.Repaginate()
    foreach ($toc in $doc.TablesOfContents) { $toc.UpdatePageNumbers() }
    $doc.Save()
    # 17 = wdExportFormatPDF, 0 = optimize for print, 1 = bookmarks from headings
    $doc.ExportAsFixedFormat($pdf, 17, $false, 0, 0, 1, 1, 0, $true, $true, 1, $true, $true, $false)
    $pages = $doc.ComputeStatistics(2)   # 2 = wdStatisticPages
    $doc.Close($false)
} finally {
    $word.Quit()
    [System.Runtime.InteropServices.Marshal]::ReleaseComObject($word) | Out-Null
}
Remove-Item $json -ErrorAction SilentlyContinue
Write-Host ("Done: {0} pages" -f $pages)
Write-Host "  $docx"
Write-Host "  $pdf"
