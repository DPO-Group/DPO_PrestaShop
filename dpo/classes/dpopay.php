<?php
/*
 * Copyright (c) 2021 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the MIT License
 */


class dpopay
{
    const DPO_URL_TEST = 'https://secure1.sandbox.directpay.online';
    const DPO_URL_LIVE = 'https://secure.3gdirectpay.com';

    private $dpoUrl;
    private $dpoGateway;
    private $testMode;
    private $testText;
    private $companyToken;
    private $serviceType;

    public function __construct($testMode = false)
    {
        if ((int)$testMode == 1) {
            $this->dpoUrl       = self::DPO_URL_TEST;
            $this->testMode     = true;
            $this->testText     = 'teston';
            $this->companyToken = '9F416C11-127B-4DE2-AC7F-D5710E4C5E0A';
            $this->serviceType  = '3854';
        } else {
            $this->dpoUrl       = self::DPO_URL_LIVE;
            $this->testMode     = false;
            $this->testText     = 'liveon';
            $this->companyToken = Configuration::get('DPO_COMPANY_TOKEN');
            $this->serviceType  = Configuration::get('DPO_SERVICE_TYPE');
        }
        $this->dpoGateway = $this->dpoUrl . '/payv2.php';
    }

    public function getCompanyToken()
    {
        return $this->companyToken;
    }

    public function getServiceType()
    {
        return $this->serviceType;
    }

    public function getDpoGateway()
    {
        return $this->dpoGateway;
    }

    /**
     * Create a DPO token for payment processing
     *
     * @param $data
     *
     * @return array
     */
    public function createToken($data)
    {
        $compToken         = $data['companyToken'];
        $accountType       = $data['accountType'];
        $paymentAmount     = $data['paymentAmount'];
        $paymentCurrency   = $data['paymentCurrency'];
        $customerFirstName = $data['customerFirstName'];
        $customerLastName  = $data['customerLastName'];
        $customerAddress   = $data['customerAddress'];
        $customerCity      = $data['customerCity'];
        $customerPhone     = $data['customerPhone'];
        $redirectURL       = $data['redirectURL'];
        $backURL           = $data['backUrl'];
        $customerEmail     = $data['customerEmail'];
        $customerZip       = $data['customerZip'];
        $customerCountry   = $data['customerCountry'];
        $reference         = $data['companyRef'] . '_' . $this->testText;

        $odate   = date('Y/m/d H:i');
        $postXml = <<<POSTXML
        <?xml version="1.0" encoding="utf-8"?>
        <API3G>
            <CompanyToken>$compToken</CompanyToken>
            <Request>createToken</Request>
            <Transaction>
                <PaymentAmount>$paymentAmount</PaymentAmount>
                <PaymentCurrency>$paymentCurrency</PaymentCurrency>
                <CompanyRef>$reference</CompanyRef>
                <customerDialCode></customerDialCode>
                <customerZip>$customerZip</customerZip>
                <customerCountry>$customerCountry</customerCountry>
                <customerFirstName>$customerFirstName</customerFirstName>
                <customerLastName>$customerLastName</customerLastName>
                <customerAddress>$customerAddress</customerAddress>
                <customerCity>$customerCity</customerCity>
                <customerPhone>$customerPhone</customerPhone>
                <RedirectURL>$redirectURL</RedirectURL>
                <BackURL>$backURL</BackURL>
                <customerEmail>$customerEmail</customerEmail>
                <TransactionSource>prestashop</TransactionSource>
            </Transaction>
            <Services>
                <Service>
                    <ServiceType>$accountType</ServiceType>
                    <ServiceDescription>$reference</ServiceDescription>
                    <ServiceDate>$odate</ServiceDate>
                </Service>
            </Services>
        </API3G>
POSTXML;

        $curl = curl_init();
        curl_setopt_array(
            $curl,
            array(
                CURLOPT_URL            => $this->dpoUrl . "/API/v6/",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "POST",
                CURLOPT_POSTFIELDS     => $postXml,
                CURLOPT_HTTPHEADER     => array(
                    "cache-control: no-cache",
                ),
            )
        );

        $response = curl_exec($curl);
        $error    = curl_error($curl);
        if ($error) {
            var_dump($error);
        }

        curl_close($curl);

        if ($response != '') {
            $xml = new SimpleXMLElement($response);

            // Check if token was created successfully
            if ($xml->xpath('Result')[0] != '000') {
                exit();
            } else {
                $transToken        = $xml->xpath('TransToken')[0]->__toString();
                $result            = $xml->xpath('Result')[0]->__toString();
                $resultExplanation = $xml->xpath('ResultExplanation')[0]->__toString();
                $transRef          = $xml->xpath('TransRef')[0]->__toString();

                return [
                    'success'           => true,
                    'result'            => $result,
                    'transToken'        => $transToken,
                    'resultExplanation' => $resultExplanation,
                    'transRef'          => $transRef,
                ];
            }
        } else {
            header('Location: ' . $backURL);
            exit;
        }
    }

    /**
     * Verify the DPO token created in first step of transaction
     *
     * @param $data
     *
     * @return bool|string
     */
    public function verifyToken($data)
    {
        $compToken  = $data['companyToken'];
        $transToken = $data['transToken'];

        $curl = curl_init();
        curl_setopt_array(
            $curl,
            array(
                CURLOPT_URL            => $this->dpoUrl . "/API/v6/",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "POST",
                CURLOPT_POSTFIELDS     => "<?xml version=\"1.0\" encoding=\"utf-8\"?>\r\n<API3G>\r\n  <CompanyToken>" . $compToken . "</CompanyToken>\r\n  <Request>verifyToken</Request>\r\n  <TransactionToken>" . $transToken . "</TransactionToken>\r\n</API3G>",
                CURLOPT_HTTPHEADER     => array(
                    "cache-control: no-cache",
                ),
            )
        );

        $response = curl_exec($curl);
        $err      = curl_error($curl);

        curl_close($curl);

        if (strlen($err) > 0) {
            echo "cURL Error #:" . $err;
        } else {
            return $response;
        }
    }
}
