<?php

/*
 * Copyright (c) 2021 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

class DpoPaymentModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        require_once _PS_MODULE_DIR_ . $this->module->name . '/classes/dpopay.php';

        $testmode = Tools::getValue('DPO_TESTMODE', Configuration::get('DPO_TESTMODE'));
        $dpopay   = new dpopay($testmode);

        // Buyer details
        $customer     = new Customer((int)($this->context->cart->id_customer));
        $user_address = new Address(intval($this->context->cart->id_address_invoice));

        $total  = $this->context->cart->getOrderTotal();
        $amount = filter_var(
            $total,
            FILTER_SANITIZE_NUMBER_FLOAT,
            FILTER_FLAG_ALLOW_THOUSAND | FILTER_FLAG_ALLOW_FRACTION
        );

        $currency = new Currency((int)$this->context->cart->id_currency);

        if ($this->context->cart->id_currency != $currency->id) {
            // If DPO currency differs from local currency
            $this->context->cart->id_currency = (int)$currency->id;
            $cookie->id_currency              = (int)$this->context->cart->id_currency;
            $cart->update();
        }

        $dateTime                          = new DateTime();
        $time                              = $dateTime->format('YmdHis');
        $this->context->cookie->order_time = $time;
        $this->context->cookie->cart_id    = $this->context->cart->id;
        $reference                         = filter_var($this->context->cart->id . '_' . $time, FILTER_SANITIZE_STRING);
        $this->context->cookie->reference  = $reference;
        $currency                          = filter_var($currency->iso_code, FILTER_SANITIZE_STRING);
        $returnUrl                         = filter_var(
            $this->context->link->getModuleLink(
                $this->module->name,
                'confirmation',
                ['key' => $this->context->cart->secure_key],
                true
            ),
            FILTER_SANITIZE_URL
        );
        $country                           = new Country();
        $country_code                      = $country->getIsoById($user_address->id_country);

        $data                      = [];
        $data['companyToken']      = $dpopay->getCompanyToken();
        $data['accountType']       = $dpopay->getServiceType();
        $data['paymentAmount']     = $amount;
        $data['paymentCurrency']   = $currency;
        $data['customerFirstName'] = $customer->firstname;
        $data['customerLastName']  = $customer->lastname;
        $data['customerAddress']   = $user_address->address1 . '_' . $user_address->address2;
        $data['customerCity']      = $user_address->city;
        $data['customerPhone']     = str_replace(['+', '-', '(', ')'], '', $user_address->phone);
        $data['redirectURL']       = $returnUrl;
        $data['backUrl']           = Tools::getHttpHost() . __PS_BASE_URI__ . 'order';
        $data['customerEmail']     = $customer->email;
        $data['customerZip']       = $user_address->postcode;
        $data['customerCountry']   = $country_code;
        $data['companyRef']        = $reference;

        $tokens = $dpopay->createToken($data);
        if ($tokens['success'] === true) {
            $data['transToken'] = $tokens['transToken'];

            $verified = null;

            while ($verified === null) {
                $verify = $dpopay->verifyToken($data);

                if ( ! empty($verify) && $verify != '') {
                    $verify = new SimpleXMLElement($verify);
                    if ($verify->Result->__toString() === '900') {
                        $verified = true;
                        $payUrl   = $dpopay->getDpoGateway() . '?ID=' . $data['transToken'];
                        header('Location: ' . $payUrl);
                        exit;
                    }
                }
            }
        } else {
            //Tokens not created
            header('Location: ' . $data['backUrl']);
            exit;
        }
    }
}
