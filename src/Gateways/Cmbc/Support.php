<?php

namespace Yansongda\Pay\Gateways\Cmbc;

use Exception;
use Yansongda\Pay\Events;
use Yansongda\Pay\Exceptions\BusinessException;
use Yansongda\Pay\Exceptions\GatewayException;
use Yansongda\Pay\Exceptions\InvalidArgumentException;
use Yansongda\Pay\Exceptions\InvalidSignException;
use Yansongda\Pay\Gateways\Cmbc;
use Yansongda\Pay\Gateways\Wechat;
use Yansongda\Pay\Log;
use Yansongda\Supports\Collection;
use Yansongda\Supports\Config;
use Yansongda\Supports\Str;
use Yansongda\Supports\Traits\HasHttpRequest;

/**
 * @author yansongda <me@yansongda.cn>
 *
 * @property string appid
 * @property string app_id
 * @property string miniapp_id
 * @property string sub_appid
 * @property string sub_app_id
 * @property string sub_miniapp_id
 * @property string mch_id
 * @property string sub_mch_id
 * @property string key
 * @property string return_url
 * @property string cert_client
 * @property string cert_key
 * @property array log
 * @property array http
 * @property string mode
 */
class Support
{
    use HasHttpRequest;

    /**
     * Wechat gateway.
     *
     * @var string
     */
    protected $baseUri;

    /**
     * Config.
     *
     * @var Config
     */
    protected $config;

    /**
     * Instance.
     *
     * @var Support
     */
    private static $instance;

    /**
     * Bootstrap.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param Config $config
     */
    private function __construct(Config $config)
    {
        $this->baseUri = Cmbc::URL[$config->get('mode', Cmbc::MODE_NORMAL)];
        $this->config = $config;

        $this->setHttpOptions();
    }

    /**
     * __get.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param $key
     *
     * @return mixed|null|Config
     */
    public function __get($key)
    {
        return $this->getConfig($key);
    }

    /**
     * create.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param Config $config
     *
     * @throws GatewayException
     * @throws InvalidArgumentException
     * @throws InvalidSignException
     *
     * @return Support
     */
    public static function create(Config $config)
    {
        if (php_sapi_name() === 'cli' || !(self::$instance instanceof self)) {
            self::$instance = new self($config);
        }

        return self::$instance;
    }

    /**
     * getInstance.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws InvalidArgumentException
     *
     * @return Support
     */
    public static function getInstance()
    {
        if (is_null(self::$instance)) {
            throw new InvalidArgumentException('You Should [Create] First Before Using');
        }

        return self::$instance;
    }

    /**
     * clear.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @return void
     */
    public static function clear()
    {
        self::$instance = null;
    }

    /**
     * Request wechat api.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $endpoint
     * @param array  $data
     * @param bool   $cert
     *
     * @throws GatewayException
     * @throws InvalidArgumentException
     * @throws InvalidSignException
     *
     * @return Collection
     */
    public static function requestApi($endpoint, $data, $cert = false): Collection
    {
        Events::dispatch(Events::API_REQUESTING, new Events\ApiRequesting('Wechat', '', self::$instance->getBaseUri().$endpoint, $data));

        $result = self::$instance->post(
            $endpoint,
            self::toXml($data),
            $cert ? [
                'cert'    => self::$instance->cert_client,
                'ssl_key' => self::$instance->cert_key,
            ] : []
        );
        $result = is_array($result) ? $result : self::fromXml($result);

        Events::dispatch(Events::API_REQUESTED, new Events\ApiRequested('Wechat', '', self::$instance->getBaseUri().$endpoint, $result));

        return self::processingApiResult($endpoint, $result);
    }

