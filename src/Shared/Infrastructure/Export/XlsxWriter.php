<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Export;

/**
 * Un `.xlsx` mínimo: hojas con cabecera en negrita, texto y enlaces pulsables.
 *
 * **Escrito a mano y no con PhpSpreadsheet** porque un `.xlsx` es un zip y la
 * imagen de PHP no lleva la extensión `zip`: meterla obligaría a reconstruir la
 * imagen de producción por un export. Por dentro son cinco XML, y el zip se
 * arma con `gzdeflate` y `crc32`, que sí están.
 *
 * Los enlaces son hipervínculos de verdad (`<hyperlinks>` con su relación), no
 * la fórmula `HYPERLINK()`: así se pulsan igual en Excel, Numbers y Google
 * Sheets, y la celda sigue siendo la URL si se copia.
 */
final class XlsxWriter
{
    /** @var list<array{name: string, header: list<string>, rows: list<list<string|null>>, links: list<int>, widths: list<int>}> */
    private array $sheets = [];

    /**
     * @param list<string>            $header
     * @param list<list<string|null>> $rows
     * @param list<int>               $linkColumns índices (desde 0) de las columnas que son URL
     * @param list<int>               $widths      ancho de cada columna, en caracteres
     */
    public function addSheet(string $name, array $header, array $rows, array $linkColumns = [], array $widths = []): self
    {
        $this->sheets[] = [
            // Excel no admite más de 31 caracteres ni algunos signos en el nombre.
            'name'   => mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name), 0, 31),
            'header' => $header,
            'rows'   => $rows,
            'links'  => $linkColumns,
            'widths' => $widths,
        ];

        return $this;
    }

    public function save(string $path): void
    {
        $files = [
            '[Content_Types].xml'        => $this->contentTypes(),
            '_rels/.rels'                => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml'            => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRels(),
            'xl/styles.xml'              => $this->styles(),
        ];
        foreach ($this->sheets as $i => $sheet) {
            [$xml, $rels] = $this->sheet($sheet);
            $files[sprintf('xl/worksheets/sheet%d.xml', $i + 1)] = $xml;
            if ($rels !== null) {
                $files[sprintf('xl/worksheets/_rels/sheet%d.xml.rels', $i + 1)] = $rels;
            }
        }

        if (file_put_contents($path, $this->zip($files)) === false) {
            throw new \RuntimeException(sprintf('No se pudo escribir %s', $path));
        }
    }

    private function contentTypes(): string
    {
        $overrides = '';
        foreach (array_keys($this->sheets) as $i) {
            $overrides .= sprintf(
                '<Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
                $i + 1,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $sheet) {
            $sheets .= sprintf('<sheet name="%s" sheetId="%d" r:id="rId%d"/>', $this->esc($sheet['name']), $i + 1, $i + 1);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets></workbook>';
    }

    private function workbookRels(): string
    {
        $rels = '';
        foreach (array_keys($this->sheets) as $i) {
            $rels .= sprintf(
                '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>',
                $i + 1,
                $i + 1,
            );
        }
        $rels .= sprintf(
            '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>',
            count($this->sheets) + 1,
        );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    /** Estilos: 0 normal, 1 cabecera en negrita, 2 enlace (azul y subrayado). */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><u/><sz val="11"/><color rgb="FF0563C1"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }

    /**
     * @param array{name: string, header: list<string>, rows: list<list<string|null>>, links: list<int>, widths: list<int>} $sheet
     *
     * @return array{0: string, 1: ?string} la hoja y, si tiene enlaces, sus relaciones
     */
    private function sheet(array $sheet): array
    {
        $rowsXml   = $this->row(1, $sheet['header'], 1);
        $hyperlink = '';
        $rels      = '';
        $relId     = 0;

        foreach ($sheet['rows'] as $r => $values) {
            $rowNumber = $r + 2;
            $rowsXml  .= $this->row($rowNumber, $values, 0, $sheet['links']);

            foreach ($sheet['links'] as $column) {
                $url = $values[$column] ?? null;
                if ($url === null || $url === '' || !preg_match('~^https?://~', $url)) {
                    continue;
                }
                $relId++;
                $hyperlink .= sprintf('<hyperlink ref="%s%d" r:id="rId%d"/>', $this->column($column), $rowNumber, $relId);
                $rels      .= sprintf(
                    '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="%s" TargetMode="External"/>',
                    $relId,
                    $this->esc($url),
                );
            }
        }

        $cols = '';
        foreach ($sheet['widths'] as $i => $width) {
            $cols .= sprintf('<col min="%d" max="%d" width="%d" customWidth="1"/>', $i + 1, $i + 1, $width);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            // La cabecera se queda fija al bajar.
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . $rowsXml . '</sheetData>'
            . sprintf('<autoFilter ref="A1:%s%d"/>', $this->column(max(0, count($sheet['header']) - 1)), count($sheet['rows']) + 1)
            . ($hyperlink !== '' ? '<hyperlinks>' . $hyperlink . '</hyperlinks>' : '')
            . '</worksheet>';

        $relsXml = $relId === 0 ? null
            : '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
              . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';

        return [$xml, $relsXml];
    }

    /**
     * @param list<string|null> $values
     * @param list<int>         $links
     */
    private function row(int $number, array $values, int $style, array $links = []): string
    {
        $cells = '';
        foreach ($values as $i => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $cellStyle = $style ?: (in_array($i, $links, true) ? 2 : 0);
            $cells    .= sprintf(
                '<c r="%s%d" t="inlineStr"%s><is><t xml:space="preserve">%s</t></is></c>',
                $this->column($i),
                $number,
                $cellStyle ? sprintf(' s="%d"', $cellStyle) : '',
                $this->esc($value),
            );
        }

        return sprintf('<row r="%d">%s</row>', $number, $cells);
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    private function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }

        return $name;
    }

    private function esc(string $value): string
    {
        // Fuera los caracteres de control que XML no admite: vienen a veces en
        // textos pegados y dejan el fichero sin abrir.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }

    /**
     * Un zip con deflate, sin la extensión `zip`.
     *
     * @param array<string, string> $files
     */
    private function zip(array $files): string
    {
        $data    = '';
        $central = '';
        $time    = 0;
        $date    = (1 << 5) | 1; // 1980-01-01: la fecha no le importa a nadie aquí.

        foreach ($files as $name => $content) {
            $deflated = (string) gzdeflate($content, 6);
            $crc      = crc32($content);
            $offset   = strlen($data);

            $header = pack('VvvvvvVVVvv', 0x04034B50, 20, 0x0800, 8, $time, $date, $crc, strlen($deflated), strlen($content), strlen($name), 0);
            $data  .= $header . $name . $deflated;

            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0x0800, 8, $time, $date, $crc, strlen($deflated), strlen($content), strlen($name), 0, 0, 0, 0, 0, $offset)
                . $name;
        }

        return $data . $central
            . pack('VvvvvVVv', 0x06054B50, 0, 0, count($files), count($files), strlen($central), strlen($data), 0);
    }
}
