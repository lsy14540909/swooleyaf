<?php
/**
 * Created by PhpStorm.
 * User: jw
 * Date: 17-4-19
 * Time: 下午1:26
 */
class PayController extends CommonController {
    private static $alipaySuccessStatus = [];

    public function init() {
        parent::init();
        self::$alipaySuccessStatus = [
            'TRADE_SUCCESS',
            'TRADE_FINISHED',
        ];
    }

    /**
     * 申请支付
     * @api {post} /Index/Pay/applyPay 申请支付
     * @apiDescription 申请支付
     * @apiGroup OrderPay
     * @apiParam {string} apply_type 申请类型,7位长度字符串
     * @apiUse OrderPayModelWxJs
     * @apiUse OrderPayModelAliWeb
     * @apiUse OrderPayModelAliCode
     * @apiUse OrderPayContentGoods
     * @apiUse CommonSuccess
     * @apiUse CommonFail
     * @SyFilter-{"field": "apply_type","explain": "申请类型","type": "string","rules": {"required": 1,"regex": "/^[0-9a-z]{7}$/"}}
     */
    public function applyPayAction() {
        $applyType = (string)\Request\SyRequest::getParams('apply_type');
        $applyRes = \Dao\OrderDao::applyPay($applyType);
        $this->SyResult->setData($applyRes);

        $this->sendRsp();
    }

    /**
     * 处理微信支付通知
     * @api {post} /Index/Pay/handleWxPayNotify 处理微信支付通知
     * @apiDescription 处理微信支付通知
     * @apiGroup OrderPay
     * @apiSuccess HandleSuccess 处理成功
     * @apiSuccess HandleFail 处理失败
     */
    public function handleWxPayNotifyAction() {
        $wxResultCode = (string)\Request\SyRequest::getParams('result_code', '');
        $wxReturnCode = (string)\Request\SyRequest::getParams('return_code', '');
        if (($wxResultCode == 'SUCCESS') && ($wxReturnCode == 'SUCCESS')) { //支付成功
            //TODO: 添加支付原始记录

            \Dao\OrderDao::completePay([
                'pay_sn' => $xmlData['out_trade_no'] . '',
                'pay_type' => \Constant\Project::PAY_TYPE_WX,
                'pay_money' => $xmlData['total_fee'],
                'pay_attach' => \Tool\Tool::getArrayVal($xmlData, 'attach', ''),
                'trade_status' => '',
            ]);
            $this->SyResult->setData([
                'msg' => '支付成功',
            ]);
        } else if($wxResultCode == 'SUCCESS') { //业务出错
            $this->SyResult->setCodeMsg(\Constant\ErrorCode::COMMON_SERVER_ERROR, $xmlData['err_code_des']);
        } else { //通信出错
            $this->SyResult->setCodeMsg(\Constant\ErrorCode::COMMON_SERVER_ERROR, $xmlData['return_msg']);
        }

        $this->sendRsp();
    }

    /**
     * 处理微信扫码预支付通知
     * @api {post} /Index/Pay/handleWxPrePayNotify 处理微信扫码预支付通知
     * @apiDescription 处理微信扫码预支付通知
     * @apiGroup OrderPay
     * @apiSuccess HandleSuccess 处理成功
     * @apiSuccess HandleFail 处理失败
     */
    public function handleWxPrePayNotifyAction() {
        $appId = (string)\Request\SyRequest::getParams('appid');
        $returnObj = new \Wx\NativeReturn($appId);
        $redis = \DesignPatterns\Factories\CacheSimpleFactory::getRedisInstance();
        $redisKey = \Constant\Server::REDIS_PREFIX_WX_NATIVE_PRE . \Request\SyRequest::getParams('product_id', '');
        if ($redis->exists($redisKey)) {
            $saveArr = \Tool\Tool::jsonDecode($redis->get($redisKey));
            //TODO: 生成一条新的单号记录
            $orderSn = '111';
            //统一下单
            $order = new \Wx\UnifiedOrder(\Wx\UnifiedOrder::TRADE_TYPE_NATIVE, $appId);
            $order->setBody($saveArr['pay_name']);
            $order->setOutTradeNo($orderSn);
            $order->setTotalFee($saveArr['pay_money']);
            $order->setAttach($saveArr['pay_attach']);
            $applyRes = \Wx\WxUtil::applyNativePay($order);
            if($applyRes['code'] == 0){
                $returnObj->setNonceStr($xmlData['nonce_str']);
                $returnObj->setPrepayId($applyRes['data']['prepay_id']);
            } else {
                $returnObj->setErrorMsg($applyRes['message'], $applyRes['message']);
            }
        } else {
            $returnObj->setErrorMsg('支付信息不存在', '支付信息不存在');
        }

        //返回结果
        $resData = $returnObj->getDetail();
        $this->SyResult->setData([
            'result' => \Wx\WxUtil::arrayToXml($resData),
        ]);

        $this->sendRsp();
    }

