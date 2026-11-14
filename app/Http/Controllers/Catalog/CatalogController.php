<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

abstract class CatalogController extends Controller
{
    abstract protected function modelClass(): string;

    abstract protected function title(): string;

    abstract protected function fields(): array;

    abstract protected function columns(): array;

    abstract protected function rules(?Model $item = null): array;

    protected function lookups(): array
    {
        return [];
    }

    protected function with(): array
    {
        return [];
    }

    protected function prepare(array $data, ?Model $item = null): array
    {
        return $data;
    }

    protected function normalize(Request $request): void {}

    protected function check(array $data, ?Model $item, Validator $validator): void {}

    protected function checkStatus(Model $item, bool $active): void {}

    protected function query()
    {
        return ($this->modelClass())::query()->with($this->with());
    }

    public function index(Request $request)
    {
        $resource = explode('.', $request->route()->getName())[1];

        return view('catalog.index', [
            'title' => $this->title(), 'fields' => $this->fields(), 'columns' => $this->columns(),
            'lookups' => $this->lookups(), 'baseUrl' => $request->url(),
            'canCreate' => $request->user()->can($resource.'.create'),
            'canUpdate' => $request->user()->can($resource.'.update'),
            'canChangeStatus' => $request->user()->can($resource.'.status'),
        ]);
    }

    public function data(): JsonResponse
    {
        return response()->json(['data' => $this->query()->orderByDesc('id')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $class = $this->modelClass();
        $item = $class::create($this->prepare($data));

        return response()->json(['message' => 'Registro creado.', 'data' => $item->load($this->with())], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = DB::transaction(function () use ($request, $id) {
            $item = $this->query()->lockForUpdate()->findOrFail($id);
            $data = $this->validated($request, $item);
            $item->update($this->prepare($data, $item));

            return $item;
        }, 5);

        return response()->json(['message' => 'Registro actualizado.', 'data' => $item->load($this->with())]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $active = $request->boolean('is_active');
        $item = DB::transaction(function () use ($id, $active) {
            $item = $this->query()->lockForUpdate()->findOrFail($id);
            $this->checkStatus($item, $active);
            $item->update(['is_active' => $active]);

            return $item;
        }, 5);

        return response()->json(['message' => $active ? 'Registro activado.' : 'Registro desactivado.', 'data' => $item->load($this->with())]);
    }

    private function validated(Request $request, ?Model $item = null): array
    {
        $this->normalize($request);
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $this->rules($item));
        $validator->after(function (Validator $validator) use ($item) {
            if (! $validator->errors()->any()) {
                $this->check($validator->validated(), $item, $validator);
            }
        });

        return $validator->validate();
    }

    protected function invalid(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
