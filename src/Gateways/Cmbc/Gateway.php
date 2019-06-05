<?php
/**
 * Created by PhpStorm.
 * User: wpz
 * Date: 2019/6/5
 * Time: 17:19
 */

namespace Yansongda\Pay\Gateways\Cmbc;


use Symfony\Component\HttpFoundation\Response;
use Yansongda\Pay\Contracts\GatewayInterface;
use Yansongda\Supports\Collection;

abstract class Gateway implements GatewayInterface
{
    /**
     * Pay an order.
     *
     * @param string $endpoint
     * @param array $payload
     *
     * @return Collection|Response
     * @author yansongda <me@yansongda.cn>
     *
     */
    abstract public function pay($endpoint, array $payload);

    /**
     * Get trade type config.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @return string
     */
    abstract protected function getTradeType();


}