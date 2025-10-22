<?php

namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CatalogosSheet implements FromArray, WithTitle, ShouldAutoSize, WithEvents
{
    public function title(): string
    {
        return 'Catálogos';
    }

    public function array(): array
    {
        // Columnas:
        // A: Modo contrato
        // B: Modalidad estudio
        // C: País (nombres comunes)
        // D: País ISO2 correspondientes (opcional, informativo)

        return [
            ['modo_contrato', 'modalidad_estudio', 'pais_nombre', 'pais_iso2'],
            ['Regular',       'Presencial',        'Perú',        'PE'],
            ['Convenio',      'Semipresencial',    'Chile',       'CL'],
            ['Beca',          'Virtual',           'Argentina',   'AR'],
            ['Otro',          '',                  'México',      'MX'],
            ['',              '',                  'Colombia',    'CO'],
            ['',              '',                  'Ecuador',     'EC'],
            ['',              '',                  'Bolivia',     'BO'],
            ['',              '',                  'Brasil',      'BR'],
            ['',              '',                  'España',      'ES'],
            ['',              '',                  'Estados Unidos', 'US'],
            ['',              '',                  'Reino Unido', 'GB'],
            ['',              '',                  'Uruguay',     'UY'],
            ['',              '',                  'Paraguay',    'PY'],
            ['',              '',                  'Venezuela',   'VE'],
            ['',              '',                  'Panamá',      'PA'],
            ['',              '',                  'Costa Rica',  'CR'],
            ['',              '',                  'Honduras',    'HN'],
            ['',              '',                  'Guatemala',   'GT'],
            ['',              '',                  'Nicaragua',   'NI'],
            ['',              '',                  'El Salvador', 'SV'],
            ['',              '',                  'República Dominicana', 'DO'],
            ['',              '',                  'Puerto Rico', 'PR'],
            ['',              '',                  'Canadá',      'CA'],
            ['',              '',                  'Alemania',    'DE'],
            ['',              '',                  'Francia',     'FR'],
            ['',              '',                  'Italia',      'IT'],
            ['',              '',                  'Portugal',    'PT'],
            ['',              '',                  'China',       'CN'],
            ['',              '',                  'Japón',       'JP'],
            // amplía si lo necesitas…
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                /** @var Worksheet $ws */
                $ws = $event->sheet->getDelegate();

                // Encabezado en negrita
                $ws->getStyle('A1:D1')->getFont()->setBold(true);

                // Ocultar esta hoja (la usaremos para listas)
                $ws->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
            },
        ];
    }
}
