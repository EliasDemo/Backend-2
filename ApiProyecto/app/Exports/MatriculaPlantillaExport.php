<?php

namespace App\Exports;

use App\Exports\Sheets\CatalogosSheet;
use App\Exports\Sheets\InstruccionesSheet;
use App\Exports\Sheets\PlantillaMatriculasSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class MatriculaPlantillaExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            new PlantillaMatriculasSheet(),
            new InstruccionesSheet(),
            new CatalogosSheet(), // hoja que se oculta y alimenta validaciones
        ];
    }
}
