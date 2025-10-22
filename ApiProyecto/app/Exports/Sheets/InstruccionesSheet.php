<?php

namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class InstruccionesSheet implements FromArray, WithTitle, ShouldAutoSize
{
    public function title(): string
    {
        return 'Instrucciones';
    }

    public function array(): array
    {
        return [
            ['Recomendaciones para completar la plantilla'],
            [''],
            ['1) Los encabezados deben mantenerse tal cual aparecen en la hoja "Plantilla".'],
            ['2) "Fecha de matrícula":'],
            ['   - Si el estudiante NO se matriculó este período, deja la celda VACÍA.'],
            ['   - Si se matriculó, usa formato dd/mm/yyyy (ej: 07/08/2025).'],
            ['3) "Pais": puedes escribir el nombre (ej: Perú) o el código ISO2 (ej: PE).'],
            ['4) "Documento": coloca el número sin guiones ni puntos.'],
            ['5) "Usuario": si lo dejas vacío, el sistema lo autogenera (o usa el DNI si corresponde).'],
            ['6) "Correo": puede ser personal. Si falta y existe "Correo Institucional", se usará ese.'],
            ['7) "Modo contrato" y "Modalidad estudio": elige de la lista desplegable.'],
            ['8) No borres ni cambies el nombre de la hoja "Catálogos" (está oculta).'],
            ['9) Puedes dejar en blanco campos opcionales si no los conoces.'],
            [''],
            ['Navegación Rápida:'],
            ['- Plantilla: para registrar filas.'],
            ['- Catálogos: lista de valores (oculta).'],
        ];
    }
}
