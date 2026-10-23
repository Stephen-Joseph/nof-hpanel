<?php

namespace App\Services;

use App\Enums\ServerStatus;
use App\Exceptions\NoAvailableServerException;
use App\Models\Server;

class ServerScheduler
{
    /**
     * Select the best eligible server node to provision a new hosting account.
     * Selects from ACTIVE servers with remaining capacity, sorted by least allocated.
     * Draining and maintenance servers are strictly excluded.
     *
     * @throws NoAvailableServerException
     */
    public function selectServer(?int $serverGroupId = null, ?string $location = null): Server
    {
        $query = Server::query()
            ->where('status', ServerStatus::ACTIVE)
            ->withCount('accounts');

        if ($serverGroupId !== null) {
            $query->where('server_group_id', $serverGroupId);
        }

        if ($location !== null) {
            $query->where('location', $location);
        }

        // Filter servers with remaining capacity
        $candidates = $query->get()->filter(function (Server $server) {
            return $server->accounts_count < $server->max_accounts;
        });

        if ($candidates->isEmpty()) {
            throw new NoAvailableServerException(
                $serverGroupId !== null || $location !== null
                    ? 'No active server with available capacity was found matching the requested cluster/location criteria.'
                    : 'No active server with available capacity is currently online.'
            );
        }

        // Return server with lowest active account allocation
        /** @var Server $bestServer */
        $bestServer = $candidates->sortBy('accounts_count')->first();

        return $bestServer;
    }
}
