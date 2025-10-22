<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ep_sede_staff_historial', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('ep_sede_id');
            $t->unsignedBigInteger('user_id');
            $t->enum('role', ['COORDINADOR','ENCARGADO']);
            $t->enum('evento', ['ASSIGN','UNASSIGN','REINSTATE','DELEGATE','AUTO_END','TRANSFER']);
            $t->date('desde')->nullable();
            $t->date('hasta')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable(); // quién realizó la acción
            $t->string('motivo', 255)->nullable();
            $t->timestamps();

            $t->index(['ep_sede_id','role','evento']);
            $t->foreign('ep_sede_id')->references('id')->on('ep_sede')->cascadeOnUpdate()->restrictOnDelete();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnUpdate()->restrictOnDelete();
            $t->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ep_sede_staff_historial');
    }
};
