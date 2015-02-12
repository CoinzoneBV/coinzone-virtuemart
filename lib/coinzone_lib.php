<?php

/**
 * Class Coinzone
 */
class Coinzone
{
    /**
     * Coinzone API URL
     */
    const API_URL = 'https://api.coinzone.com/v2/';

    /**
     * @var string
     */
    private $clientCode;

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var array
     */
    private $headers;

    /**
     * @param $clientCode
     * @param $apiKey
     */
    public function __construct($clientCode, $apiKey)
    {
        $this->clientCode = $clientCode;
        $this->apiKey = $apiKey;
    }

    /**
     * Set headers and sign the request
     * @param $path
     * @param array $payload
     */
    private function prepareRequest($path, array $payload)
    {
        $timestamp = time();
        if (!empty($payload)) {
            $payload = json_encode($payload);
        }
        $stringToSign = $payload . self::API_URL.$path . $timestamp;
        $signature = hash_hmac('sha256', $stringToSign, $this->apiKey);

        $this->headers = array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
            'clientCode: ' . $this->clientCode,
            'timestamp: ' . $timestamp,
            'signature: ' . $signature
        );
    }

    /**
     * API Call
     * @param $path
     * @param $payload
     * @return mixed|string
     */
    public function callApi($path, $payload = '')
    {
        $this->prepareRequest($path, $payload);

        $url = self::API_URL . $path;
        $curlHandler = curl_init($url);
        curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandler, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($curlHandler, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curlHandler, CURLOPT_SSL_VERIFYPEER, false);

        if (!empty($payload)) {
            curl_setopt($curlHandler, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($curlHandler, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $result = curl_exec($curlHandler);
        if ($result === false) {
            return false;
        }
        $response = json_decode($result);
        curl_close($curlHandler);

        return $response;
    }
}
