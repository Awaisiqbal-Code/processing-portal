<?php
declare(strict_types=1);

/**
 * Pure PHP Simple XLSX Generator.
 * Zero-dependency single-file class. No Composer required.
 * Generates valid Microsoft Excel OpenXML (.xlsx) spreadsheets.
 * Uses ZipArchive if available, or pure-PHP zip packager as fallback.
 */
class SimpleXLSXGen {
    protected array $sheets = [];
    protected array $template = [];

    public function __construct() {
        $this->sheets['Sheet1'] = [];
    }

    public static function fromArray(array $rows, string $sheetName = 'Sheet1'): self {
        $xlsx = new self();
        $xlsx->sheets[$sheetName] = $rows;
        return $xlsx;
    }

    public function addSheet(array $rows, string $sheetName): self {
        $this->sheets[$sheetName] = $rows;
        return $this;
    }

    protected function escapeXml(?string $s): string {
        if ($s === null) return '';
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    protected function colLetter(int $colIdx): string {
        $colIdx++;
        $letter = '';
        while ($colIdx > 0) {
            $colIdx--;
            $letter = chr(65 + ($colIdx % 26)) . $letter;
            $colIdx = intdiv($colIdx, 26);
        }
        return $letter;
    }

    public function output(): string {
        $files = [];

        // 1. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n"
            . '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n"
            . '  <Default Extension="xml" ContentType="application/xml"/>' . "\n"
            . '  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . "\n"
            . '  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . "\n";

        $sheetIdx = 1;
        foreach ($this->sheets as $name => $rows) {
            $contentTypes .= '  <Override PartName="/xl/worksheets/sheet' . $sheetIdx . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' . "\n";
            $sheetIdx++;
        }
        $contentTypes .= '</Types>';
        $files['[Content_Types].xml'] = $contentTypes;

        // 2. _rels/.rels
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n"
            . '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' . "\n"
            . '</Relationships>';

        // 3. xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $sheetIdx = 1;
        foreach ($this->sheets as $name => $rows) {
            $wbRels .= '  <Relationship Id="rId' . $sheetIdx . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetIdx . '.xml"/>' . "\n";
            $sheetIdx++;
        }
        $wbRels .= '  <Relationship Id="rId' . $sheetIdx . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' . "\n"
            . '</Relationships>';
        $files['xl/_rels/workbook.xml.rels'] = $wbRels;

        // 4. xl/styles.xml
        $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n"
            . '  <fonts count="2">' . "\n"
            . '    <font><sz val="11"/><name val="Calibri"/></font>' . "\n"
            . '    <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>' . "\n"
            . '  </fonts>' . "\n"
            . '  <fills count="3">' . "\n"
            . '    <fill><patternFill patternType="none"/></fill>' . "\n"
            . '    <fill><patternFill patternType="gray125"/></fill>' . "\n"
            . '    <fill><patternFill patternType="solid"><fgColor rgb="FF1E293B"/></patternFill></fill>' . "\n"
            . '  </fills>' . "\n"
            . '  <borders count="1">' . "\n"
            . '    <border><left/><right/><top/><bottom/></border>' . "\n"
            . '  </borders>' . "\n"
            . '  <cellStyleXfs count="1">' . "\n"
            . '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' . "\n"
            . '  </cellStyleXfs>' . "\n"
            . '  <cellXfs count="2">' . "\n"
            . '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' . "\n"
            . '    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' . "\n"
            . '  </cellXfs>' . "\n"
            . '</styleSheet>';

        // 5. xl/workbook.xml
        $wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n"
            . '  <sheets>' . "\n";
        $sheetIdx = 1;
        foreach ($this->sheets as $name => $rows) {
            $wbXml .= '    <sheet name="' . $this->escapeXml((string)$name) . '" sheetId="' . $sheetIdx . '" r:id="rId' . $sheetIdx . '"/>' . "\n";
            $sheetIdx++;
        }
        $wbXml .= '  </sheets>' . "\n"
            . '</workbook>';
        $files['xl/workbook.xml'] = $wbXml;

        // 6. xl/worksheets/sheetN.xml
        $sheetIdx = 1;
        foreach ($this->sheets as $name => $rows) {
            $colWidths = [];
            foreach ($rows as $rIdx => $row) {
                if (!is_array($row)) continue;
                foreach (array_values($row) as $cIdx => $cell) {
                    $len = mb_strlen((string)$cell);
                    $colWidths[$cIdx] = max($colWidths[$cIdx] ?? 12, min($len + 4, 60));
                }
            }

            $sXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n"
                . '  <cols>' . "\n";
            foreach ($colWidths as $cIdx => $w) {
                $cNum = $cIdx + 1;
                $sXml .= '    <col min="' . $cNum . '" max="' . $cNum . '" width="' . $w . '" customWidth="1"/>' . "\n";
            }
            $sXml .= '  </cols>' . "\n"
                . '  <sheetData>' . "\n";

            foreach ($rows as $rIdx => $row) {
                if (!is_array($row)) continue;
                $rowNum = $rIdx + 1;
                $isHeader = ($rIdx === 0);
                $sXml .= '    <row r="' . $rowNum . '">' . "\n";
                foreach (array_values($row) as $cIdx => $cell) {
                    $ref = $this->colLetter($cIdx) . $rowNum;
                    $style = $isHeader ? '1' : '0';
                    $val = $this->escapeXml((string)$cell);
                    $sXml .= '      <c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t>' . $val . '</t></is></c>' . "\n";
                }
                $sXml .= '    </row>' . "\n";
            }

            $sXml .= '  </sheetData>' . "\n"
                . '</worksheet>';
            $files['xl/worksheets/sheet' . $sheetIdx . '.xml'] = $sXml;
            $sheetIdx++;
        }

        return $this->zipFiles($files);
    }

    protected function zipFiles(array $files): string {
        if (class_exists('ZipArchive')) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_zip_');
            $zip = new ZipArchive();
            if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                foreach ($files as $name => $content) {
                    $zip->addFromString($name, $content);
                }
                $zip->close();
                $data = file_get_contents($tmpFile);
                @unlink($tmpFile);
                return $data ?: '';
            }
        }

