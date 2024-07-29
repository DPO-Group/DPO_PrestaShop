<?php

/*
 * Copyright (c) 2024 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

use Dpo\Common\Dpo;

require_once __DIR__ . '/../../vendor/autoload.php';

class DpoConfirmationModuleFrontController extends ModuleFrontController
{

    protected static function dpoQuery($data)
    {
        $receivedSum = $data['CHECKSUM'];
        unset($data['CHECKSUM']);
        $key           = Configuration::get('DPO_SERVICE_TYPE');
        $calculatedSum = md5(implode('', $data) . $key);

        if ($receivedSum !== $calculatedSum) {
            return false;
        }

        unset($data['TRANSACTION_STATUS']);
        $checksum         = md5(implode('', $data) . $key);
        $data['CHECKSUM'] = $checksum;

        $url      = 'https://secure.dpo.co.za/payweb3/query.trans';
        $response = self::doCurl($url, $data);

        if ($response['error']) {
            return false;
        }

        return $response['response'];
    }

    protected static function doCurl($url, $data)
    {
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => "",
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => "POST",
            CURLOPT_HTTPHEADER     => array(
                "cache-control: no-cache",
            ),
        ];

        $curlOpts[CURLOPT_POSTFIELDS] = http_build_query($data, '', '&');

        $curl = curl_init($url);
        curl_setopt_array($curl, $curlOpts);
        $response = curl_exec($curl);
        $error    = curl_error($curl);
        curl_close($curl);

        if (strlen($error) > 0) {
            return ['error' => true, 'error' => $error];
        } else {
            $data      = [];
            $responses = explode('&', $response);
            foreach ($responses as $r) {
                $i           = explode('=', $r);
                $data[$i[0]] = $i[1];
            }

            return ['error' => false, 'response' => $data];
        }
    }

    public function initContent()
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
                $keys_match = ($cart->secure_key === $_GET['key']
                ) ? true : false;

                // Fail the transaction if the CompanyRef between the GET query and the verify data does not match
                $status = $this->checkCompanyRef($verify, $status);

                if ($keys_match) {
                    switch ($status) {
                        case 1:
                            // Update the purchase status
                            $transactionAmount    = (float)$verify->TransactionAmount->__toString();
                            $allocationAmount     = (float)$verify->AllocationAmount->__toString();
                            $amountWithoutCharges = (string)($transactionAmount - $allocationAmount);
                            $method_name          = $this->module->displayName;
                            /** @noinspection PhpUndefinedConstantInspection */
                            $this->module->validateOrder(
                                $cartid,
                                _PS_OS_PAYMENT_,
                                $amountWithoutCharges,
                                $method_name,
                                null,
                                array('transaction_id' => $verify->ApprovalNumber->__toString()),
                                null,
                                false,
                                $cart->secure_key
                            );

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

    public function getStatusValue($dpopay, $data)
    {
        $status      = null;
        $status_data = array();
        while ($status === null) {
            $verify = $dpopay->verifyToken($data);
            if ($verify != '') {
                $verify = new SimpleXMLElement($verify);
                switch ($verify->Result->__toString()) {
                    case '000':
                        $status = 1;
                        break;
                    case '901':
                        $status = 2;
                        break;
                    case '904':
                    default:
                        $status = 4;
                        break;
                }
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
