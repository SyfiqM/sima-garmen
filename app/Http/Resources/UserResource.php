<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Support\Menu;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Ambil nilai string dari Enum jika $this->role adalah Enum,
        // atau gunakan langsung jika sudah berupa string.
        $roleValue = $this->role instanceof \UnitEnum ? $this->role->value : $this->role;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'role' => $roleValue,
            'tahap_id' => $this->tahap_id,
            'no_hp' => $this->no_hp,
            'is_active' => (bool) $this->is_active,
            'menu' => Menu::getForRole($roleValue),
        ];
    }
}