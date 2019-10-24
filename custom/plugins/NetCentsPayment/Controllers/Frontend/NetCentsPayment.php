<?php

use NetCentsPayment\Components\NetCentsPayment\PaymentResponse;
use NetCentsPayment\Components\NetCentsPayment\NetCentsPaymentService;
use Shopware\Components\Plugin\ConfigReader;
use Shopware\Components\CSRFWhitelistAware;

require_once __DIR__ . '/../../Components/netcents/init.php';

class Shopware_Controllers_Frontend_NetCentsPayment extends Shopware_Controllers_Frontend_Payment implements CSRFWhitelistAware
{
    private $pluginDirectory;
    private $config;

    const PAYMENTSTATUSPAID = 12;
    const PAYMENTSTATUSCANCELED = 17;
    const PAYMENTSTATUSPENDING = 18;
    const PAYMENTSTATUSREFUNDED = 20;

    public function preDispatch()
    {
        /** @var \Shopware\Components\Plugin $plugin */
        $plugin = $this->get('kernel')->getPlugins()['NetCentsPayment'];

        $this->get('template')->addTemplateDir($plugin->getPath() . '/Resources/views/');
    }

    public function indexAction()
    {
        switch ($this->getPaymentShortName()) {
            case 'cryptocurrency_payments_via_netcents':
                return $this->redirect(['action' => 'direct', 'forceSecure' => true]);
            default:
                return $this->redirect(['controller' => 'checkout']);
        }
    }

    /**
     * Direct action method.
     *
     * Collects the payment information and transmits it to the payment provider.
     */
    public function directAction()
    {
        $config = $this->container->get('shopware.plugin.cached_config_reader')->getByPluginName('NetCentsPayment');
        $router = $this->Front()->Router();

        $data = $this->getOrderData();
        $user = Shopware()->Session()->sOrderVariables['sUserData'];
        $order_id = $data[0]["orderID"];
        $shop = $this->getShopData();

        $payload = array(
            'external_id' => $order_id,
            'amount' => $this->getAmount(),
            'currency_iso' => $this->getCurrencyShortName(),
            'callback_url' => $router->assemble(['action' => 'return']) . '?external_id=' . $order_id,
            'first_name' => $user['billingaddress']['firstname'],
            'last_name' => $user['billingaddress']['lastname'],
            'email' => $user['additional']['user']['email'],
            'webhook_url' => $router->assemble(['action' => 'webhook101010']),
            'merchant_id' => $config['NetCentsCredentialsApiKey'],
            'hosted_payment_id' => $config['NetCentsWebPluginId'],
            'data_encryption' => array(
                'external_id' => $order_id,
                'amount' => $this->getAmount(),
                'currency_iso' => $this->getCurrencyShortName(),
                'callback_url' => $router->assemble(['action' => 'return']) . '?external_id=' . $order_id,
                'first_name' => $user['billingaddress']['firstname'],
                'last_name' => $user['billingaddress']['lastname'],
                'email' => $user['additional']['user']['email'],
                'webhook_url' => $router->assemble(['action' => 'webhook101010']),
                'merchant_id' => $config['NetCentsCredentialsApiKey'],
                'hosted_payment_id' => $config['NetCentsWebPluginId'],
            )
        );

        $api_url = $this->nc_get_api_url($config['NetCentsApiUrl']);
        $response = \NetCents\NetCents::request(
            $api_url . '/widget/v2/encrypt',
            $payload,
            $config['NetCentsCredentialsApiKey'],
            $config['NetCentsCredentialsSecretKey']
        );

        $token = $response['token'];

        if ($token) {
            $this->redirect($config['NetCentsApiUrl'] . "/widget/merchant/widget?data=" . $token);
        } else {
            error_log(print_r(array($order), true) . "\n", 3, Shopware()->DocPath() . '/error.log');
        }
    }

    public function nc_get_api_url($host_url)
    {
        $parsed = parse_url($host_url);
        if ($host_url == 'https://merchant.net-cents.com') {
            $api_url = 'https://api.net-cents.com';
        } else if ($host_url == 'https://gateway-staging.net-cents.com') {
            $api_url = 'https://api-staging.net-cents.com';
        } else if ($host_url == 'https://gateway-test.net-cents.com') {
            $api_url = 'https://api-test.net-cents.com';
        } else {
            $api_url = $parsed['scheme'] . '://' . 'api.' . $parsed['host'];
        }
        return $api_url;
    }

