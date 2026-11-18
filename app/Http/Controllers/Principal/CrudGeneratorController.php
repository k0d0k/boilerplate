<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Platform\Crud\BrandCrudDefinition;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;

final class CrudGeneratorController extends Controller
{
    public function index(Request $request, BrandCrudDefinition $catalog): View
    {
        $preview = $request->session()->get('principal.brand_crud_preview');

        return view('principal.crud.index', [
            'definition' => $preview ?? BrandCrudDefinition::defaults(),
            'source' => $preview === null ? null : $catalog->render($preview),
        ]);
    }

    public function preview(Request $request, BrandCrudDefinition $catalog): RedirectResponse
    {
        return to_route('principal.tailadmin.crud.index')
            ->with('principal.brand_crud_preview', $this->definition($request, $catalog));
    }

    public function export(Request $request, BrandCrudDefinition $catalog): JsonResponse
    {
        return response()->json($this->definition($request, $catalog), options: JSON_PRETTY_PRINT)
            ->header('Content-Disposition', HeaderUtils::makeDisposition('attachment', 'master-brand.json'))
            ->header('Cache-Control', 'no-store');
    }

    private function definition(Request $request, BrandCrudDefinition $catalog): array
    {
        $definition = $request->except('_token');
        $definition += ['filters' => [], 'actions' => []];

        return $catalog->validate($definition);
    }
}
