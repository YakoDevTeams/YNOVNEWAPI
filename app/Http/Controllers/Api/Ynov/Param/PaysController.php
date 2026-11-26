<?php

namespace App\Http\Controllers\Api\Ynov\Param;

use App\Http\Controllers\Controller;
use App\Models\Api\Ynov\parameter\ActivityLog;
use App\Models\Api\Ynov\parameter\Pays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PaysController extends Controller
{
    public function indexPays(Request $request): JsonResponse
    {
        $query = Pays::query();
        $search = trim((string) $request->input('search', ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('codePays', 'LIKE', "%{$search}%")
                    ->orWhere('MonLibelle', 'LIKE', "%{$search}%")
                    ->orWhere('Indicatif', 'LIKE', "%{$search}%");
            });
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $pays = $query->orderBy('MonLibelle')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Liste des pays récupérée.',
            'code' => 'PAYS_LISTED',
            'data' => $pays,
        ]);
    }

    public function storePays(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codePays' => ['required', 'string', 'max:10', Rule::unique('pays', 'codePays')],
            'MonLibelle' => ['required', 'string', 'max:255'],
            'Indicatif' => ['nullable', 'string', 'max:10'],
            'Flag' => ['nullable', 'string', 'max:10'],
            'FlagUrl' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['uuid_pays'] = (string) Str::uuid();

        $pays = Pays::create($validated);

        ActivityLog::log([
            'user_uuid' => $request->user()->uuid_user,
            'action' => 'create',
            'action_type' => 'crud',
            'module' => 'pays',
            'description' => "Création du pays : {$pays->MonLibelle}",
            'resource_type' => 'pays',
            'resource_id' => $pays->uuid_pays,
            'new_values' => $pays->toArray(),
            'level' => 'info',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pays créé avec succès.',
            'code' => 'PAYS_CREATED',
            'data' => $pays,
        ], 201);
    }

    public function show(string $uuid_pays): JsonResponse
    {
        $pays = Pays::where('uuid_pays', $uuid_pays)->firstOrFail();

        return response()->json([
            'success' => true,
            'message' => 'Détails du pays.',
            'code' => 'PAYS_FOUND',
            'data' => $pays,
        ]);
    }

    public function update(Request $request, string $uuid_pays): JsonResponse
    {
        $pays = Pays::where('uuid_pays', $uuid_pays)->firstOrFail();
        $oldValues = $pays->toArray();

        $validated = $request->validate([
            'codePays' => [
                'sometimes', 'required', 'string', 'max:10',
                Rule::unique('pays', 'codePays')->ignore($pays->uuid_pays, 'uuid_pays'),
            ],
            'MonLibelle' => ['sometimes', 'required', 'string', 'max:255'],
            'Indicatif' => ['sometimes', 'nullable', 'string', 'max:10'],
            'Flag' => ['sometimes', 'nullable', 'string', 'max:10'],
            'FlagUrl' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $pays->update($validated);

        ActivityLog::log([
            'user_uuid' => $request->user()->uuid_user,
            'action' => 'update',
            'action_type' => 'crud',
            'module' => 'pays',
            'description' => "Mise à jour du pays : {$pays->MonLibelle}",
            'resource_type' => 'pays',
            'resource_id' => $pays->uuid_pays,
            'old_values' => $oldValues,
            'new_values' => $pays->fresh()->toArray(),
            'level' => 'info',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pays mis à jour avec succès.',
            'code' => 'PAYS_UPDATED',
            'data' => $pays->fresh(),
        ]);
    }

    public function destroy(Request $request, string $uuid_pays): JsonResponse
    {
        $pays = Pays::where('uuid_pays', $uuid_pays)->firstOrFail();
        $oldValues = $pays->toArray();
        $pays->delete();

        ActivityLog::log([
            'user_uuid' => $request->user()->uuid_user,
            'action' => 'delete',
            'action_type' => 'crud',
            'module' => 'pays',
            'description' => "Suppression du pays : {$pays->MonLibelle}",
            'resource_type' => 'pays',
            'resource_id' => $pays->uuid_pays,
            'old_values' => $oldValues,
            'level' => 'warning',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pays supprimé avec succès.',
            'code' => 'PAYS_DELETED',
        ]);
    }
}
