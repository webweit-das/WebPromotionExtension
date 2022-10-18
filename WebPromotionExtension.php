<?php declare(strict_types=1);
/**
 * WebPromotionExtension
 * Copyright © 2019 webweit GmbH
 */

namespace WebPromotionExtension;

use Shopware\Components\Plugin;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\Context\UninstallContext;

class WebPromotionExtension extends Plugin
{
    public function install(InstallContext $context)
    {
        parent::install($context);
    }

    public function uninstall(UninstallContext $context)
    {
        parent::uninstall($context);
    }
}
