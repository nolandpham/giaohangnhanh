<?php

namespace Boolfly\GiaoHangNhanh\Model\Api\Rest\Service\Order;

use Boolfly\GiaoHangNhanh\Model\Api\Rest\Service;
use Boolfly\GiaoHangNhanh\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\ZendClientFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Model\Quote\AddressFactory;
use Magento\Sales\Model\Order;
use Magento\Store\Model\Information;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zend_Http_Client_Exception;

class Synchronizer extends Service
{
    const GHN_STATUS_FAIL = 0;
    const GHN_STATUS_SUCCESS = 1;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Information
     */
    private $storeInformation;

    /**
     * @var AddressFactory
     */
    private $addressFactory;

    /**
     * Synchronizer constructor.
     * @param LoggerInterface $log
     * @param Config $config
     * @param SerializerInterface $serializer
     * @param ZendClientFactory $httpClientFactory
     * @param StoreManagerInterface $storeManager
     * @param Information $storeInformation
     * @param AddressFactory $addressFactory
     */
    public function __construct(
        LoggerInterface $log,
        Config $config,
        SerializerInterface $serializer,
        ZendClientFactory $httpClientFactory,
        StoreManagerInterface $storeManager,
        Information $storeInformation,
        AddressFactory $addressFactory
    ) {
        parent::__construct($log, $config, $serializer, $httpClientFactory);
        $this->storeManager = $storeManager;
        $this->storeInformation = $storeInformation;
        $this->addressFactory = $addressFactory;
    }

    /**
     * @param Order $order
     * @param array $additionalData
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws Zend_Http_Client_Exception
     */
    public function syncOrder(Order $order, $additionalData)
    {
        $config = $this->config;
        $weightRate = $config->getWeightUnit() == 'kgs' ? Config::KGS_G : Config::LBS_G;
        $store = $this->storeManager->getStore();
        $storeInfo = $this->storeInformation->getStoreInformationObject($store);
        $storeFormattedAddress = $this->storeInformation->getFormattedAddress($store);
        $storeDistrict = (int)$config->getStoreDistrict();

        $data = [
            'token' => $config->getApiToken(),
            'PaymentTypeID' => (int)$config->getPaymentType(),
            'FromDistrictID' => $storeDistrict,
            'ToDistrictID' => (int)$additionalData['district'],
            'ClientContactName' => $storeInfo->getName(),
            'ClientContactPhone' => $storeInfo->getPhone(),
            'ClientAddress' => $storeFormattedAddress,
            'CustomerName' => $order->getCustomerName(),
            'CustomerPhone' => $order->getShippingAddress()->getTelephone(),
            'ShippingAddress' => $order->getShippingAddress()->getStreetLine(1),
            'NoteCode' => $config->getNoteCode(),
            'ServiceID' => $additionalData['shipping_service_id'],
            'Weight' => $order->getWeight() * $weightRate,
            'Length' => 10,
            'Width' => 10,
            'Height' => 10,
            'CoDAmount' => 0,
            'ReturnContactName' => $storeInfo->getName(),
            'ReturnContactPhone' => $storeInfo->getPhone(),
            'ReturnAddress' => $storeFormattedAddress,
            'ReturnDistrictID' => $storeDistrict,
            'ExternalReturnCode' => $storeInfo->getName()
        ];

        $response = $this->makeRequest($config->getSynchronizingOrderUrl(), $data);

        if ($this->checkResponse($response)) {
            $order->setData('ghn_status', self::GHN_STATUS_SUCCESS);
            $order->setData('tracking_code', $response['response_object']['data']['OrderCode']);
        } else {
            $order->setData('ghn_status', self::GHN_STATUS_FAIL);
        }
    }
}