    /**
     * Filter payload.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array        $payload
     * @param array|string $params
     * @param bool         $preserve_notify_url
     *
     * @throws InvalidArgumentException
     *
     * @return array
     */
    public static function filterPayload($payload, $params, $preserve_notify_url = false): array
    {
        $type = self::getTypeName($params['type'] ?? '');

        $payload = array_merge(
            $payload,
            is_array($params) ? $params : ['out_trade_no' => $params]
        );
        $payload['appid'] = self::$instance->getConfig($type, '');

//        if (self::$instance->getConfig('mode', Wechat::MODE_NORMAL) === Wechat::MODE_SERVICE) {
//            $payload['sub_appid'] = self::$instance->getConfig('sub_'.$type, '');
//        }

        unset($payload['trade_type'], $payload['type']);
        if (!$preserve_notify_url) {
            unset($payload['notify_url']);
        }

        $payload['sign'] = self::generateSign($payload);

        return $payload;
    }

    /**
     * Generate wechat sign.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array $data
     *
     * @throws InvalidArgumentException
     *
     * @return string
     */
    public static function generateSign($data): string
    {
        $key = self::$instance->key;

        if (is_null($key)) {
            throw new InvalidArgumentException('Missing Wechat Config -- [key]');
        }

        ksort($data);

        $string = md5(self::getSignContent($data).'&key='.$key);

        Log::debug('Wechat Generate Sign Before UPPER', [$data, $string]);

        return strtoupper($string);
    }

    /**
     * Generate sign content.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array $data
     *
     * @return string
     */
    public static function getSignContent($data): string
    {
        $buff = '';

        foreach ($data as $k => $v) {
            $buff .= ($k != 'sign' && $v != '' && !is_array($v)) ? $k.'='.$v.'&' : '';
        }

        Log::debug('Wechat Generate Sign Content Before Trim', [$data, $buff]);

        return trim($buff, '&');
    }

    /**
     * Decrypt refund contents.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $contents
     *
     * @return string
     */
    public static function decryptRefundContents($contents): string
    {
        return openssl_decrypt(
            base64_decode($contents),
            'AES-256-ECB',
            md5(self::$instance->key),
            OPENSSL_RAW_DATA
        );
    }

    /**
     * Convert array to xml.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array $data
     *
     * @throws InvalidArgumentException
     *
     * @return string
     */
    public static function toXml($data): string
    {
        if (!is_array($data) || count($data) <= 0) {
            throw new InvalidArgumentException('Convert To Xml Error! Invalid Array!');
        }

        $xml = '<xml>';
        foreach ($data as $key => $val) {
            $xml .= is_numeric($val) ? '<'.$key.'>'.$val.'</'.$key.'>' :
                                       '<'.$key.'><![CDATA['.$val.']]></'.$key.'>';
        }
        $xml .= '</xml>';

        return $xml;
    }

