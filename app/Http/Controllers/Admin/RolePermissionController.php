<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RolePermissionController extends Controller
{
    /**
     * Display the Role & Permission matrix.
     */
    public function index(): View
    {
        $permissions = Permission::all()->groupBy('category');

        // Fetch permission slugs mapped to 'user' role
        $userPermissionSlugs = DB::table('role_permissions')
            ->where('role', 'user')
            ->pluck('permission_slug')
            ->toArray();

        return view('admin.roles.index', compact('permissions', 'userPermissionSlugs'));
    }

    /**
     * Update permissions for the 'user' role.
     */
    public function update(Request $request): RedirectResponse
    {
        $selectedSlugs = (array) $request->input('permissions', []);

        // Validate that permission slugs exist
        $validSlugs = Permission::whereIn('slug', $selectedSlugs)->pluck('slug')->toArray();

        DB::transaction(function () use ($validSlugs) {
            DB::table('role_permissions')->where('role', 'user')->delete();

            $records = array_map(fn ($slug) => [
                'role' => 'user',
                'permission_slug' => $slug,
                'created_at' => now(),
                'updated_at' => now(),
            ], $validSlugs);

            if (! empty($records)) {
                DB::table('role_permissions')->insert($records);
            }
        });

        return redirect()->route('admin.roles.index')->with('success', "Role 'user' permissions updated successfully!");
    }
}