    public function returnAction()
    {
        $id = $this->Request()->getParam('external_id');

        $transaction_id = 'NETCENTS' . $id;
        $md5_id = md5($id);

        $this->saveOrder(
            $transaction_id,
            $md5_id,
            self::PAYMENTSTATUSPENDING
        );

        $this->redirect(['controller' => 'checkout', 'action' => 'finish']);
    }

    public function webhook101010Action()
    {
        Shopware()->Plugins()->Controller()->ViewRenderer()->setNoRender();

        $data = $this->Request()->getPost('data');

        $decoded_data = json_decode(base64_decode(urldecode($data)));

        $signature = $this->Request()->getPost('signature');

        $signing = $this->Request()->getPost('signing');

        $external_id = $decoded_data->external_id;

        $result = $this->checkPayment($signature, $data, $signing);

        if ($result) {
            switch ($decoded_data->transaction_status) {
                case 'paid':
                    $order_status = self::PAYMENTSTATUSPAID;
                    break;
                case 'overpaid':
                    $order_status = 21;
                    break;
                case 'underpaid':
                    $order_status = 21;
                    break;
                default:
                    $order_status = false;
            }
            if ($order_status) {
                $this->savePaymentStatus('NETCENTS' . $external_id, md5($external_id), $order_status);
            }
        }
    }

    public function successAction($args)
    {
        return $this->forward('finish', 'checkout', null, array('sUniqueID' => $this->Request()->get('external_id')));
    }


    public function cancelAction()
    { }

    public function createPaymentToken($amount, $customerId)
    {
        return md5(implode('|', [$amount, $customerId]));
    }

    public function getWhitelistedCSRFActions()
    {
        return array(
            'callback',
            'webhook101010'
        );
    }

    private function checkPayment($signature, $data, $signing)
    {
        $exploded_parts = explode(",", $signature);
        $timestamp = explode("=", $exploded_parts[0])[1];
        $signature = explode("=", $exploded_parts[1])[1];
        $hashable_payload = $timestamp . '.' . $data;
        $hash_hmac = hash_hmac("sha256", $hashable_payload, $signing);
        $timestamp_tolerance = 60;
        $date = new DateTime();
        $current_timestamp = $date->getTimestamp();
        if ($hash_hmac === $signature) {
            return true;
        }
        return false;
    }

    private function getOrderData()
    {
        $queryBuilder = $this->container->get('dbal_connection')->createQueryBuilder();
        $queryBuilder->select('*')
            ->from('s_order_attributes');
        $data = $queryBuilder->execute()->fetchAll();
        $last_order = array_values(array_slice($data, -1));

        return $last_order;
    }

    private function getShopData()
    {
        $queryBuilder = $this->container->get('dbal_connection')->createQueryBuilder();
        $queryBuilder->select('*')
            ->from('s_core_shops');
        $data = $queryBuilder->execute()->fetchAll();

        return $data;
    }

    private function getPluginVersion()
    {
        $plugin = $this->get('kernel')->getPlugins()['NetCentsPayment'];
        $xml = simplexml_load_file($plugin->getPath() . "/plugin.xml") or die("Error parsing plugin.xml");

        return $xml->version;
    }

    private function insertOrderID($id)
    {
        /** @var \Shopware\Bundle\AttributeBundle\Service\CrudService $service */
        $service = $this->get('shopware_attribute.crud_service');
        $service->update('s_order_attributes', 'netcents_callback_order_id', 'text');
        $queryBuilder = $this->container->get('dbal_connection')->createQueryBuilder();
        $queryBuilder
            ->insert('s_order_attributes')
            ->values(
                array(
                    'netcents_callback_order_id' => $id,
                )
            );
        $data = $queryBuilder->execute();
    }

    private function userAgent()
    {
        $netcents_version = $this->getPluginVersion();
        return $agent = 'Shopware v' . Shopware::VERSION . ' NetCents Extension v' . $netcents_version[0]["version"];
    }
}