        // Pure-PHP Zip packager (Zero external dependencies)
        $datasec = [];
        $ctrlDir = [];
        $offset = 0;

        foreach ($files as $name => $data) {
            $name = str_replace('\\', '/', $name);
            $uncLen = strlen($data);
            $crc = crc32($data);
            $gzData = gzdeflate($data);
            if ($gzData !== false && strlen($gzData) < $uncLen) {
                $cLen = strlen($gzData);
                $meth = 8; // Deflated
                $cData = $gzData;
            } else {
                $cLen = $uncLen;
                $meth = 0; // Stored
                $cData = $data;
            }

            $dtime = dechex((int)date('Y') - 1980 << 25 | (int)date('m') << 21 | (int)date('d') << 16 | (int)date('H') << 11 | (int)date('i') << 5 | (int)date('s') >> 1);
            $hexdtime = pack('V', hexdec($dtime));

            // Local header
            $fr = "\x50\x4b\x03\x04" . "\x14\x00\x00\x00" . pack('v', $meth) . $hexdtime . pack('V', $crc) . pack('V', $cLen) . pack('V', $uncLen) . pack('v', strlen($name)) . pack('v', 0) . $name . $cData;
            $datasec[] = $fr;

            // Central directory entry
            $cdrec = "\x50\x4b\x01\x02" . "\x00\x00\x14\x00\x00\x00" . pack('v', $meth) . $hexdtime . pack('V', $crc) . pack('V', $cLen) . pack('V', $uncLen) . pack('v', strlen($name)) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('V', 32) . pack('V', $offset) . $name;
            $ctrlDir[] = $cdrec;

            $offset += strlen($fr);
        }

        $dataOut = implode('', $datasec);
        $ctrlOut = implode('', $ctrlDir);

        // End of central directory record
        $eocd = "\x50\x4b\x05\x06" . "\x00\x00\x00\x00" . pack('v', count($ctrlDir)) . pack('v', count($ctrlDir)) . pack('V', strlen($ctrlOut)) . pack('V', strlen($dataOut)) . "\x00\x00";

        return $dataOut . $ctrlOut . $eocd;
    }
}
