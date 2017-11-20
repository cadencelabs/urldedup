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

class AbstractCatalogUrlRewrites extends Command
{
    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory
     */
    protected $_categoryCollectionFactory;

    /**
     * @var \Magento\Catalog\Helper\Category
     */
    protected $_categoryHelper;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var \Magento\Framework\App\State $appState
     */
    protected $_appState;

    /**
     * @var string
     */
    protected $_tableName;

    /**
     * @var string
     */
    protected $_label;

    /**
     * @var string
     */
    protected $_entity;

    /**
     * @var string
     */
    protected $_code;

    /**
     * @var array
     */
    protected $_urlAttributes = ['url_key'];

    /**
     * Constructor of RegenerateUrlRewrites
     *
     * @param \Magento\Framework\App\ResourceConnection $resource
     */
    public function __construct(
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Framework\App\State $appState
    ) {
        $this->_resource = $resource;
        $this->_appState = $appState;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        if (is_null($this->_tableName)) {
            throw new \Exception("Invalid Dedup instance! You must define the dedup table.");
        }
        if (is_null($this->_label)) {
            throw new \Exception("Invalid Dedup instance! You must define the dedup label.");
        }
        if (is_null($this->_entity)) {
            throw new \Exception("Invalid Dedup instance! You must define the dedup entity.");
        }
        if (is_null($this->_code)) {
            throw new \Exception("Invalid Dedup instance! You must define the dedup code.");
        }
        $this->setName('cadence:urldedup:' . $this->_code)
            ->setDescription('Dedup ' . $this->_label . ' url rewrites across stores')
            ->setDefinition([
                new InputArgument(
                    'storeId',
                    InputArgument::OPTIONAL,
                    'Store ID: 5'
                )
            ]);
    }

    /**
     * Regenerate Url Rewrites
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        set_time_limit(0);
        $allStores = $this->getAllStoreIds();
        $output->writeln('Dedup ' . $this->_label . ' Url rewrites:');

        // get store Id (if was set)
        $storeId = $input->getArgument('storeId');

        // dedup only this store Id
        if (!empty($storeId) && $storeId > 0) {
            if (isset($allStores[$storeId])) {
                $storesList = array(
                    $storeId => $allStores[$storeId]
                );
            } else {
                $output->writeln('ERROR: store with this ID does not exists.');
                $output->writeln('Finished');
                return;
            }
        }
        // otherwise we dedup for all stores
        else {
            $storesList = $allStores;
        }

        // set area code if needed
        try {
            $areaCode = $this->_appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // if area code is not set then magento generate exception "LocalizedException"
            $this->_appState->setAreaCode('adminhtml');
        }

        $urlAttributes = $this->getUrlAttributes();

        // begin the transaction
        $this->_resource->getConnection()->beginTransaction();

        try {
            foreach ($storesList as $storeId => $storeCode) {
                $output->writeln('');
                $output->write("[Store ID: {$storeId}, Store View code: {$storeCode}]");
                $output->writeln('');

                foreach ($urlAttributes as $attributeCode => $attributeId) {

                    $output->writeln('');
                    $output->writeln("[Dedup {$attributeCode} for {$storeId} store id: explicit conflicts.]");

                    // Handle explicit URL conflicts (url attribute / store_id conflict has explicit duplicate)
                    $this->processUrlAttributeUpdates(
                        $attributeId,
                        $storeId,
                        $output
                    );

                    $output->writeln("[Dedup {$attributeCode} for {$storeId} store id: implicit URL conflicts from cascading default value. " .
                        "Default values will be left unchanged, and store-view level entities will have their URLs changed.]");

                    // Handle implicit URL conflicts (derived conflicts from default value)
                    $this->processCascadingUrlAttributeUpdates(
                        $attributeId,
                        $storeId,
                        $output
                    );


                }

                $output->writeln('');
            }

            // commit
            $this->_resource->getConnection()->commit();

        } catch (\Throwable $e) {
            $output->writeln($e->getMessage());
            // rollback on error
            $this->_resource->getConnection()->rollBack();
            exit;
        }

        $output->writeln('');

        $output->writeln('Reindexing: php bin/magento indexer:reindex');
        shell_exec('php bin/magento indexer:reindex');

        $output->writeln('Refreshing Cache: php bin/magento cache:clean && php bin/magento cache:flush');
        shell_exec('php bin/magento cache:clean');
        shell_exec('php bin/magento cache:flush');
        $output->writeln('Finished');
    }

    /**
     * Get list of all stores id/code
     *
     * @return array
     */
    public function getAllStoreIds() {
        $result = [
            0 => 'admin'
        ];

        $sql = $this->_resource->getConnection()->select()
            ->from($this->_resource->getTableName('store'), array('store_id', 'code'))
            ->where('`code` <> ?', 'admin')
            ->order('store_id', 'ASC');

        $queryResult = $this->_resource->getConnection()->fetchAll($sql);

        foreach ($queryResult as $row) {
            if (isset($row['store_id']) && (int)$row['store_id'] > 0) {
                $result[(int)$row['store_id']] = $row['code'];
            }
        }

        return $result;
    }

    /**
     * @return array
     */
    public function getUrlAttributes($attributes = null)
    {
        if (is_null($attributes)) {
            $attributes = $this->_urlAttributes;
        }
        $entityTypeId = ObjectManager::getInstance()
            ->create('Magento\Eav\Model\Config')
            ->getEntityType($this->_entity)
            ->getEntityTypeId();

        $sql = $this->_resource->getConnection()->select()
            ->from($this->_resource->getTableName('eav_attribute'), array('attribute_id', 'attribute_code'))
            ->where('`attribute_code` IN (?)', $attributes)
            ->where('`entity_type_id` = ?', $entityTypeId);

        $queryResult = $this->_resource->getConnection()->fetchAll($sql);

        $attributes = [];

        foreach($queryResult as $row) {
            $attributes[$row['attribute_code']] = $row['attribute_id'];
        }

        return $attributes;
    }

    /**
     * @param $attributeId
     * @param $storeId
     * @return array
     */
    public function getUrlAttributeUpdates($attributeId, $storeId)
    {
        $sql = "
SELECT `value`, min(entity_id) as master_entity_id, COUNT(*) as c 
FROM {$this->_resource->getTableName($this->_tableName)} 
where attribute_id = '{$attributeId}'
and store_id = '{$storeId}'
and `value` IS NOT NULL
GROUP BY value, store_id HAVING c > 1;
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
        $tableName = $this->_resource->getTableName($this->_tableName);

        $sql = "
UPDATE {$tableName} SET `value` = CONCAT(`value`, '-', entity_id) 
WHERE attribute_id = '{$attributeId}' AND store_id = '{$storeId}' AND entity_id <> $masterEntityId
AND `value` IS NOT NULL AND `value` = '{$entityValue}'
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

            $this->dedupUrlAttribute($update['value'], $attributeId, $update['master_entity_id'], $storeId);

            $output->writeln(
                "[DEDUPED {$update['value']}]"
            );
        }

        return $this;
    }

    /**
     * Fix implicit duplicate attributes that are created by the store_id = 0 value
     * cascading down to independent store views
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

set leafUrl.`value` = CONCAT(leafUrl.`value`, '-', leafUrl.entity_id)

WHERE 
leafUrl.attribute_id = '{$attributeId}' 
AND leafUrl.store_id = '{$storeId}'
AND subRootUrl.value_id IS NULL;
        ";

        $this->_resource->getConnection()->query($sql);

        return $this;
    }
}
