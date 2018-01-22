<?php

namespace app\index\controller;

use app\common\controller\Frontend;
use think\Cookie;
use think\Hook;
use think\Session;
use think\Validate;

/**
 * 会员中心
 */
class User extends Frontend
{

    protected $layout = 'default';
    protected $noNeedLogin = ['login', 'register', 'third'];
    protected $noNeedRight = ['*'];

    public function _initialize()
    {
        parent::_initialize();
        $auth = $this->auth;

        //监听注册登录注销的事件
        Hook::add('user_login_successed', function($user) use($auth) {
            Cookie::set('uid', $user->id);
            Cookie::set('token', $auth->getToken());
        });
        Hook::add('user_register_successed', function($user) use($auth) {
            Cookie::set('uid', $user->id);
            Cookie::set('token', $auth->getToken());
        });
        Hook::add('user_delete_successed', function($user) use($auth) {
            Cookie::delete('uid');
            Cookie::delete('token');
        });
        Hook::add('user_logout_successed', function($user) use($auth) {
            Cookie::delete('uid');
            Cookie::delete('token');
        });
    }

    /**
     * 会员中心
     */
    public function index()
    {
        $this->view->assign('title', __('User center'));
        return $this->view->fetch();
    }

    /**
     * 注册会员
     */
    public function register()
    {
        $url = $this->request->request('url', url('user/index'));
        if ($this->auth->id)
            $this->success(__('You\'ve logged in, do not login again'), $url);
        if ($this->request->isPost())
        {
            $username = $this->request->post('username');
            $password = $this->request->post('password');
            $email = $this->request->post('email');
            $mobile = $this->request->post('mobile', '');
            $captcha = $this->request->post('captcha');
            $token = $this->request->post('__token__');
            $rule = [
                'username'  => 'require|length:3,30',
                'password'  => 'require|length:6,30',
                'email'     => 'require|email',
                'mobile'    => 'regex:/^1\d{10}$/',
                'captcha'   => 'require|captcha',
                '__token__' => 'token',
            ];

            $msg = [
                'username.require' => 'Username can not be empty',
                'username.length'  => 'Username must be 3 to 30 characters',
                'password.require' => 'Password can not be empty',
                'password.length'  => 'Password must be 6 to 30 characters',
                'captcha.require'  => 'Captcha can not be empty',
                'captcha.captcha'  => 'Captcha is incorrect',
                'email'            => 'Email is incorrect',
                'mobile'           => 'Mobile is incorrect',
            ];
            $data = [
                'username'  => $username,
                'password'  => $password,
                'email'     => $email,
                'mobile'    => $mobile,
                'captcha'   => $captcha,
                '__token__' => $token,
            ];
            $validate = new Validate($rule, $msg);
            $result = $validate->check($data);
            if (!$result)
            {
                $this->error(__($validate->getError()));
            }
            if ($this->auth->register($username, $password, $email, $mobile))
            {
                $synchtml = '';
                ////////////////同步到Ucenter////////////////
                if (defined('UC_STATUS') && UC_STATUS)
                {
                    $uc = new \addons\ucenter\library\client\Client();
                    $synchtml = $uc->uc_user_synregister($this->auth->id, $password);
                }
                $referer = Cookie::get('referer_url');
                $this->success(__('Sign up successful') . $synchtml, $referer);
            }
            else
            {
                $this->error($this->auth->getError());
            }
        }
        Session::set('redirect_url', $url);
        $this->view->assign('title', __('Register'));
        return $this->view->fetch();
    }

    /**
     * 会员登录
     */
    public function login()
    {
        $url = $this->request->request('url', url('user/index'));
        if ($this->auth->id)
            $this->success(__('You\'ve logged in, do not login again'), $url);
        if ($this->request->isPost())
        {
            $account = $this->request->post('account');
            $password = $this->request->post('password');
            $keeptime = (int) $this->request->post('keeptime');
            $token = $this->request->post('__token__');
            $rule = [
                'account'   => 'require|length:3,50',
                'password'  => 'require|length:6,30',
                '__token__' => 'token',
            ];

            $msg = [
                'account.require'  => 'Account can not be empty',
                'account.length'   => 'Account must be 3 to 50 characters',
                'password.require' => 'Password can not be empty',
                'password.length'  => 'Password must be 6 to 30 characters',
            ];
            $data = [
                'account'   => $account,
                'password'  => $password,
                '__token__' => $token,
            ];
            $validate = new Validate($rule, $msg);
            $result = $validate->check($data);
            if (!$result)
            {
                $this->error(__($validate->getError()));
                return FALSE;
            }
            if ($this->auth->login($account, $password, $keeptime))
            {
                $synchtml = '';
                ////////////////同步到Ucenter////////////////
                if (defined('UC_STATUS') && UC_STATUS)
                {
                    $uc = new \addons\ucenter\library\client\Client();
                    $synchtml = $uc->uc_user_synlogin($this->auth->id);
                }
                $this->success(__('Logged in successful') . $synchtml, $url);
            }
            else
            {
                $this->error($this->auth->getError());
            }
        }
        $this->view->assign('title', __('Login'));
        return $this->view->fetch();
    }

