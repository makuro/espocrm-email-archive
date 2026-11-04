<?php

use Espo\Core\Container;
use Espo\Core\InjectableFactory;
use Espo\Modules\Archive\Tools\EmailArchive\Setup;

class BeforeUninstall
{
    /**
     * @param array{isUpgrade?: bool} $params
     */
    public function run(Container $container, array $params = []): void
    {
        // Installing a new version uninstalls the old one first; keep jobs and panels then.
        if ($params['isUpgrade'] ?? false) {
            return;
        }

        $container->getByClass(InjectableFactory::class)
            ->create(Setup::class)
            ->teardown();
    }
}
