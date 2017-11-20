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

class CategoryUrlRewrites extends AbstractCatalogUrlRewrites
{
    /**
     * @var string
     */
    protected $_entity = \Magento\Catalog\Model\Category::ENTITY;

    /**
     * @var string
     */
    protected $_label = 'Category';

    /**
     * @var string
     */
    protected $_code = 'categories';

    /**
     * @var string
     */
    protected $_tableName = 'catalog_category_entity_varchar';

    /**
     * @param $attributeId
     * @param $storeId
     * @return array
     */
    public function getUrlAttributeUpdates($attributeId, $storeId)
    {
        $extraAttributes = $this->getUrlAttributes(['url_path']);
        $pathId = $extraAttributes['url_path'];

        $sql = "
SELECT main.`value`, min(main.entity_id) as master_entity_id, path.value as path_value, COUNT(*) as c 
FROM {$this->_resource->getTableName($this->_tableName)}  main
LEFT JOIN {$this->_resource->getTableName('catalog_category_entity_varchar')} path
ON 
      path.entity_id = main.entity_id
  AND path.attribute_id = '{$pathId}'
  AND path.store_id ='{$storeId}'
where main.attribute_id = '{$attributeId}'
and main.store_id = '{$storeId}'
and main.`value` IS NOT NULL
GROUP BY main.value, IFNULL(path.value,''), main.store_id HAVING c > 1;
";

        $queryResult = $this->_resource->getConnection()->fetchAll($sql);

        $attributes = [];

        foreach($queryResult as $row) {
            $attributes[] = $row;
        }

        return $attributes;
    }

    /**
     * @param $entityValue
     * @param $attributeId
     * @param $masterEntityId
     * @param $storeId
     * @param array $additional
     * @return $this
     */
    public function dedupUrlAttribute($entityValue, $attributeId, $masterEntityId, $storeId, $additional = [])
    {
        $extraAttributes = $this->getUrlAttributes(['url_path']);
        $pathId = $extraAttributes['url_path'];

        $path_value = $additional['path_value'];
        $tableName = $this->_resource->getTableName($this->_tableName);

        $pathComponent = $path_value ? "path.value IS NOT NULL AND path.value = '{$path_value}'"
            : 'path.value IS NULL';

        $sql = "
UPDATE {$tableName} main 
 LEFT JOIN {$this->_resource->getTableName('catalog_category_entity_varchar')} path
 ON 
        path.entity_id = main.entity_id 
    AND path.attribute_id = '{$pathId}'
    AND path.store_id = '{$storeId}'
 SET main.`value` = CONCAT(main.`value`, '-', main.entity_id) 
WHERE 
main.attribute_id = '{$attributeId}' 
AND main.store_id = '{$storeId}' 
AND main.entity_id <> $masterEntityId
AND main.`value` IS NOT NULL 
AND main.`value` = '{$entityValue}' 
AND $pathComponent 
";
        $this->_resource->getConnection()->query($sql);

        return $this;
    }

    /**
     * Fix duplicate attributes at the same store_id scope
     * @param $attributeId
     * @param $storeId
     * @param OutputInterface $output
     * @return $this
     */
    public function processUrlAttributeUpdates($attributeId, $storeId, OutputInterface $output)
    {

        $urlAttributeUpdates = $this->getUrlAttributeUpdates($attributeId, $storeId);

        foreach ($urlAttributeUpdates as $update) {
            $updateCount = $update['c'] - 1;
            $output->writeln(
                "[Found value to dedup: {$update['value']}. " .
                "{$this->_label} ID {$update['master_entity_id']} will keep original URL. " .
                "{$updateCount} " . $this->_label . "(s) will have their URLs changed"
            );

            $this->dedupUrlAttribute($update['value'], $attributeId, $update['master_entity_id'], $storeId, [
                'path_value' => $update['path_value']
            ]);

            $output->writeln(
                "[DEDUPED {$update['value']}]"
            );
        }

        return $this;
    }

    /**
     * Fix implicit duplicate attributes that are created by the store_id = 0 value
     * cascading down to independent store views
     *
     * ** Update from base -- only compare categories with the same parent id
     * @param $attributeId
     * @param $storeId
     * @param OutputInterface $output
     * @return $this
     */
    public function processCascadingUrlAttributeUpdates($attributeId, $storeId, OutputInterface $output)
    {
        if ($storeId == 0) {
            $output->writeln("[Refusing to process cascading duplicates for root store]");
            return $this;
        }

        $extraAttributes = $this->getUrlAttributes(['url_path']);
        $pathId = $extraAttributes['url_path'];

        $sql = "
UPDATE {$this->_resource->getTableName($this->_tableName)} leafUrl

INNER JOIN {$this->_resource->getTableName($this->_tableName)} rootUrl 
	on 
			leafUrl.value = rootUrl.value 
        AND leafUrl.attribute_id = rootUrl.attribute_id 
        AND rootUrl.entity_id <> leafUrl.entity_id
        AND rootUrl.store_id = 0
        
LEFT JOIN {$this->_resource->getTableName($this->_tableName)} subRootUrl
	on 
			subRootUrl.entity_id = rootUrl.entity_id
        AND leafUrl.attribute_id = subRootUrl.attribute_id 
        AND subRootUrl.store_id = '{$storeId}'
        
LEFT JOIN {$this->_resource->getTableName('catalog_category_entity_varchar')} leafPath
  ON 
          leafPath.entity_id = leafUrl.entity_id 
      AND leafPath.attribute_id = '{$pathId}'
      AND leafPath.store_id = '{$storeId}'        
        
LEFT JOIN {$this->_resource->getTableName('catalog_category_entity_varchar')} rootPath
  On 
          rootUrl.entity_id = rootPath.entity_id
      AND leafPath.attribute_id = '{$pathId}'
      AND rootPath.value = leafPath.value
      AND rootPath.store_id = 0

set leafUrl.`value` = CONCAT(leafUrl.`value`, '-', leafUrl.entity_id)

WHERE leafUrl.attribute_id = '{$attributeId}' AND leafUrl.store_id = '{$storeId}' 
AND leafPath.value = rootPath.value
AND subRootUrl.value_id IS NULL
;
        ";

        $this->_resource->getConnection()->query($sql);

        return $this;
    }
}