    /**
     * 处理支付宝退款异步通知消息
     * @api {post} /Index/Pay/handleAliRefundNotify 处理支付宝退款异步通知消息
     * @apiDescription 处理支付宝退款异步通知消息
     * @apiGroup OrderPay
     * @apiSuccess HandleSuccess 处理成功
     * @apiSuccessExample success:
     *     success
     * @apiSuccess HandleFail 处理失败
     * @apiSuccessExample fail:
     *     fail
     */
    public function handleAliRefundNotifyAction() {
        $allParams = \Request\SyRequest::getParams();
        if(\AliPay\AliPayUtil::verifyData($allParams, '2', 'RSA2')){
            if($allParams['notify_type'] == 'batch_refund_notify'){ //即时到账批量退款
                $needData = [
                    'refund_sn' => $allParams['batch_no'],
                    'list' => []
                ];
                $dataArr = explode('#', $allParams['result_details']);
                foreach($dataArr as $eData) {
                    $eData1 = explode('$', $eData);
                    $eData2 = explode('^', $eData1[0]);
                    $needData['list'][] = [
                        'order_sn' => $eData2[0] . '',
                        'refund_money' => number_format($eData2[1], 2, '.', '') . '',
                        'refund_status' => $eData2[2]
                    ];
                }

                //TODO: 处理退款数据
            }

            $this->SyResult->setData('success');
        } else {
            $error = '支付宝退款数据校验失败,数据:' . \Tool\Tool::jsonEncode($allParams, JSON_UNESCAPED_UNICODE);
            \Log\Log::error($error, \Constant\ErrorCode::ALIPAY_PARAM_ERROR);

            $this->SyResult->setData('fail');
        }

        $this->sendRsp();
    }

    /**
     * 处理支付宝付款异步通知消息
     * @api {post} /Index/Pay/handleAliPayNotify 处理支付宝付款异步通知消息
     * @apiDescription 处理支付宝付款异步通知消息
     * @apiGroup OrderPay
     * @apiSuccess HandleSuccess 处理成功
     * @apiSuccessExample success:
     *     success
     * @apiSuccess HandleFail 处理失败
     * @apiSuccessExample fail:
     *     fail
     */
    public function handleAliPayNotifyAction() {
        $allParams = \Request\SyRequest::getParams();
        if(\AliPay\AliPayUtil::verifyData($allParams, '2', 'RSA2')){
            if(($allParams['notify_type'] == 'trade_status_sync') && (in_array($allParams['trade_status'], self::$alipaySuccessStatus))) {
                //TODO: 添加支付原始记录

                if(isset($allParams['payment_type'])) { //网页支付
                    $payMoney = isset($allParams['total_fee']) && is_numeric($allParams['total_fee']) ? (int)($allParams['total_fee'] * 1000 / 10) : 0;
                } else { //二维码支付
                    if(isset($allParams['buyer_pay_amount']) && is_numeric($allParams['buyer_pay_amount'])) {
                        $payMoney = (int)($allParams['buyer_pay_amount'] * 1000 / 10);
                    } else if(isset($allParams['total_amount']) && is_numeric($allParams['total_amount'])) {
                        $payMoney = (int)($allParams['total_amount'] * 1000 / 10);
                    } else {
                        $payMoney = 0;
                    }
                }

                \Dao\OrderDao::completePay([
                    'pay_sn' => $allParams['out_trade_no'] . '',
                    'pay_type' => \Constant\Project::PAY_TYPE_ALI,
                    'pay_money' => $payMoney,
                    'pay_attach' => isset($allParams['body']) && is_string($allParams['body']) ? $allParams['body'] . '' : '',
                    'trade_status' => $allParams['trade_status'],
                ]);
            }

            $this->SyResult->setData('success');
        } else {
            $error = '支付宝付款数据校验失败,数据:' . \Tool\Tool::jsonEncode($allParams, JSON_UNESCAPED_UNICODE);
            \Log\Log::error($error, \Constant\ErrorCode::ALIPAY_PARAM_ERROR);

            $this->SyResult->setData('fail');
        }

        $this->sendRsp();
    }
}