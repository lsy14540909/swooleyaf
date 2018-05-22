<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2017-04-04
 * Time: 1:17
 */
namespace Wx;

use Constant\ErrorCode;
use DesignPatterns\Singletons\WxConfigSingleton;
use Exception\Wx\WxException;

class OrderClose {
    public function __construct(string $appId) {
        $shopConfig = WxConfigSingleton::getInstance()->getShopConfig($appId);
        $this->appid = $shopConfig->getAppId();
        $this->mch_id = $shopConfig->getPayMchId();
        $this->sign_type = 'MD5';
        $this->nonce_str = WxUtilShop::createNonceStr();
    }

    /**
     * 公众号ID
     * @var string
     */
    private $appid = '';

    /**
     * 商户号
     * @var string
     */
    private $mch_id = '';

    /**
     * 商户订单号
     * @var string
     */
    private $out_trade_no = '';

    /**
     * 随机字符串
     * @var string
     */
    private $nonce_str = '';

    /**
     * 签名
     * @var string
     */
    private $sign = '';

    /**
     * 签名类型
     * @var string
     */
    private $sign_type = '';

    /**
     * @param string $outTradeNo
     * @throws \Exception\Wx\WxException
     */
    public function setOutTradeNo(string $outTradeNo) {
        if (preg_match('/^[0-9]{1,32}$/', $outTradeNo . '') > 0) {
            $this->out_trade_no = $outTradeNo . '';
        } else {
            throw new WxException('商户单号不合法', ErrorCode::WX_PARAM_ERROR);
        }
    }

    public function getDetail() : array {
        $resArr = [];
        $saveArr = get_object_vars($this);
        foreach ($saveArr as $key => $value) {
            if (strlen($value . '') > 0) {
                $resArr[$key] = $value;
            }
        }

        if (!isset($resArr['out_trade_no'])) {
            throw new WxException('商户单号不能为空', ErrorCode::WX_PARAM_ERROR);
        }

        $resArr['sign'] = WxUtilShop::createSign($resArr, $this->appid);

        return $resArr;
    }
}