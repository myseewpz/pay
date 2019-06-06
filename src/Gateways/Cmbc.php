<?php
/**
 * Created by PhpStorm.
 * User: wpz
 * Date: 2019/6/5
 * Time: 15:33
 */

namespace Yansongda\Pay\Gateways;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yansongda\Pay\Contracts\GatewayApplicationInterface;
use Yansongda\Pay\Contracts\GatewayInterface;
use Yansongda\Pay\Events;
use Yansongda\Pay\Exceptions\InvalidConfigException;
use Yansongda\Pay\Exceptions\InvalidGatewayException;
use Yansongda\Pay\Exceptions\InvalidSignException;
use Yansongda\Pay\Gateways\Cmbc\Support;
use Yansongda\Supports\Collection;
use Yansongda\Supports\Config;
use Yansongda\Supports\Str;

class Cmbc implements GatewayApplicationInterface
{
    /**
     * 普通模式.
     */
    const MODE_NORMAL = 'normal';

    /**
     * Const url.
     */
    const URL = [
        self::MODE_NORMAL  => 'https://uat.zwmedia.com/',
    ];

    /**
     * Cmbc payload.
     *
     * @var array
     */
    protected $payload;

    /**
     * Cmbc gateway.
     *
     * @var string
     */
    protected $gateway;


    public function __construct(Config $config)
    {
        $this->gateway = Support::create($config)->getBaseUri();
        $this->payload = [
            '_CallBackUrl'      => $config->get('JumpUrl'),
            'merchInfo'         => $config->get('corpID'),
            'url'               => $config->get('NotifyUrl'),
//            'version'           => '1.0',
            'timestamp'         => (int) (microtime(true) * 1000),
        ];

    }

    /**
     * Magic pay.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $method
     * @param array  $params
     *
     * @throws InvalidGatewayException
     *
     * @return Response|Collection
     */
    public function __call($method, $params)
    {
        return $this->pay($method, ...$params);
    }


    /**
     * Cancel an order.
     *
     * @param string|array $order
     *
     * @return Collection
     * @author yansongda <me@yansongda.cn>
     *
     */
    public function cancel($order)
    {
        // TODO: Implement cancel() method.
    }

    /**
     * Verify sign.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param null|array $data
     * @param bool       $refund
     *
     * @throws InvalidSignException
     * @throws InvalidConfigException
     *
     * @return Collection
     */
    public function verify($data = null, $refund = false): Collection
    {
        if (is_null($data)) {
            $request = Request::createFromGlobals();

            $data = $request->request->count() > 0 ? $request->request->all() : $request->query->all();
        }

        Events::dispatch(Events::REQUEST_RECEIVED, new Events\RequestReceived('cmbc', '', $data));

        $data = Support::cmbcCashierDecode($data['req']);
        
        if (preg_match('/\$ERRCODE/', $data)) {
            $data = Support::cmbcCashierDecode($data['req']);
            if (preg_match('/\$ERRCODE/', $data)) {

                Events::dispatch(Events::SIGN_FAILED, new Events\SignFailed('cmbc', '', $data));

                throw new InvalidSignException('cmbc Sign Verify FAILED', $data);
            }
        }
        $data = base64_decode($data);
        $data = json_decode($data, true);
        $data['orderNum'] = substr($data['orderNo'], strlen($this->payload['merchInfo']));

//        return $data;
//        if (is_array($data)) {
//            $data['ordenum'] = substr($data['orderNo'], strlen($config->get('corpID')));
//            if (count($arr) > 4) {
//                if ($arr['status'] == '02') {
//                    return new Collection($data);
//                }
//            }
//        }
        return new Collection($data);

    }

    /**
     * Close an order.
     *
     * @param string|array $order
     *
     * @return Collection
     * @author yansongda <me@yansongda.cn>
     *
     */
    public function close($order)
    {
        // TODO: Implement close() method.
    }

    /**
     * To pay.
     *
     * @param string $gateway
     * @param array $params
     *
     * @return Collection|Response
     * @author yansongda <me@yansonga.cn>
     *
     */
    public function pay($gateway, $params)
    {
        Events::dispatch(Events::PAY_STARTING, new Events\PayStarting('Cmbc', $gateway, $params));

        $this->payload['_CallBackUrl'] = $params['_CallBackUrl'] ?? $this->payload['_CallBackUrl'];
        $this->payload['url'] = $params['url'] ?? $this->payload['url'];

        unset($params['_CallBackUrl'], $params['url']);

        $this->payload = array_merge($this->payload, $params);

        $gateway = get_class($this).'\\'.Str::studly($gateway).'Gateway';

        if (class_exists($gateway)) {
            return $this->makePay($gateway);
        }

        throw new InvalidGatewayException("Pay Gateway [{$gateway}] not exists");
    }

    /**
     * Query an order.
     *
     * @param string|array $order
     * @param bool $refund
     *
     * @return Collection
     * @author yansongda <me@yansongda.cn>
     *
     */
    public function find($order, $refund)
    {
        // TODO: Implement find() method.
    }

    /**
     * Refund an order.
     *
     * @param array $order
     *
     * @return Collection
     * @author yansongda <me@yansongda.cn>
     *
     */
    public function refund($order)
    {
        // TODO: Implement refund() method.
    }

    /**
     * Echo success to server.
     *
     * @return Response
     * @author yansongda <me@yansongda.cn>
     *
     */
    public function success()
    {
        return ['msgCode' => 01];
    }

    public function fail()
    {
        return ['msgCode' => 02];
    }


    /**
     * Make pay gateway.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $gateway
     *
     * @throws InvalidGatewayException
     *
     * @return Response|Collection
     */
    protected function makePay($gateway)
    {
        $app = new $gateway();

        if ($app instanceof GatewayInterface) {
            return $app->pay($this->gateway, array_filter($this->payload, function ($value) {
                return $value !== '' && !is_null($value);
            }));
        }

        throw new InvalidGatewayException("Pay Gateway [{$gateway}] Must Be An Instance Of GatewayInterface");
    }
}