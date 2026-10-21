<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $packages = Package::availableFor($user)->orderBy('name')->get();

        return response()->json([
            'packages' => $packages->map(fn (Package $pkg) => [
                'id' => $pkg->id,
                'name' => $pkg->name,
                'storage_mb' => $pkg->storage_mb,
                'inodes' => $pkg->inodes,
                'bandwidth_gb' => $pkg->bandwidth_gb,
                'max_websites' => $pkg->max_websites,
                'max_databases' => $pkg->max_databases,
                'max_ftp_accounts' => $pkg->max_ftp_accounts,
                'max_cron_jobs' => $pkg->max_cron_jobs,
                'php_workers' => $pkg->php_workers,
                'php_memory_mb' => $pkg->php_memory_mb,
                'allowed_php_versions' => $pkg->allowed_php_versions,
                'ssh_enabled' => $pkg->ssh_enabled,
                'git_enabled' => $pkg->git_enabled,
            ]),
        ]);
    }
}
