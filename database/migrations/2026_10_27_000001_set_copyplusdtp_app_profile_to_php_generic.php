<?php

use App\Enums\AppProfile;
use App\Jobs\WriteVhostJob;
use App\Models\Domain;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $domain = Domain::with(['account.server', 'account.package', 'certificate', 'redirects', 'protectedDirectories'])
            ->where('domain', 'copyplusdtp.in')
            ->first();

        if ($domain) {
            $domain->update([
                'app_profile' => AppProfile::PHP_GENERIC,
            ]);

            WriteVhostJob::dispatch($domain);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op rollback: keeps php-generic profile
    }
};

