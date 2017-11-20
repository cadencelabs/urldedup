<?php
/**
 * Regenerate Url rewrites
 *
 * @package OlegKoval_RegenerateUrlRewrites
 * @author Oleg Koval <contact@olegkoval.com>
 * @copyright 2017 Oleg Koval
 * @license OSL-3.0, AFL-3.0
 */

namespace Cadence\UrlDedup\Console\Command;

use Magento\Framework\App\ObjectManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class ProductUrlRewrites extends AbstractCatalogUrlRewrites
{
    /**
     * @var string
     */
    protected $_entity = \Magento\Catalog\Model\Product::ENTITY;

    /**
     * @var string
     */
    protected $_label = 'Product';

    /**
     * @var string
     */
    protected $_code = 'products';

    /**
     * @var string
     */
    protected $_tableName = 'catalog_product_entity_varchar';
}
