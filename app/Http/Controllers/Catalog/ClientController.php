<?php

namespace App\Http\Controllers\Catalog;

use App\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class ClientController extends CatalogController
{
    protected function modelClass(): string
    {
        return Client::class;
    }

    protected function title(): string
    {
        return 'Clientes';
    }

    protected function fields(): array
    {
        return [
            ['name', 'Nombre completo', 'text', true], ['document', 'Documento de identidad', 'text', true],
            ['email', 'Correo', 'email', false], ['phone', 'Teléfono', 'text', false], ['address', 'Dirección', 'text', false],
        ];
    }

    protected function columns(): array
    {
        return [['name', 'Nombre'], ['document', 'Documento'], ['email', 'Correo'], ['phone', 'Teléfono'], ['is_active', 'Estado']];
    }

    protected function rules(?Model $item = null): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'document' => ['required', 'string', 'max:255', Rule::unique('clients', 'document')->ignore($item?->id)],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:255'],
        ];
    }
}
