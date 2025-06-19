<?php

/*
 * Copyright (c) 2025 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

use Dpo\Common\Dpo;

require_once __DIR__ . '/../../vendor/autoload.php';

class DpoConfirmationModuleFrontController extends ModuleFrontController
{
    /**
     * @throws PrestaShopException
     * @throws Exception
     */
    public function initContent(): void
    {
        if (isset($_GET['TransactionToken'])) {
            parent::initContent();

            $dpopay           = new Dpo(false);
            $transToken       = $_GET['TransactionToken'];
            $companyReference = substr($_GET['CompanyRef'], 0, -7);

            $data                 = [];
            $data['transToken']   = $transToken;
            $data['companyToken'] = Configuration::get('DPO_COMPANY_TOKEN');
            $cartid               = substr($companyReference, 0, strpos($companyReference, '_'));

            $status_data = $this->getStatusValue($dpopay, $data);
            $verify      = $status_data['verify'];
            $status      = $status_data['status'];

            if ($this->context->cookie->cart_id == $cartid) {
                $cart       = new Cart($this->context->cookie->cart_id);
                $keys_match = $cart->secure_key === $_GET['key'];

                $order = Order::getByCartId($cart->id);

                // Check to see if there is already an order for this cart - it may have been created by notify (validate.php)
                if ($order && $order->hasBeenPaid()) {
                    Tools::redirect(
                        $this->context->link->getPageLink(
                            'order-confirmation',
                            null,
                            null,
                            'key=' . $cart->secure_key . '&id_cart=' . (int)($cartid) . '&id_module=' . (int)($this->module->id)
                        )
                    );
                }

                // Fail the transaction if the CompanyRef between the GET query and the verify data does not match
                $status = $this->checkCompanyRef($verify, $status);

                if ($keys_match) {
                    switch ($status) {
                        case 1:
                            // Update the purchase status
                            $transactionAmount = (float)$verify->TransactionAmount->__toString();
                            $method_name       = $this->module->displayName;
                            $transactionId     = $verify->ApprovalNumber->__toString();

                            if (!$order) {
                                $this->module->validateOrder(
                                    (int)$cartid,
                                    _PS_OS_PAYMENT_,
                                    $transactionAmount,
                                    $method_name,
                                    null,
                                    array('transaction_id' => $transactionId),
                                    null,
                                    false,
                                    $cart->secure_key
                                );
                            } else {
                                if (!$order->hasBeenPaid()) {
                                    $order->addOrderPayment(
                                        (string)$transactionAmount,
                                        $method_name,
                                        $transactionId
                                    );
                                }
                            }

                            Tools::redirect(
                                $this->context->link->getPageLink(
                                    'order-confirmation',
                                    null,
                                    null,
                                    'key=' . $cart->secure_key . '&id_cart=' . (int)($cart->id) . '&id_module=' . (int)($this->module->id)
                                )
                            );
                            break;

                        case '2':
                            $status = 2;
                            break;

                        case '4':
                            $status = 4;
                            break;

                        default:
                            break;
                    }
                }
            }

            $this->context->smarty->assign('status', $status);

            $this->setTemplate('module:dpo/views/templates/front/confirmation.tpl');
        }
    }

    /**
     * @throws Exception
     */
    public function getStatusValue($dpopay, $data): array
    {
        $status      = null;
        $status_data = array();
        while ($status === null) {
            $verify = $dpopay->verifyToken($data);
            if ($verify != '') {
                $verify                = new SimpleXMLElement($verify);
                $status                = match ($verify->Result->__toString()) {
                    '000' => 1,
                    '901' => 2,
                    default => 4,
                };
                $status_data['verify'] = $verify;
            }
        }
        $status_data['status'] = $status;

        return $status_data;
    }

    /**
     * @param mixed $verify
     * @param int $status
     *
     * @return int
     */
    public function checkCompanyRef(mixed $verify, int $status): int
    {
        if ($_GET['CompanyRef'] != $verify->CompanyRef->__toString()) {
            $status = 2;
        }

        return $status;
    }
}
