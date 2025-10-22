<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imagenes', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Alcance polimórfico (Proyecto/Evento/u otro)
            $table->unsignedBigInteger('imageable_id');
            $table->string('imageable_type');

            // Ubicación del archivo
            $table->string('disk');                 // p.ej. public, s3, local
            $table->string('path', 1024);           // ruta dentro del disk
            $table->string('url', 2048)->nullable();// URL absoluta opcional (CDN, S3 firmado, etc.)

            // Metadatos
            $table->string('titulo')->nullable();
            $table->enum('visibilidad', ['PUBLICA', 'PRIVADA', 'RESTRINGIDA'])->default('PRIVADA');

            // Autor (quien sube)
            $table->unsignedBigInteger('subido_por');
            $table->foreign('subido_por')
                ->references('id')->on('users')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            // Índices
            $table->index(['imageable_type', 'imageable_id']);
            $table->index('disk');
            $table->index('visibilidad');
            $table->unique(['disk', 'path']); // evita duplicar el mismo archivo en el mismo disk

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imagenes');
    }
};
