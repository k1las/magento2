<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Controller\Adminhtml\Index;

use Magento\Customer\Ui\Component\Listing\AttributeRepository;
use Magento\Customer\Api\Data\CustomerInterface;

class InlineEdit extends \Magento\Customer\Controller\Adminhtml\Index
{
    /** @var CustomerInterface */
    private $customer;

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        /** @var \Magento\Framework\Controller\Result\Json $resultJson */
        $resultJson = $this->resultJsonFactory->create();

        $postItems = $this->getRequest()->getParam('items', []);
        if (!($this->getRequest()->getParam('isAjax') && count($postItems))) {
            return $resultJson->setData([
                'messages' => [__('Please correct the data sent.')],
                'error' => true,
            ]);
        }

        foreach (array_keys($postItems) as $customerId) {
            $this->setCustomer($this->_customerRepository->getById($customerId));
            if ($this->getCustomer()->getDefaultBilling()) {
                $this->updateDefaultBilling($this->getData($postItems[$customerId]));
            } else {
                $this->addNewBilling($this->getData($postItems[$customerId]));
            }
            $this->updateCustomer($this->getData($postItems[$customerId], true));
            $this->saveCustomer($this->getCustomer());
        }

        return $resultJson->setData([
            'messages' => $this->getErrorMessages(),
            'error' => $this->isErrorExists()
        ]);
    }

    /**
     * Receive entity(customer|customer_address) data from request
     *
     * @param array $data
     * @param null $isCustomerData
     * @return array
     */
    protected function getData(array $data, $isCustomerData = null)
    {
        $addressKeys = preg_grep(
            '/^(' . AttributeRepository::BILLING_ADDRESS_PREFIX . '\w+)/',
            array_keys($data),
            $isCustomerData
        );
        $result = array_intersect_key($data, array_flip($addressKeys));
        if ($isCustomerData === null) {
            foreach ($result as $key => $value) {
                if (strpos($key, AttributeRepository::BILLING_ADDRESS_PREFIX) !== false) {
                    unset($result[$key]);
                    $result[str_replace(AttributeRepository::BILLING_ADDRESS_PREFIX, '', $key)] = $value;
                }
            }
        }
        return $result;
    }

    /**
     * Update customer data
     *
     * @param array $data
     * @return void
     */
    protected function updateCustomer(array $data)
    {
        $customer = $this->getCustomer();
        $customerData = array_merge(
            $this->customerMapper->toFlatArray($customer),
            $data
        );
        $this->dataObjectHelper->populateWithArray(
            $customer,
            $customerData,
            '\Magento\Customer\Api\Data\CustomerInterface'
        );
    }

    /**
     * Update customer address data
     *
     * @param array $data
     * @return void
     */
    protected function updateDefaultBilling(array $data)
    {
        $addresses = $this->getCustomer()->getAddresses();
        /** @var \Magento\Customer\Api\Data\AddressInterface $address */
        foreach ($addresses as $address) {
            if ($address->isDefaultBilling()) {
                $addressData = array_merge(
                    $this->addressMapper->toFlatArray($address),
                    $this->processAddressData($data)
                );
                $this->dataObjectHelper->populateWithArray(
                    $address,
                    $addressData,
                    '\Magento\Customer\Api\Data\AddressInterface'
                );
                break;
            }
        }
    }

    /**
     * Add new address to customer
     *
     * @param array $data
     * @return void
     */
    protected function addNewBilling(array $data)
    {
        $customer = $this->getCustomer();
        /** @var \Magento\Customer\Api\Data\AddressInterface $address */
        $address = $this->addressDataFactory->create();
        $this->dataObjectHelper->populateWithArray(
            $address,
            $this->processAddressData($data),
            '\Magento\Customer\Api\Data\AddressInterface'
        );
        $address->setCustomerId($customer->getId());
        $address->setIsDefaultBilling(true);
        $this->addressRepository->save($address);
        $customer->setAddresses(array_merge($customer->getAddresses(), [$address]));
    }

    /**
     * Save customer with error catching
     *
     * @param CustomerInterface $customer
     * @return void
     */
    protected function saveCustomer(CustomerInterface $customer)
    {
        try {
            $this->_customerRepository->save($customer);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->getMessageManager()->addError($this->getErrorWithCustomerId($e->getMessage()));
            $this->logger->critical($e);
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($this->getErrorWithCustomerId('We can\'t save the customer.'));
            $this->logger->critical($e);
        }
    }

    /**
     * Parse street field
     *
     * @param array $data
     * @return array
     */
    protected function processAddressData(array $data)
    {
        foreach (['firstname', 'lastname'] as $requiredField) {
            if (empty($data[$requiredField])) {
                $data[$requiredField] =  $this->getCustomer()->{'get' . ucfirst($requiredField)}();
            }
        }
        return $data;
    }

    /**
     * Get array with errors
     *
     * @return array
     */
    protected function getErrorMessages()
    {
        $messages = [];
        foreach ($this->getMessageManager()->getMessages()->getItems() as $error) {
            $messages[] = $error->getText();
        }
        return $messages;
    }

    /**
     * Check if errors exists
     *
     * @return bool
     */
    protected function isErrorExists()
    {
        return (bool)$this->getMessageManager()->getMessages(true)->getCount();
    }

    /**
     * Set customer
     *
     * @param CustomerInterface $customer
     * @return $this
     */
    protected function setCustomer(CustomerInterface $customer)
    {
        $this->customer = $customer;
        return $this;
    }

    /**
     * Receive customer
     *
     * @return CustomerInterface
     */
    protected function getCustomer()
    {
        return $this->customer;
    }

    /**
     * Add page title to error message
     *
     * @param string $errorText
     * @return string
     */
    protected function getErrorWithCustomerId($errorText)
    {
        return '[Customer ID: ' . $this->getCustomer()->getId() . '] ' . __($errorText);
    }
}
