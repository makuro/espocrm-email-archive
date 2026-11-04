<?php

use Espo\Core\Container;
use Espo\Core\DataManager;
use Espo\Core\InjectableFactory;
use Espo\Modules\Archive\Tools\EmailArchive\Setup;

class AfterInstall
{
    public function run(Container $container): void
    {
        $container->getByClass(InjectableFactory::class)
            ->create(Setup::class)
            ->run();

        $container->getByClass(DataManager::class)->clearCache();
    }
}
