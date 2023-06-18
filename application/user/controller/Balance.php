<?php

namespace app\user\controller;

use app\common\controller\Frontend;
use app\common\library\Ems;
use app\common\library\Sms;
use app\index\model\GoodsOrder;
use app\user\controller\WechatCaptcha;
use hehe\Network;
use hehe\Trade;
use think\Config;
use think\Cookie;
use think\Db;
use think\Hook;
use think\Validate;

class Balance extends Frontend {

    protected $layout = 'default';

    protected $noNeedRight = '*';
    protected $noNeedLogin = ['login', 'register'];

    //    protected $noNeedLogin = [''];

    public function _initialize() {
        parent::_initialize(); // TODO: Change the autogenerated stub

        $auth = $this->auth;

        if (!$this->request->isPjax()) {

            $this->view->engine->layout('default/layout/' . $this->layout);

        }

    }



    /**
     * 我的余额
     */
    public function index() {
        if($this->request->isPost()){
            // $this->token();
            $params = $this->request->param();
            if($params['type'] == 'recharge'){ //充值
                if(empty($params['money'])) $this->error('请输入充值金额');
                if($params['money'] < 0.01) $this->error('充值金额输入错误');
                $out_trade_no = Trade::generateTradeNo();
                $money = $params['money'];
                $insert = [
                    'out_trade_no' => $out_trade_no,
                    'user_id' => $this->user['id'],
                    'money' => $money,
                    'pay_type' => $params['pay_type'],
                    'create_time' => $this->timestamp,
                ];
                db::name('recharge_order')->insert($insert);
                // 发起支付
                
                // print_r($this->plugin);die;
                
                // print_r($params);die;
                
                $payPlugin = selectPayPlugin($this->plugin, $params['pay_type']);
                
                
                include_once ROOT_PATH . 'plugin/' . $payPlugin['english_name'] . '/' . $payPlugin['english_name'] . '.php';

                $result = pay([
                    'subject' => '会员充值',
                    'out_trade_no' => $out_trade_no,
                    'money' => $money,
                    'hm_type' => 'recharge',
                    'pay_type' => $params['pay_type']
                ], $payPlugin['info']);
                
                // print_r($this->plugin);die;
                return $result;
            }
            if($params['type'] == 'cashout'){ //提现
                if($params['money'] < 0) $this->error('请输入正确的提现金额');
                if(empty($params['name'])) $this->error('账户姓名不能为空');
                if(empty($params['account'])) $this->error('账号不能为空');
                if($params['money'] > $this->user['money']) $this->error('余额不足');
                $insert = [
                    'out_trade_no' => Trade::generateTradeNo(),
                    'user_id' => $this->user['id'],
                    'create_time' => $this->timestamp,
                    'money' => $params['money'],
                    'name' => $params['name'],
                    'account' => $params['account'],
                ];
                db::name('user')->where(['id' => $this->user['id']])->setDec('money', $params['money']);
                db::name('cashout')->insert($insert);
                $this->success('已提交申请');
            }
        }
        $cashout = db::name('cashout')->where(['user_id' => $this->user['id']])->order('status asc, id desc')->select();

        $pay_list = getPayList($this->plugin);

        $this->assign(['pay_list' => $pay_list, 'cashout' => $cashout]);
        return view('default/balance/index');
    }



}
