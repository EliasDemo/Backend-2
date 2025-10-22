<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class HorasPorPeriodoExport implements FromArray, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function __construct(
        protected array $meta,
        protected array $rows
    ) {}

    public function headings(): array
    {
        $ep  = $this->meta['escuela_profesional'] ?? 'EP';
        $cols = ['EP', 'CODIGO', 'AP. Y NOMBRES'];

        $din = [];
        $bucketAntes = $this->meta['bucket_antes'];
        if ($bucketAntes) {
            $din[] = $bucketAntes;
        }
        foreach ($this->meta['periodos'] as $p) {
            $din[] = $p['codigo'];
        }

        return array_merge($cols, $din, ['TOTAL']);
    }

    public function map($row): array
    {
        $out = [
            $row['ep'],
            $row['codigo'],
            $row['apellidos_nombres'],
        ];

        $bucketAntes = $this->meta['bucket_antes'];
        if ($bucketAntes) {
            $out[] = $row['buckets'][$bucketAntes] ?? 0;
        }

        foreach ($this->meta['periodos'] as $p) {
            $code = $p['codigo'];
            $out[] = $row['buckets'][$code] ?? 0;
        }

        $out[] = $row['total'];
        return $out;
    }

    public function array(): array
    {
        // FromArray requiere datos sin encabezados.
        // El mapeo ya arma cada fila.
        return $this->rows;
    }

    public function styles(Worksheet $sheet)
    {
        // Cabecera en negrita
        $sheet->getStyle('A1:Z1')->getFont()->setBold(true);
        // Opcional: formateo numérico para columnas de horas
        // (dejar como general si mezclas min/h)
        return [];
    }
}