    /**
     * Convert xml to array.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $xml
     *
     * @throws InvalidArgumentException
     *
     * @return array
     */
    public static function fromXml($xml): array
    {
        if (!$xml) {
            throw new InvalidArgumentException('Convert To Array Error! Invalid Xml!');
        }

        libxml_disable_entity_loader(true);

        return json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA), JSON_UNESCAPED_UNICODE), true);
    }

    /**
     * Get service config.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param null|string $key
     * @param null|mixed  $default
     *
     * @return mixed|null
     */
    public function getConfig($key = null, $default = null)
    {
        if (is_null($key)) {
            return $this->config->all();
        }

        if ($this->config->has($key)) {
            return $this->config[$key];
        }

        return $default;
    }
    

    /**
     * Get Base Uri.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @return string
     */
    public function getBaseUri()
    {
        return $this->baseUri;
    }
    

    /**
     * Set Http options.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @return self
     */
    private function setHttpOptions(): self
    {
        if ($this->config->has('http') && is_array($this->config->get('http'))) {
            $this->config->forget('http.base_uri');
            $this->httpOptions = $this->config->get('http');
        }

        return $this;
    }

    public static function cmbcCashierDecode($str)
    {
//        dd(self::$instance->config->get('jE'));
//        $str = json_encode($data,JSON_UNESCAPED_UNICODE);
        $str = base64_decode($str);
        $data = self::asdkVerifyDecode($str);

        return $data;
    }

    

    public static function cmbcCashierEncode($data)
    {
        $str = json_encode($data,JSON_UNESCAPED_UNICODE);
        $str = base64_encode($str);
        $data = self::sadkSignAllInOne($str);

        return $data;
    }

    private static function sadkSignAllInOne($base64Plain)
    {
        try {
            $ret = self::lajp_call("cfca.sadk.cmbc.patch.tools.php.PHPDecryptKitAllInOne::SignAndEncryptMessage",
                self::$instance->config->get('privatePath'),
                self::$instance->config->get('privatePassword'),
                self::$instance->config->get('publicPath'), $base64Plain);
            return $ret; // 70010001的时候说明 没有初始化 需要调用sadkInitializeByParam 进行初始化
        } catch (Exception $ret) {
            return $e;
        }
    }

    private static function asdkVerifyDecode($str)
    {
        try {

            $ret = self::lajp_call("cfca.sadk.cmbc.patch.tools.php.PHPDecryptKitAllInOne::DecryptAndVerifyMessage",
                self::$instance->config->get('privatePath'),
                self::$instance->config->get('privatePassword'),
                self::$instance->config->get('publicPath'), $str);
            return $ret;
        } catch (Exception $e) {
            return $e;
        }
    }

    private static function lajp_call()
    {
        // 参数数量
        $args_len = func_num_args();
        // 参数数组
        $arg_array = func_get_args();

        // 参数数量不能小于1
        if ($args_len < 1) {
            throw new \Exception("[LAJP Error] lajp_call function's arguments length < 1", self::$instance->config->get('pError'));
        }
        // 第一个参数是Java类、方法名称，必须是string类型
        if (! is_string($arg_array[0])) {
            throw new \Exception("[LAJP Error] lajp_call function's first argument must be string \"class_name::method_name\".", self::$instance->config->get('pError'));
        }

        if (($socket = socket_create(AF_INET, SOCK_STREAM, 0)) === false) {
            throw new \Exception("[LAJP Error] socket create error.", self::$instance->config->get('sError'));
        }

        if (socket_connect($socket, self::$instance->config->get('ip'), self::$instance->config->get('port')) === false) {
            throw new \Exception("[LAJP Error] socket connect error.", self::$instance->config->get('sError'));
        }

        // 消息体序列化
        $request = serialize($arg_array);
        $req_len = strlen($request);

        $request = $req_len . "," . $request;

        // echo "{$request}<br>";

        $send_len = 0;
        do {
            // 发送
            if (($sends = socket_write($socket, $request, strlen($request))) === false) {
                throw  new \Exception("[LAJP Error] socket write error.", self::$instance->config->get('sError'));
            }

            $send_len += $sends;
            $request = substr($request, $sends);
        } while ($send_len < $req_len);

        // 接收
        $response = "";
        while (true) {
            $recv = "";
            if (($recv = socket_read($socket, 1400)) === false) {
                throw new \Exception("[LAJP Error] socket read error.", self::$instance->config->get('sError'));
            }

            if ($recv == "") {
                break;
            }

            $response .= $recv;

            // echo "{$response}<br>";
        }

        // 关闭
        socket_close($socket);

        $rsp_stat = substr($response, 0, 1); // 返回类型 "S":成功 "F":异常
        $rsp_msg = substr($response, 1); // 返回信息
        // echo "返回类型:{$rsp_stat},返回信息:{$rsp_msg}<br>";

        if ($rsp_stat == "F") {
            // 异常信息不用反序列化
            throw new \Exception("[LAJP Error] Receive Java exception: " . $rsp_msg, self::$instance->config->get('jE'));
        } else {
            if ($rsp_msg != "N") // 返回非void
            {
                // 反序列化
                return unserialize($rsp_msg);
            }
        }
    }
}
