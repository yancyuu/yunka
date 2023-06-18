<?php

namespace app\user\controller;

use app\common\controller\Frontend;
use app\common\library\Ems;
use app\common\library\Sms;
use app\index\model\GoodsOrder;
use hehe\Network;
use hehe\Trade;
use think\Config;
use think\Cookie;
use think\Db;
use think\Hook;
use think\Validate;

class User extends Frontend {

    protected $layout = 'default';

    protected $noNeedRight = '*';
    protected $noNeedLogin = ['login', 'register', 'findOrder'];

    //    protected $noNeedLogin = [''];

    public function _initialize() {
        parent::_initialize(); // TODO: Change the autogenerated stub

        $auth = $this->auth;

        if (!Config::get('fastadmin.usercenter')) {
            $this->error(__('User center already closed'), '/');
        }

        //监听注册登录退出的事件
        Hook::add('user_login_successed', function ($user) use ($auth) {
            $expire = input('post.keeplogin') ? 30 * 86400 : 0;
            Cookie::set('uid', $user->id, $expire);
            Cookie::set('token', $auth->getToken(), $expire);
        });
        Hook::add('user_register_successed', function ($user) use ($auth) {
            Cookie::set('uid', $user->id);
            Cookie::set('token', $auth->getToken());
        });
        Hook::add('user_delete_successed', function ($user) use ($auth) {
            Cookie::delete('uid');
            Cookie::delete('token');
        });
        Hook::add('user_logout_successed', function ($user) use ($auth) {
            Cookie::delete('uid');
            Cookie::delete('token');
        });

        if (!$this->request->isPjax()) {
            //            $this->pjaxLayout();

            $this->view->engine->layout('default/layout/' . $this->layout);

        }

    }

    /**
     * 推广记录
     */
    public function promotionLog() {
        $this->assign(['active' => 'promotion_log']);
        return view('default/promotion_log');
    }



    /**
     * 我要推广
     */
    public function spread() {


        $link = Network::getHostDomain() . '/invite.html?u=' . $this->user['id'];

        $this->assign([
            'link' => $link
        ]);
        return view('default/spread');
    }


    /**
     * 成为代理
     */
    public function agency() {

        if ($this->request->isPost()) {
            $params = $this->request->param();
            $agency = db::name('user_agency')->where(['id' => $params['agency_id']])->find();
            if (!$agency) $this->error('代理等级出现错误，请刷新页面后重试');
            if ($this->user['money'] < $agency['price']) $this->error('您的余额不足，请充值');
            $where = ['user_id' => $this->user['id'], 'agency_id' => $params['agency_id']];
            if (db::name('order_agency')->where($where)->find()) $this->error('为了您的资金安全，请勿重复开通');
            $insert = ['user_id' => $this->user['id'], 'agency_id' => $params['agency_id'], 'money' => $agency['price'], 'create_time' => $this->timestamp];
            db::name('order_agency')->insert($insert);
            db::name('user')->where(['id' => $this->user['id']])->update(['agency_id' => $params['agency_id']]);
            db::name('user')->where(['id' => $this->user['id']])->setDec('money', $agency['price']);

            $this->success('已开通');
        }

        $agency = db::name('user_agency')->whereNull('deletetime')->order('weigh desc')->select();

        $this->assign(['active' => 'agency', 'agency' => $agency]);
        return view('default/agency');
    }

    /**
     * 查找订单
     */
    public function findOrder() {
        $list = [];
        $paginate = '';
        $password = '';
        $email = '';
        $mobile = '';
        $total = -1;
        if($this->request->has('password') || $this->request->has('email') || $this->request->has('mobile')){
            $params = $this->request->param();
            $where = [];
            if(in_array('mobile', $this->options['buy_input'])){
                if(empty($params['mobile'])) $this->error('请输入手机号码');
                $where['mobile'] = $params['mobile'];
                $mobile = $params['mobile'];
            }
            if(in_array('email', $this->options['buy_input'])){
                if(empty($params['email'])) $this->error('请输入电子邮箱');
                $where['email'] = $params['email'];
                $email = $params['email'];
            }
            if(in_array('password', $this->options['buy_input'])){
                if(empty($params['password'])) $this->error('请输入查单密码');
                $where['password'] = $params['password'];
                $password = $params['password'];
            }

            $model = new GoodsOrder();
            $result = $model->with(['goods', 'deliver'])->where($where)->whereNotNull('pay_time')->order('id desc')->paginate(10, false, ['query' => $params]);
            $list = $result->items();
            foreach ($list as &$val) {
                $val['pay_type'] = payTypeText($val['pay_type']);
                $val['attach'] = json_decode($val['attach'], true);
            }
            $paginate = $result->render();
            $total = $result->total();



        }
        $this->assign([
            'list' => $list,
            'paginate' => $paginate,
            'password' => $password,
            'mobile' => $mobile,
            'email' => $email,
            'total' => $total
        ]);
        return view('default/find_order');
    }


