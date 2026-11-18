<?php

namespace App\Platform\Crud\Generated;

final class BrandCrud
{
    public const OPTIONS = [
        'columns' => ['name', 'slug', 'status'],
        'filters' => ['status'],
        'actions' => ['create', 'edit', 'change_status'],
    ];
}
