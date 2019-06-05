<?php
/**
 * Created by PhpStorm.
 * User: wpz
 * Date: 2019/6/5
 * Time: 17:17
 */

namespace Yansongda\Pay\Gateways\Cmbc;


use Symfony\Component\HttpFoundation\Response;
use Yansongda\Pay\Contracts\GatewayInterface;
use Yansongda\Pay\Events;
use Yansongda\Pay\Exceptions\GatewayException;
use Yansongda\Pay\Exceptions\InvalidArgumentException;
use Yansongda\Pay\Exceptions\InvalidSignException;
use Yansongda\Pay\Gateways\Cmbc\Support;
use Yansongda\Supports\Collection;

class Cashier extends Gateway
{
    /**
     * Pay an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $endpoint
     * @param array  $payload
     *
     * @throws GatewayException
     * @throws InvalidArgumentException
     * @throws InvalidSignException
     *
     * @return RedirectResponse
     */
    public function pay($endpoint, array $payload): RedirectResponse
    {
        $payload['payWay'] = $this->getTradeType();

        Events::dispatch(Events::PAY_STARTED, new Events\PayStarted('Cmbc', 'Cashier', $endpoint, $payload));

//        $mweb_url = $this->preOrder($payload)->get('mweb_url');
        Support::cmbcCashierEncode($payload);


        return RedirectResponse::create();
    }


    /**
     * Get trade type config.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @return string
     */
    protected function getTradeType(): string
    {
        return '0';
    }

}