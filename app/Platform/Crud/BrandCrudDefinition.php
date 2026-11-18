<?php

namespace App\Platform\Crud;

use App\Models\Brand;
use App\Models\User;
use App\Platform\Access\Contracts\ResolvesRoles;
use App\Platform\Access\RoleKey;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BrandCrudDefinition
{
    public const ABILITY = 'use-principal-crud-generator';

    public const FIELDS = ['name', 'slug', 'status'];

    public const ACTIONS = ['create', 'edit', 'change_status'];

    public const FILTERS = ['status'];

    public function __construct(private readonly ResolvesRoles $roles) {}

    public function allows(User $user): bool
    {
        return $user->is_active
            && in_array(RoleKey::PRINCIPAL_SUPER_ADMIN, $this->roles->principalRoleKeys($user), true);
    }

    public static function defaults(): array
    {
        return [
            'model' => Brand::class,
            'fields' => self::FIELDS,
            'validation' => 'brand',
            'columns' => self::FIELDS,
            'filters' => self::FILTERS,
            'actions' => self::ACTIONS,
        ];
    }

    public function validate(array $input): array
    {
        $definition = Validator::make(['definition' => $input], [
            'definition' => ['required', 'array:model,fields,validation,columns,filters,actions'],
            'definition.model' => ['required', 'string', Rule::in([Brand::class])],
            'definition.fields' => ['required', 'array', 'list', 'size:3'],
            'definition.fields.*' => ['required', 'string', 'distinct', Rule::in(self::FIELDS)],
            'definition.validation' => ['required', 'string', Rule::in(['brand'])],
            'definition.columns' => ['required', 'array', 'list', 'min:1', 'max:3'],
            'definition.columns.*' => ['required', 'string', 'distinct', Rule::in(self::FIELDS)],
            'definition.filters' => ['present', 'array', 'list', 'max:1'],
            'definition.filters.*' => ['required', 'string', Rule::in(self::FILTERS)],
            'definition.actions' => ['present', 'array', 'list', 'max:3'],
            'definition.actions.*' => ['required', 'string', 'distinct', Rule::in(self::ACTIONS)],
        ])->validate()['definition'];

        if (! in_array('name', $definition['columns'], true)) {
            throw ValidationException::withMessages(['definition.columns' => __('principal.crud.name_required')]);
        }

        foreach (['fields', 'columns', 'filters', 'actions'] as $key) {
            $definition[$key] = array_values(array_intersect(self::defaults()[$key], $definition[$key]));
        }

        return array_replace(self::defaults(), $definition);
    }

    public function render(array $definition): string
    {
        $definition = $this->validate($definition);
        $values = '';

        foreach (['columns', 'filters', 'actions'] as $key) {
            $items = implode(', ', array_map(fn (string $item): string => var_export($item, true), $definition[$key]));
            $values .= "        '{$key}' => [{$items}],\n";
        }

        return str_replace('{{ options }}', rtrim($values), file_get_contents(__DIR__.'/brand.stub'));
    }
}
