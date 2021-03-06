<?php

namespace Yaroslavche\TDLibBundle\Tests;

use RuntimeException;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Exception\LoaderLoadException;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as SymfonyKernel;
use Symfony\Component\Routing\RouteCollectionBuilder;
use TDApi\TDLibParameters;
use Yaroslavche\TDLibBundle\YaroslavcheTDLibBundle;

class Kernel extends SymfonyKernel
{
    use MicroKernelTrait;

    /**
     * Kernel constructor.
     *
     * @param string $env
     * @param bool $debug
     */
    public function __construct(string $env = 'test', bool $debug = true)
    {
        parent::__construct($env, $debug);
    }

    public function registerBundles()
    {
        return [
            new YaroslavcheTDLibBundle(),
            new FrameworkBundle()
        ];
    }

    public function getCacheDir()
    {
        return __DIR__ . '/cache/' . spl_object_hash($this);
    }

    /**
     * @return array
     */
    public static function getBundleConfig(): array
    {
        if (!isset($_ENV['API_ID']) || !isset($_ENV['API_HASH']) || !isset($_ENV['PHONE_NUMBER'])) {
            throw new RuntimeException('Some of API_ID, API_HASH, PHONE_NUMBER environment variable is not defined. You need to define environment variables in phpunit.xml (copy from dist).');
        }
        $_POST[sprintf('code_%s', $_ENV['PHONE_NUMBER'])] = $_ENV['CODE'];
        return [
            'parameters' => [
                /** required */
                TDLibParameters::API_ID => (int)$_ENV['API_ID'],
                TDLibParameters::API_HASH => $_ENV['API_HASH'],
                TDLibParameters::SYSTEM_LANGUAGE_CODE => 'en',
                TDLibParameters::DEVICE_MODEL => 'test',
                TDLibParameters::SYSTEM_VERSION => 'stable',
                TDLibParameters::APPLICATION_VERSION => '0.0.1',
                /** optional */
                TDLibParameters::USE_TEST_DC => true,
                TDLibParameters::IGNORE_FILE_NAMES => true,
                TDLibParameters::USE_SECRET_CHATS => true,
                TDLibParameters::USE_MESSAGE_DATABASE => true,
                TDLibParameters::USE_CHAT_INFO_DATABASE => true,
                TDLibParameters::USE_FILE_DATABASE => true,
                TDLibParameters::FILES_DIRECTORY => '/var/tmp/tdlib',
                TDLibParameters::DATABASE_DIRECTORY => '/var/tmp/tdlib',
            ],
            'client' => [
                'phone_number' => $_ENV['PHONE_NUMBER'],
                'encryption_key' => '',
                'default_timeout' => 10,
                'auto_init' => false,
            ]
        ];
    }

    /**
     * @param RouteCollectionBuilder $routes
     * @throws LoaderLoadException
     */
    protected function configureRoutes(RouteCollectionBuilder $routes)
    {
        $routes->import(__DIR__ . '/../src/Resources/config/routes.xml');
    }

    /**
     * @param ContainerBuilder $c
     * @param LoaderInterface $loader
     */
    protected function configureContainer(ContainerBuilder $c, LoaderInterface $loader)
    {
        $c->loadFromExtension(
            'framework',
            [
                'secret' => 'test'
            ]
        );
        $c->loadFromExtension('yaroslavche_tdlib', static::getBundleConfig());
    }
}
