<?php

namespace App\Exceptions;

use RuntimeException;

class FabricConfigurationSyncException extends RuntimeException
{
    public function __construct(public readonly array $results)
    {
        $failed = array_keys(array_filter($results, fn ($result) => ! $result['applied']));
        parent::__construct('File saved in Coolify; synchronization failed on server IDs: '.implode(', ', $failed).'. Save again to retry.');
    }
}
