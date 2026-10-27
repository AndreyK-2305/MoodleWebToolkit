<?php

namespace App\Domain\Aws;

use App\Models\AwsStorageSettings;
use Illuminate\Database\QueryException;
use PDOException;

/**
 * Lays the bucket an administrator saved over `toolkit.remote.package_bucket`
 * and `package_region`, so every reader of that configuration follows the web
 * settings without changes. Without saved settings the installation's
 * environment values stay in place.
 */
class RemoteStorageConfiguration
{
    public function apply(): void
    {
        try {
            $settings = AwsStorageSettings::current();
        } catch (QueryException|PDOException) {
            // No database yet (installation, migrations): the environment values remain.
            return;
        }
        if ($settings !== null) {
            config(['toolkit.remote.package_bucket' => $settings->bucket, 'toolkit.remote.package_region' => $settings->region]);
        }
    }
}
