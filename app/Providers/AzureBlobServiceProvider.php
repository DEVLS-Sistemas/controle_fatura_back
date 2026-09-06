<?php

namespace App\Providers;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Common\Internal\StorageServiceSettings;
use Throwable;

class AzureBlobServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('azure', function ($app, array $config) {
            $connectionString = $config['connection_string'] ?: sprintf(
                'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s',
                $config['name'] ?? '',
                $config['key'] ?? ''
            );

            $client = BlobRestProxy::createBlobService($connectionString);
            $serviceSettings = $this->serviceSettings($connectionString, $config);
            $adapter = new AzureBlobStorageAdapter(
                $client,
                (string) ($config['container'] ?? ''),
                (string) ($config['prefix'] ?? ''),
                null,
                5000,
                AzureBlobStorageAdapter::ON_VISIBILITY_THROW_ERROR,
                $serviceSettings
            );

            $filesystem = new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );

            if ($serviceSettings !== null) {
                $filesystem->buildTemporaryUrlsUsing(
                    function (string $path, $expiration, array $options = []) use ($adapter) {
                        return $adapter->temporaryUrl($path, $expiration, new Config($options));
                    }
                );
            }

            return $filesystem;
        });
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function serviceSettings(string $connectionString, array $config): ?StorageServiceSettings
    {
        if ($connectionString === '' || (
            empty($config['connection_string']) && (empty($config['name']) || empty($config['key']))
        )) {
            return null;
        }

        try {
            return StorageServiceSettings::createFromConnectionString($connectionString);
        } catch (Throwable) {
            return null;
        }
    }
}
