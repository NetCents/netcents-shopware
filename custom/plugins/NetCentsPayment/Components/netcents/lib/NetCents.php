<?php
namespace NetCents;

class NetCents
{
    const VERSION           = '3.0.0';
    const USER_AGENT_ORIGIN = 'NetCents PHP Library';

    public static function request($url, $params = array(), $api_key, $secret)
    {

        $curl      = curl_init();
        $curl_options = array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL            => $url
        );

        $header = array();
        $header[] = 'Content-Type: application/x-www-form-urlencoded';

        array_merge($curl_options, array(CURLOPT_POST => 1));
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));

        curl_setopt_array($curl, $curl_options);
        curl_setopt($curl, CURLOPT_USERPWD, $api_key . ':' . $secret);

        $response    = curl_exec($curl);

        $decoded_json = json_decode($response, TRUE);

        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($http_status === 200)
            return $decoded_json;
        else
            \NetCents\Exception::throwException($http_status, $response);
    }
}