    /**
     * 修改密码
     */
    public function changepwd() {
        if ($this->request->isPost()) {
            $oldpassword = $this->request->post("oldpassword");
            $newpassword = $this->request->post("newpassword");
            $renewpassword = $this->request->post("renewpassword");
            $token = $this->request->post('__token__');
            $rule = ['oldpassword' => 'require|regex:\S{6,30}', 'newpassword' => 'require|regex:\S{6,30}', 'renewpassword' => 'require|regex:\S{6,30}|confirm:newpassword', '__token__' => 'token',];

            $msg = ['renewpassword.confirm' => __('Password and confirm password don\'t match')];
            $data = ['oldpassword' => $oldpassword, 'newpassword' => $newpassword, 'renewpassword' => $renewpassword, '__token__' => $token,];
            $field = ['oldpassword' => __('Old password'), 'newpassword' => __('New password'), 'renewpassword' => __('Renew password')];
            $validate = new Validate($rule, $msg, $field);
            $result = $validate->check($data);
            if (!$result) {
                $this->error(__($validate->getError()), null, ['token' => $this->request->token()]);
                return false;
            }

            $ret = $this->auth->changepwd($newpassword, $oldpassword);
            if ($ret) {
                $this->success(__('Reset password successful'), url('/login'));
            } else {
                $this->error($this->auth->getError(), null, ['token' => $this->request->token()]);
            }
        }
        $this->assign(['active' => 'changepwd']);
        return view('default/changepwd');
    }

    /**
     * 我的资料
     */
    public function profile() {
        $this->assign(['active' => 'profile']);
        return view('default/profile');
    }

    /**
     * 首页
     */
    public function index() {
        $agency = [];
        if (empty($this->user['agency_id'])) {
            $agency['name'] = '普通用户';
        } else {
            $agencyResult = db::name('user_agency')->where(['id' => $this->user['agency_id']])->find();
            if (empty($agencyResult)) {
                $agency['name'] = '普通用户';
            } else {
                $agency['name'] = $agencyResult['name'];
            }
        }

        $this->assign(['active' => 'index', 'agency' => $agency]);
        return view('default/index');
    }

    /**
     * 订单列表
     */
    public function order() {
        $model = new GoodsOrder();
        $result = $model->with(['goods', 'deliver'])->where(['user_id' => $this->user['id']])->whereNotNull('pay_time')->order('id desc')->paginate(10);
        $list = $result->items();
        foreach ($list as &$val) {
            $val['pay_type'] = payTypeText($val['pay_type']);
            $val['attach'] = json_decode($val['attach'], true);
        }
        $page = $result->render();
        $this->assign(['active' => 'order', 'list' => $list, 'page' => $page,]);
        return view('default/order');
    }



