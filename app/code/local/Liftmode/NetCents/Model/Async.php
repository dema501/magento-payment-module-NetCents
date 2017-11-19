<?php
/**
 *
 * @category   Mage
 * @package    Liftmode_NetCents
 * @copyright  Copyright (c)  Dmitry Bashlov, contributors
 */

class Liftmode_NetCents_Model_Async extends Mage_Core_Model_Abstract
{

    private $_model;

    public function __construct()
    {
        parent::__construct();
        $this->_model = Mage::getModel('netcents/method_netCents');
    }

    /**
     * Poll Amazon API to receive order status and update Magento order.
     */
    public function syncOrderStatus(Mage_Sales_Model_Order $order, $isManualSync = false)
    {
        try {
            $data = $this->_model->_doGetStatus($order->getPayment());

            if (empty($data) === false && empty($data["approved"]) === false && (int) $data["approved"] === 1) {
                $this->putOrderOnProcessing($order);
            } else {
                $this->_model->log(array('comment' => 'Ive got Status', 'status' => $data));
                $this->putOrderOnHold($order, 'No transaction found, you should manually make invoice');
            }

        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Magento cron to sync Amazon orders
     */
    public function cron()
    {
        if ($this->_model->getConfigData('active') && $this->_model->getConfigData('async')) {
            $orderCollection = Mage::getModel('sales/order_payment')
                ->getCollection()
                ->join(array('order'=>'sales/order'), 'main_table.parent_id=order.entity_id', 'state')
                ->addFieldToFilter('method', $this->_model->_code)
                ->addFieldToFilter('state',  array('in' => array(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, Mage_Sales_Model_Order::STATE_PROCESSING)))
                ->addFieldToFilter('status', Mage_Index_Model_Process::STATUS_PENDING)
        ;

            $this->_model->log(array('run sql------>>>', $orderCollection->getSelect()->__toString()));

            foreach ($orderCollection as $orderRow) {
                $order = Mage::getModel('sales/order')->load($orderRow->getParentId());

                $this->_model->log(array('found order------>>>', 'IncrementId' => $order->getIncrementId(), 'Status' => $order->getStatus(), 'State' => $order->getState()));

                $this->syncOrderStatus($order);
            }
        }
    }

    public function putOrderOnProcessing(Mage_Sales_Model_Order $order)
    {
        $this->_model->log(array('putOrderOnProcessing------>>>', 'IncrementId' => $order->getIncrementId()));

        // Change order to "On Process"
        if ($order->canShip()) {
            // Save the payment changes
            try {
                $payment = $order->getPayment();
                $payment->setIsTransactionClosed(1);
                $payment->save();

                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING);
                $order->setStatus('processing');

                $order->addStatusToHistory($order->getStatus(), 'We recieved your payment, thank you!', true);
                $order->save();
            } catch (Exception $e) {
                $this->_model->log(array('putOrderOnProcessing---->>>>', $e->getMessage()));
            }
        }
    }

    public function putOrderOnHold(Mage_Sales_Model_Order $order, $reason)
    {
        $this->_model->log(array('putOrderOnHold------>>>', 'IncrementId' => $order->getIncrementId()));

        // Change order to "On Hold"
        try {
            $order->hold();
            $order->addStatusToHistory($order->getStatus(), $reason, false);
            $order->save();
        } catch (Exception $e) {
            $this->_model->log(array('putOrderOnHold---->>>>', $e->getMessage()));
        }
    }
}
