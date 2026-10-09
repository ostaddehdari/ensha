<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class SimpleXlsxExporter
{
    public function create(string $title,array $headers,iterable $rows): array
    {
        if(!class_exists(ZipArchive::class)) throw new RuntimeException('افزونه PHP Zip برای ساخت XLSX فعال نیست.');
        $directory=storage_path('app/private/report-exports');
        if(!is_dir($directory) && !mkdir($directory,0750,true) && !is_dir($directory)) throw new RuntimeException('ساخت پوشه خروجی ناموفق بود.');
        $path=$directory.'/'.Str::uuid().'.xlsx';
        $zip=new ZipArchive();
        if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('ساخت فایل XLSX ناموفق بود.');
        $allRows=[]; $allRows[]=$headers;
        foreach($rows as $row) $allRows[]=array_values((array)$row);
        $sheet=$this->sheetXml($allRows);
        $zip->addFromString('[Content_Types].xml',$this->contentTypes());
        $zip->addFromString('_rels/.rels',$this->rootRels());
        $zip->addFromString('docProps/core.xml',$this->coreXml($title));
        $zip->addFromString('docProps/app.xml',$this->appXml());
        $zip->addFromString('xl/workbook.xml',$this->workbookXml($title));
        $zip->addFromString('xl/_rels/workbook.xml.rels',$this->workbookRels());
        $zip->addFromString('xl/styles.xml',$this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml',$sheet);
        $zip->close();
        chmod($path,0640);
        return ['path'=>$path,'sha256'=>hash_file('sha256',$path),'rows'=>max(0,count($allRows)-1)];
    }

    private function sheetXml(array $rows): string
    {
        $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews><sheetFormatPr defaultRowHeight="20"/><sheetData>';
        foreach($rows as $r=>$row){ $rn=$r+1; $xml.='<row r="'.$rn.'">'; foreach($row as $c=>$value){ $ref=$this->column($c+1).$rn; if($r>0&&is_numeric($value)&&$value!==''&&$value!==null){ $xml.='<c r="'.$ref.'" s="2"><v>'.(0+$value).'</v></c>'; } else { $safe=$this->safeString($value); $xml.='<c r="'.$ref.'" t="inlineStr" s="'.($r===0?'1':'0').'"><is><t xml:space="preserve">'.$this->escape($safe).'</t></is></c>'; }} $xml.='</row>'; }
        return $xml.'</sheetData><autoFilter ref="A1:'.$this->column(max(1,count($rows[0]??[]))).max(1,count($rows)).'"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
    }

    private function safeString(mixed $value): string
    {
        $v=(string)($value??'');
        $v=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$v)??'';
        return preg_match('/^[=+\-@]/u',$v)?"'".$v:$v;
    }
    private function escape(string $v): string { return htmlspecialchars($v,ENT_XML1|ENT_QUOTES,'UTF-8'); }
    private function column(int $n): string { $s=''; while($n>0){$n--; $s=chr(65+($n%26)).$s; $n=(int)($n/26);} return $s; }
    private function contentTypes(): string { return '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>'; }
    private function rootRels(): string { return '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>'; }
    private function workbookXml(string $title): string { return '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets><sheet name="'.$this->escape(mb_substr($title,0,31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>'; }
    private function workbookRels(): string { return '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>'; }
    private function stylesXml(): string { return '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1"><alignment horizontal="center"/></xf><xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>'; }
    private function coreXml(string $title): string { return '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>'.$this->escape($title).'</dc:title><dc:creator>Ensha</dc:creator></cp:coreProperties>'; }
    private function appXml(): string { return '<?xml version="1.0" encoding="UTF-8"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Ensha</Application></Properties>'; }
}
