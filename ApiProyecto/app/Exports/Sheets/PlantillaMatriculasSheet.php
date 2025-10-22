<?php

namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class PlantillaMatriculasSheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize, WithEvents
{
    /** Encabezados EXACTOS que consume el importador (con tildes): */
    public function headings(): array
    {
        return [
            'Modo contrato',
            'Modalidad estudio',
            'Ciclo',
            'Grupo',
            'Código estudiante',
            'Estudiante',
            'Documento',
            'Correo',
            'Usuario',
            'Correo Institucional',
            'Celular',
            'Pais',
            'Religión',
            'Fecha de nacimiento',
            'Fecha de matrícula',
        ];
    }

    /** 1 fila de ejemplo para guiar al usuario. */
    public function array(): array
    {
        return [[
            'Regular',          // Modo contrato
            'Presencial',       // Modalidad estudio
            '1',                // Ciclo
            '1',                // Grupo
            '202411234',        // Código estudiante
            'APELLIDOS NOMBRES',
            '12345678',         // Documento (DNI/CE/Pasaporte)
            'correo@dominio.com',
            'usuario.sugerido', // Usuario (si se omite, se genera)
            '12345678@upeu.edu.pe',
            '999999999',        // Celular (solo números)
            'Perú',             // País (nombre o ISO2)
            'Adventista del Séptimo Día',
            '01/01/2006',       // Fecha de nacimiento (dd/mm/yyyy)
            '',                 // Fecha de matrícula: DEJAR VACÍO si NO se matriculó
        ]];
    }

    public function title(): string
    {
        return 'Plantilla';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Congelar encabezado
                $sheet->freezePane('A2');

                // Estilo de encabezado
                $sheet->getStyle('A1:O1')->getFont()->setBold(true);
                $sheet->getStyle('A1:O1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // Formato de fechas (N = 14, O = 15)
                $sheet->getStyle('N:N')->getNumberFormat()->setFormatCode('dd/mm/yyyy'); // nacimiento
                $sheet->getStyle('O:O')->getNumberFormat()->setFormatCode('dd/mm/yyyy'); // matrícula

                // Validaciones por lista (aplican a muchas filas)
                $max = 10000; // rango razonable

                // A: Modo contrato -> Catálogos!A2:A5
                for ($row = 2; $row <= $max; $row++) {
                    $this->applyListValidation($sheet, "A{$row}", "'Catálogos'!\$A\$2:\$A\$5", 'Selecciona un valor de la lista.');
                }

                // B: Modalidad estudio -> Catálogos!B2:B4
                for ($row = 2; $row <= $max; $row++) {
                    $this->applyListValidation($sheet, "B{$row}", "'Catálogos'!\$B\$2:\$B\$4", 'Selecciona un valor de la lista.');
                }

                // L: País -> Catálogos!C2:C200 (nombres) o D2:D200 (ISO2) – aceptamos ambos.
                // Dejamos validación de nombres para ayudar; también aceptará ISO2 aunque no esté en la lista.
                for ($row = 2; $row <= $max; $row++) {
                    $this->applyListValidation($sheet, "L{$row}", "'Catálogos'!\$C\$2:\$C\$200", 'Usa un país de la lista o su código ISO2.');
                }

                // Validación de fecha: permitir vacío en "Fecha de matrícula" (O)
                for ($row = 2; $row <= $max; $row++) {
                    $this->applyDateValidation($sheet, "N{$row}", false); // nacimiento: no obligatorio
                    $this->applyDateValidation($sheet, "O{$row}", false); // matrícula: puede estar vacío
                }

                // Comentarios útiles en encabezados
                $sheet->getComment('O1')->getText()->createTextRun(
                    'Dejar en blanco si el estudiante NO se matriculó este período.'
                );
                $sheet->getComment('L1')->getText()->createTextRun(
                    "Puedes escribir el nombre (ej: Perú) o el ISO2 (ej: PE)."
                );
            },
        ];
    }

    private function applyListValidation($sheet, string $cell, string $formulaRange, string $prompt = ''): void
    {
        $validation = $sheet->getCell($cell)->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowDropDown(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        if ($prompt !== '') {
            $validation->setPromptTitle('Ayuda');
            $validation->setPrompt($prompt);
        }
        $validation->setErrorTitle('Valor inválido');
        $validation->setError('El valor no está en la lista permitida.');
        $validation->setFormula1($formulaRange);
    }

    private function applyDateValidation($sheet, string $cell, bool $required = false): void
    {
        $validation = $sheet->getCell($cell)->getDataValidation();
        $validation->setType(DataValidation::TYPE_DATE);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setShowErrorMessage(true);
        $validation->setAllowBlank(!$required);
        $validation->setOperator(DataValidation::OPERATOR_BETWEEN);
        // Rango razonable de fechas
        $validation->setFormula1('DATE(1900,1,1)');
        $validation->setFormula2('DATE(2100,12,31)');
        $validation->setErrorTitle('Fecha inválida');
        $validation->setError('Ingresa una fecha válida (dd/mm/yyyy).');
    }
}
