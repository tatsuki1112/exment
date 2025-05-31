<?php

namespace Exceedone\Exment\Storage\Adapter;

use League\Flysystem\Local\LocalFilesystemAdapter;

class ExmentAdapterLocal extends LocalFilesystemAdapter implements ExmentAdapterInterface
{
    use AdapterTrait;

    /**
     * @var array<string, mixed>
     */
    protected static $permissions = [
        'file' => [
            'public' => 0644,
            'private' => 0600,
        ],
        'dir' => [
            // Change public permission 0755 to 0775
            'public' => 0775,
            'private' => 0700,
        ],
    ];

    /**
     * get adapter class
     * @param mixed $app
     * @param mixed $config
     * @param mixed $driverKey
     * @return self
     */
    public static function getAdapter($app, $config, $driverKey)
    {
        $mergeConfig = static::getConfig($config);
        return new self(array_get($mergeConfig, 'root'));
    }

    public static function getMergeConfigKeys(string $mergeFrom, array $options = []): array
    {
        return [];
    }

    /**
     * Get config. Execute merge.
     *
     * @param array<mixed> $config
     * @return array<mixed>
     */
    public static function getConfig($config): array
    {
        $mergeFrom = array_get($config, 'mergeFrom');
        $config = static::mergeFileConfig('filesystems.disks.local', "filesystems.disks.$mergeFrom", $mergeFrom);
        return $config;
    }
}