    /**
     * 注销登录
     */
    function logout()
    {
        //注销本站
        $this->auth->logout();
        $synchtml = '';
        ////////////////同步到Ucenter////////////////
        if (defined('UC_STATUS') && UC_STATUS)
        {
            $uc = new \addons\ucenter\library\client\Client();
            $synchtml = $uc->uc_user_synlogout();
        }
        $this->success(__('Logout successful') . $synchtml, url('user/index'));
    }

    /**
     * 第三方登录跳转和回调处理
     */
    public function third()
    {
        $url = url('user/index');
        $action = $this->request->param('action');
        $platform = $this->request->param('platform');
        $config = get_addon_config('third');
        if (!$config || !isset($config[$platform]))
        {
            $this->error(__('Invalid parameters'));
        }
        foreach ($config as $k => &$v)
        {
            $v['callback'] = url('user/third', ['action' => 'callback', 'platform' => $k], false, true);
        }
        unset($v);
        $app = new \addons\third\library\Application($config);
        if ($action == 'redirect')
        {
            // 跳转到登录授权页面
            $this->redirect($app->{$platform}->getAuthorizeUrl());
        }
        else if ($action == 'callback')
        {
            // 授权成功后的回调
            $result = $app->{$platform}->getUserInfo();
            if ($result)
            {
                $loginret = \addons\third\library\Service::connect($platform, $result);
                if ($loginret)
                {
                    $synchtml = '';
                    ////////////////同步到Ucenter////////////////
                    if (defined('UC_STATUS') && UC_STATUS)
                    {
                        $uc = new \addons\ucenter\library\client\Client();
                        $synchtml = $uc->uc_user_synlogin($this->auth->id);
                    }
                    $this->success(__('Logged in successful') . $synchtml, $url);
                }
            }
            $this->error(__('Operation failed'), $url);
        }
        else
        {
            $this->error(__('Invalid parameters'));
        }
    }

    /**
     * 个人信息
     */
    public function profile()
    {
        $this->view->assign('title', __('Profile'));
        return $this->view->fetch();
    }

    /**
     * 激活邮箱
     */
    public function activeemail()
    {
        $code = $this->request->request('code');
        $code = base64_decode($code);
        parse_str($code, $params);
        if (!isset($params['id']) || !isset($params['time']) || !isset($params['key']))
        {
            $this->error(__('Invalid parameters'));
        }
        $user = \app\common\model\User::get($params['id']);
        if (!$user)
        {
            $this->error(__('User not found'));
        }
        if ($user->verification->email)
        {
            $this->error(__('Email already activation'));
        }
        if ($key !== md5(md5($user->id . $user->email . $time) . $user->salt) || time() - $params['time'] > 1800)
        {
            $this->error(__('Secrity code already invalid'));
        }
        $verification = $user->verification;
        $verification->email = 1;
        $user->verification = $verification;
        $user->save();
        $this->success(__('Active email successful'), url('user/index'));
        return;
    }

    /**
     * 修改密码
     */
    public function changepwd()
    {
        if ($this->request->isPost())
        {
            $oldpassword = $this->request->post("oldpassword");
            $newpassword = $this->request->post("newpassword");
            $renewpassword = $this->request->post("renewpassword");
            $token = $this->request->post('__token__');
            $rule = [
                'oldpassword'   => 'require|length:6,30',
                'newpassword'   => 'require|length:6,30',
                'renewpassword' => 'require|length:6,30|confirm:newpassword',
                '__token__'     => 'token',
            ];

            $msg = [
            ];
            $data = [
                'oldpassword'   => $oldpassword,
                'newpassword'   => $newpassword,
                'renewpassword' => $renewpassword,
                '__token__'     => $token,
            ];
            $field = [
                'oldpassword'   => __('Old password'),
                'newpassword'   => __('New password'),
                'renewpassword' => __('Renew password')
            ];
            $validate = new Validate($rule, $msg, $field);
            $result = $validate->check($data);
            if (!$result)
            {
                $this->error(__($validate->getError()));
                return FALSE;
            }

            $ret = $this->auth->changepwd($newpassword, $oldpassword);
            if ($ret)
            {
                $synchtml = '';
                ////////////////同步到Ucenter////////////////
                if (defined('UC_STATUS') && UC_STATUS)
                {
                    $uc = new \addons\ucenter\library\client\Client();
                    $synchtml = $uc->uc_user_synlogout();
                }
                $this->success(__('Reset password successful') . $synchtml, url('user/login'));
            }
            else
            {
                $this->error($this->auth->getError());
            }
        }
        $this->view->assign('title', __('Change password'));
        return $this->view->fetch();
    }

}
