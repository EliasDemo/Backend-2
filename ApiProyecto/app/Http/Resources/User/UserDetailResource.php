<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Filesystem\FilesystemAdapter;
use App\Http\Resources\User\ExpedienteDetailResource; // ✅ import necesario

class UserDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        // Roles y permisos (Spatie)
        $roles = $this->getRoleNames()->values();
        $perms = $this->getAllPermissions()->pluck('name')->values();

        // URL pública de la foto
        $photo = $this->profile_photo;
        $photoUrl = $photo && !str_starts_with($photo, 'http')
            ? (function () use ($photo) {
                $diskName = config('filesystems.default', 'public');
                /** @var FilesystemAdapter $disk */
                $disk = Storage::disk($diskName);
                if (method_exists($disk, 'url')) {
                    return $disk->url($photo);
                }
                return Storage::url($photo);
            })()
            : $photo;

        $expedientes = ExpedienteDetailResource::collection(
            $this->whenLoaded('expedientesAcademicos')
        );

        // expediente activo (si lo hay) para acceso rápido
        $expedienteActivo = $this->relationLoaded('expedientesAcademicos')
            ? $this->expedientesAcademicos->firstWhere('estado', 'ACTIVO')
            : null;

        return [
            'id'               => $this->id,
            'username'         => $this->username,
            'first_name'       => $this->first_name,
            'last_name'        => $this->last_name,
            'full_name'        => trim(($this->first_name ?? '').' '.($this->last_name ?? '')),
            'email'            => $this->email,
            'status'           => $this->status,
            'doc_tipo'         => $this->doc_tipo,
            'doc_numero'       => $this->doc_numero,
            'celular'          => $this->celular,
            'pais'             => $this->pais,
            'religion'         => $this->religion,
            'fecha_nacimiento' => $this->fecha_nacimiento ? $this->fecha_nacimiento->toDateString() : null,

            'profile_photo'     => $this->profile_photo,
            'profile_photo_url' => $photoUrl,

            'roles'         => $roles,
            'rol_principal' => $roles->first(),
            'permissions'   => $perms,
            // ✅ Mapa booleano para checks rápidos en FE: permissions_map['vm.proyecto.create'] === true
            'permissions_map' => $perms->mapWithKeys(fn ($p) => [$p => true]),

            'expediente_activo' => $expedienteActivo
                ? new ExpedienteDetailResource($expedienteActivo)
                : null,

            'expedientes' => $expedientes,
        ];
    }
}
