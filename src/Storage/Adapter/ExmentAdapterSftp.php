<?php

namespace Exceedone\Exment\Storage\Adapter;

use League\Flysystem\PhpseclibV2\SftpAdapter;
use League\Flysystem\PhpseclibV2\SftpConnectionProvider;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;

class ExmentAdapterSftp extends SftpAdapter implements ExmentAdapterInterface
{
    use AdapterTrait;

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

        $driver = new self(
            SftpConnectionProvider::fromArray($mergeConfig),
            array_get($mergeConfig, 'root')
            //,
            //PortableVisibilityConverter::fromArray(array_get($mergeConfig, 'permissions'))
        );
        return $driver;
    }

    /**
     * @param string $mergeFrom
     * @param array<mixed> $options
     * @return array<mixed>
     */
    public static function getMergeConfigKeys(string $mergeFrom, array $options = []): array
    {
        return [
            'root' => config('exment.rootpath.sftp.' . $mergeFrom),
        ];
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
        $mergeConfig = static::mergeFileConfig('filesystems.disks.sftp', "filesystems.disks.$mergeFrom", $mergeFrom);
        $mergeConfig['driver'] = 'sftp';
        return $mergeConfig;
    }
}