    /**
     * 注册会员
     */
    public function register() {
        $url = $this->request->request('url', '', 'trim');
        if ($this->auth->id) {
            $this->redirect($url ? $url : url('/'));
            die;
        }
        if ($this->request->isPost()) {
            $username = $this->request->post('username');
            $password = $this->request->post('password');
            $repassword = $this->request->post('repassword');
            $email = $this->request->post('email');
            $mobile = $this->request->post('mobile', '');
            $captcha = $this->request->post('captcha');
            $token = $this->request->post('__token__');

            $rule = ['username' => 'require|length:3,30', 'password' => 'require|length:6,30', 'email' => 'require|email', 'mobile' => 'regex:/^1\d{10}$/', '__token__' => 'require|token',];

            $msg = ['username.require' => 'Username can not be empty', 'username.length' => 'Username must be 3 to 30 characters', 'password.require' => 'Password can not be empty', 'password.length' => 'Password must be 6 to 30 characters', 'email' => 'Email is incorrect', 'mobile' => 'Mobile is incorrect',];
            $data = ['username' => $username, 'password' => $password, 'email' => $email, 'mobile' => $mobile, '__token__' => $token,];
            //验证码
            $captchaResult = true;
            $captchaType = config("fastadmin.user_register_captcha");
            if ($captchaType) {
                if ($captchaType == 'mobile') {
                    $captchaResult = Sms::check($mobile, $captcha, 'register');
                } elseif ($captchaType == 'email') {
                    $captchaResult = Ems::check($email, $captcha, 'register');
                } elseif ($captchaType == 'wechat') {
                    $captchaResult = WechatCaptcha::check($captcha, 'register');
                } elseif ($captchaType == 'text') {
                    $captchaResult = \think\Validate::is($captcha, 'captcha');
                }
            }
            if (!$captchaResult) {
                $this->error(__('Captcha is incorrect'));
            }
            $validate = new Validate($rule, $msg);
            $result = $validate->check($data);
            if (!$result) {
                $this->error(__($validate->getError()), null, ['token' => $this->request->token()]);
            }

           if(Validate::is($username, 'email')){
               $this->error('账号不能是邮箱格式');
           }


            if ($password != $repassword) $this->error('两次密码输入不一致', null, ['token' => $this->request->token()]);

            $p1 = Cookie::has('invite_u') ? Cookie::get('invite_u') : 0;
            $p2 = 0;
            $p3 = 0;
            if($p1){
                $p = db::name('user')->where(['id' => $p1])->find();
                if($p){
                    if($p['p1'] > 0){
                        $p2 = $p['p1'];
                        $p3 = $p['p2'];
                    }
                }else{
                    $p1 = 0;
                }
            }

            $merchant_id = $this->is_main ? 0 : $this->merchant['id'];


            if ($this->auth->register($username, $password, $email, $mobile, ['p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'merchant_id' => $merchant_id])) {
                $this->success(__('Sign up successful'), $url ? $url : url('/'));
            } else {
                $this->error($this->auth->getError(), null, ['token' => $this->request->token()]);
            }
        }
        //判断来源
        $referer = $this->request->server('HTTP_REFERER');
        if (!$url && (strtolower(parse_url($referer, PHP_URL_HOST)) == strtolower($this->request->host())) && !preg_match("/(user\/login|user\/register|user\/logout)/i", $referer)) {
            $url = $referer;
        }
        $this->view->assign('captchaType', config('fastadmin.user_register_captcha'));
        $this->view->assign('url', $url);
        $this->view->assign('title', __('Register'));
        return view('default/register');
    }

    /**
     * 会员登录
     */
    public function login() {
        $url = $this->request->request('url', '', 'trim');
        if ($this->auth->id) {
            $this->redirect($url ? $url : url('/'));
            die;
        }
        if ($this->request->isPost()) {
            $account = $this->request->post('account');
            $password = $this->request->post('password');
            $keeplogin = (int)$this->request->post('keeplogin');
            $token = $this->request->post('__token__');
            $rule = ['account' => 'require|length:3,50', 'password' => 'require|length:6,30', '__token__' => 'require|token',];

            $msg = ['account.require' => 'Account can not be empty', 'account.length' => 'Account must be 3 to 50 characters', 'password.require' => 'Password can not be empty', 'password.length' => 'Password must be 6 to 30 characters',];
            $data = ['account' => $account, 'password' => $password, '__token__' => $token,];
            $validate = new Validate($rule, $msg);
            $result = $validate->check($data);
            if (!$result) {
                $this->error(__($validate->getError()), null, ['token' => $this->request->token()]);
                return false;
            }
            if ($this->auth->login($account, $password, $this->is_main, $this->merchant)) {
                $this->success(__('Logged in successful'), $url ? $url : url('/user'));
            } else {
                $this->error($this->auth->getError(), null, ['token' => $this->request->token()]);
            }
        }
        //判断来源
        $referer = $this->request->server('HTTP_REFERER');
        if (!$url && (strtolower(parse_url($referer, PHP_URL_HOST)) == strtolower($this->request->host())) && !preg_match("/(user\/login|user\/register|user\/logout)/i", $referer)) {
            $url = $referer;
        }
        $this->view->assign('url', $url);
        $this->view->assign('title', __('Login'));

        return view('default/login');
    }

    /**
     * 退出登录
     */
    public function logout() {
        if ($this->request->isPost()) {
            $this->token();
            //退出本站
            $this->auth->logout();
            $this->redirect('/login');
            die;

        }

        $html = "<form id='logout_submit' name='logout_submit' action='' method='post'>" . token() . "<input type='submit' value='ok' style='display:none;'></form>";
        $html .= "<script>document.forms['logout_submit'].submit();</script>";
        return $html;
    }

}
