<?php

namespace Infield\AemImport\Controller\Adminhtml\Import;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\CategoryListInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Psr\Log\LoggerInterface;

class Save extends \Magento\Backend\App\Action {

    /**
     * @var CategoryListInterface
     */
    private $categoryList;

    /**
     * @var CategoryRepositoryInterface
     */
    private $categoryRepository;

    /**
     * @var ProductFactory
     */
    private $productFactory;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var StockRegistryInterface
     */
    private $stockRegistry;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var ClientFactory
     */
    private $httpClientFactory;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $serializer;

    /**
     * @var LoggerInterface
     */
    private $logger;


    public function __construct(Context $context,
                                CategoryListInterface $categoryList,
                                CategoryRepositoryInterface $categoryRepository,
                                ProductFactory $productFactory,
                                ProductRepositoryInterface $productRepository,
                                StockRegistryInterface $stockRegistry,
                                SearchCriteriaBuilder $searchCriteriaBuilder,
                                ClientFactory $httpClientFactory,
                                Json $serializer = null,
                                LoggerInterface $logger)
    {
        parent::__construct($context);

        $this->categoryList = $categoryList;
        $this->categoryRepository = $categoryRepository;
        $this->productFactory = $productFactory;
        $this->productRepository = $productRepository;
        $this->stockRegistry = $stockRegistry;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->httpClientFactory = $httpClientFactory;
        $this->serializer = $serializer ?: ObjectManager::getInstance()->get(Json::class);
        $this->logger = $logger;
    }

    /**
     * Execute action based on request and return result
     *
     * Note: Request will be added as operation argument in future
     *
     * @return \Magento\Framework\Controller\ResultInterface|ResponseInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\StateException
     */
    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        $fieldset = $this->getRequest()->getParam('aemimport_fieldset');

        $httpClient = $this->httpClientFactory->create();
        $httpClient->setCredentials($fieldset['aem_username'], $fieldset['aem_password']);
        $httpClient->get($fieldset['aem_instance_url'] . $fieldset['aem_products_path'] . ".infinity.json");

        $aemCommerceData = $this->serializer->unserialize($httpClient->getBody());

        $defaultCategory = $this->getCategoryById(2);
        $categories = $this->getChildCategoryByName($defaultCategory, 'men');
        foreach ($categories as $category) {
            $this->logger->debug("Category = " . $category->getName() . " : " . $category->getPath());
        }

        foreach ($aemCommerceData as $aemPotentialCategory => $aemPotentialCategoryValue) {
            if (is_array($aemPotentialCategoryValue) && $aemPotentialCategoryValue["jcr:primaryType"] == "sling:Folder") {

                // Types of sling:Folder are assumed to be categories
                //TODO : Ensure this category exists
                foreach ($aemPotentialCategoryValue as $aemPotentialSubcategory => $aemPotentialSubcategoryValue) {
                    if (is_array($aemPotentialSubcategoryValue) && $aemPotentialSubcategoryValue["jcr:primaryType"] == "sling:Folder") {
                        foreach ($aemPotentialSubcategoryValue as $aemPotentialProduct => $aemPotentialProductValue) {
                            if (is_array($aemPotentialProductValue) &&
                                array_key_exists("sling:resourceType", $aemPotentialProductValue) &&
                                $aemPotentialProductValue["sling:resourceType"] == "commerce/components/product") {

                                $sku = $aemPotentialProductValue["identifier"];

                                // If product does not exist then create it
                                if (!$this->productExists($sku)) {
                                    $this->logger->debug("Creating product " . $aemPotentialProductValue["jcr:title"]);

                                    $product = $this->productFactory->create();
                                    $product->setSku($sku);
                                    $product->setName($aemPotentialProductValue["jcr:title"]);
                                    $product->setTypeId(Type::TYPE_SIMPLE);
                                    $product->setVisibility(4);
                                    $product->setPrice($aemPotentialProductValue["price"]);
                                    $product->setAttributeSetId(4); // Default attribute set for products
                                    $product->setStatus(Status::STATUS_ENABLED);

                                    //TODO: Create a product for each color/size variation
                                    //$this->setProductAttribute($product, 'color', 'Yellow');

                                    //TODO: Import product images

                                    //TODO: Add product to appropriate categories

                                    $product = $this->productRepository->save($product);
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->messageManager->addSuccessMessage(__('Import success.'));

        return $resultRedirect->setPath('aemimport/import');
    }

    /**
     * Return product by SKU
     *
     * @param string $sku
     * @return Product|bool
     */
    protected function getProductBySku(string $sku)
    {
        /** @var Product $product */
        $product = Bootstrap::getObjectManager()->get(Product::class);
        return $product->loadByAttribute('sku', $sku);
    }

    /**
     * Return whether or not a product with this SKU already exists
     * @param string $sku
     * @return bool
     */
    protected function productExists(string $sku) {
        try {
            $this->productRepository->get($sku);
            return true;
        } catch (NoSuchEntityException $e) {
            return false;
        }
    }

    protected function getCategoryById($id) {
        $searchCriteria = $this->searchCriteriaBuilder->addFilter("entity_id", $id)->create();
        $resultCategories = $this->categoryList->getList($searchCriteria)->getItems();
        return count($resultCategories) > 0 ? $resultCategories[0] : null;
    }

    /**
     * @param CategoryInterface $parentCategory
     * @param string $name
     * @return null
     */
    protected function getChildCategoryByName($parentCategory, $name) {
        if ($parentCategory && $name) {
            $childCategories = $parentCategory->getChildren();
            if ($childCategories) {
                foreach (explode(',', $childCategories) as $childCategoryId) {
                    $this->logger->debug("childCategoryId = " . $childCategoryId);
                    $childCategory = $this->getCategoryById($childCategoryId);
                    $this->logger->debug("Child category name = " . $childCategory->getName());
                    if (strcasecmp($childCategory->getName(), $name)) {
                        return $childCategory;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Set the value of a product option attribute.
     * @param $product
     * @param $name
     * @param $value
     */
    protected function setProductAttribute(&$product, $name, $value) {
        $attr = $product->getResource()->getAttribute($name);
        $avid = $attr->getSource()->getOptionId($value);
        $product->setData($name, $avid);
    }
}