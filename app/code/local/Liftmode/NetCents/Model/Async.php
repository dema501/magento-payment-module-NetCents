<?php
/**
 *
 * @category   Mage
 * @package    Liftmode_NetCents
 * @copyright  Copyright (c)  Dmitry Bashlov, contributors
 */

class Liftmode_NetCents_Model_Async extends Mage_Core_Model_Abstract
{
    /**
     * Poll Amazon API to receive order status and update Magento order.
     */
    public function syncOrderStatus(Mage_Sales_Model_Order $order, $isManualSync = false)
    {
        try {
            $netCents = Mage::getModel('netcents/method_netCents');

            $data = $netCents->_doGetStatus($order->getPayment());

            if (!empty($data["status"]) && ((int) substr($data["status"], 0, 1) !== 2) ) {
                $this->putOrderOnProcessing($order);
            } else {
                $this->putOrderOnHold($order, 'No transaction found, you should manually make invoice');
            }

        } catch (Exception $e) {
//            $this->putOrderOnHold($order);
            Mage::logException($e);
        }
    }

    /**
     * Magento cron to sync Amazon orders
     */
    public function cron()
    {
        if(Mage::getStoreConfig('payment/netcents/async')) {
            $orderCollection = Mage::getModel('sales/order_payment')
                ->getCollection()
                ->join(array('order'=>'sales/order'), 'main_table.parent_id=order.entity_id', 'state')
                ->addFieldToFilter('method', 'netcents')
                ->addFieldToFilter('state',  array('in' => array(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, Mage_Sales_Model_Order::STATE_PROCESSING)))
                ->addFieldToFilter('status', Mage_Index_Model_Process::STATUS_PENDING)
        ;

        echo $orderCollection->getSelect()->__toString();
            foreach ($orderCollection as $orderRow) {
                $order = Mage::getModel('sales/order')->load($orderRow->getParentId());

                Mage::log(array('found orders------>>>', $order->getIncrementId(), $order->getStatus(), $order->getState()), null, 'NetCents.log');

                $this->syncOrderStatus($order);
            }
        }
    }

    public function putOrderOnProcessing(Mage_Sales_Model_Order $order)
    {
        Mage::log(array('putOrderOnProcessing------>>>', $order->canShip()), null, 'NetCents.log');

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

                Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('netcents')->__('We recieved your payment for order id: %s. Order was paid by NetCents', $order->getIncrementId()));
            } catch (Exception $e) {
                Mage::log(array('putOrderOnProcessing---->>>>', $e->getMessage()), null, 'NetCents.log');
            }
        }
    }

    public function putOrderOnHold(Mage_Sales_Model_Order $order, $reason)
    {
        Mage::log(array('putOrderOnHold------>>>', $order->getIncrementId()), null, 'NetCents.log');

        // Change order to "On Hold"
        try {
            $order->hold();
            $order->addStatusToHistory($order->getStatus(), $reason, false);
            $order->save();
        } catch (Exception $e) {
            Mage::log(array('putOrderOnProcessing---->>>>', $e->getMessage()), null, 'NetCents.log');
        }
    }
}
