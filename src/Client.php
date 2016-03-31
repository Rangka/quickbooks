<?php
namespace Rangka\Quickbooks;

use GuzzleHttp\Client as Guzzle;
use Rangka\Quickbooks\Builders\Builder;

class Client {
    /**
     * API Base Url
     *
     * * @var string
     */
    const URL_API_BASE = 'https://sandbox-quickbooks.api.intuit.com/v3/company';

    /**
     * Hold's QuickBooks' Consumer Key.
     *
     * * @var string
     */
    protected static $consumer_key;

    /**
     * Holds QuickBook's Consume Secret. 
     *
     * * @var string
     */
    protected static $consumer_secret;

    /**
     * Hold's QuickBooks' OAuth Token.
     *
     * * @var string
     */
    protected static $oauth_token;

    /**
     * Holds QuickBook's OAuth Token Secret. 
     *
     * * @var string
     */
    protected static $oauth_token_secret;

    /**
     * Holds QuickBook's Company ID (previously known as realm). 
     *
     * * @var string
     */
    protected static $company_id;

    /**
     * Construct a new client.
     * 
     * @return void
     */
    public function __construct() {
    }

    /**
    * Configure Client's tokens.
    *
    * @param    array   $params     Array of `oauth_token`, `oauth_token_secret` and `company_id`
    * @return void
    */
    public static function configure($options) {
        self::$consumer_key       = getenv('QUICKBOOKS_CONSUMER_KEY') ?: $options['consumer_key'];
        self::$consumer_secret    = getenv('QUICKBOOKS_CONSUMER_SECRET') ?: $options['consumer_secret'];
        self::$oauth_token        = isset($options['oauth_token']) ? $options['oauth_token'] : '';
        self::$oauth_token_secret = isset($options['oauth_token_secret']) ? $options['oauth_token_secret'] : '';
        self::$company_id         = isset($options['company_id']) ? $options['company_id'] : '';
    }

    /**
    * Sign a request
    * 
    * @param string     $url        Endpoint
    * @param array      $params     Array of parameters to be signed.
    * @param string     $secret     Token secret to be appended to consumer secret for signing.
    * @return array                 string - base string to be signed, 
    *                               url    - signed URL (null if QuickBooks has been connected)
    *                               header - Authorization header (null if QuickBooks has not been connected)
    */
    protected function sign($method, $url, $params = []) {
        // parse URL
        $parsedURL = parse_url($url);

        // reconstruct it with only what we need
        $url = $parsedURL['scheme'] . '://' . $parsedURL['host'] . $parsedURL['path'];

        // set default parameters and sort it by key
        $params = array_merge([
            'oauth_consumer_key'     => self::$consumer_key,
            'oauth_nonce'            => substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 10),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => time(),
            'oauth_version'          => '1.0'
        ], $params);
        ksort($params);

        // generate string to be signed
        $string = $method . '&' . rawurlencode($url) . '&' . rawurlencode(http_build_query($params) . (isset($parsedURL['query']) ? '&' . $parsedURL['query'] : ''));

        // calculate signature
        $params['oauth_signature'] = base64_encode(hash_hmac('sha1', $string, rawurlencode(self::$consumer_secret) . '&' . rawurlencode(self::$oauth_token_secret), true));

        // generate auth header if oauth_token is present but without verifier (actual API request and not authorization process)
        $header = $signed_url = '';
        if (isset($params['oauth_token']) && !isset($params['oauth_verifier'])) {
            $header = 'OAuth ' . implode(', ', [
                'oauth_signature_method="'.$params['oauth_signature_method'].'"',
                'oauth_signature="'.rawurlencode($params['oauth_signature']).'"',
                'oauth_nonce="'.$params['oauth_nonce'].'"',
                'oauth_timestamp="'.$params['oauth_timestamp'].'"',
                'oauth_token="'.$params['oauth_token'].'"',
                'oauth_consumer_key="'.$params['oauth_consumer_key'].'"',
                'oauth_version="'.$params['oauth_version'].'"'
            ]);
        } else {
            // build URL for signed URL request
            $signed_url = $url . '?' . http_build_query($params);
        }

        // return all results
        return [
            'string' => $string,
            'url'    => $signed_url,
            'header' => $header
        ];
    }

    /**
    * Request from Quickbooks.
    * 
    * @return 
    */
    public function request($method, $url, $body = []) {
        $url      = trim($url, '/');
        $base_uri = self::URL_API_BASE . '/' . self::$company_id . '/';
        $full_url = $base_uri . $url;
        $signed   = $this->sign($method, $full_url, [
            'oauth_token' => self::$oauth_token
        ]);

        $response = (new Guzzle([
            'base_uri' => $base_uri,
            'headers' => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'Authorization' => $signed['header']
            ],
            'json' => $body instanceof Builder ? $body->toArray() : $body
        ]))->request($method, $url);

        return json_decode((string) $response->getBody());
    }

    /**
    * Make a GET request.
    * 
    * @return void
    */
    public function get($url, $body = []) {
        return $this->request('GET', $url, $body);
    }

    /**
    * Make a POST request.
    * 
    * @return void
    */
    public function post($url, $body = []) {
        return $this->request('POST', $url, $body);
    }
}