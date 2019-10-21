<?php

namespace app\home\controller;

use think\Controller;

class Login extends Controller
{
    public function login()
    {
        //临时关闭模板布局
        $this->view->engine->layout(false);
        return view();
    }
    //登录 表单提交
    public function dologin()
    {
        //接收参数
        $params = input();
  
        //参数检测
        $validate = $this->validate($params, [
            'username|用户名' => 'require',
            'password|密码' => 'require|length:6,20'
        ]);
        if($validate !== true){
            $this->error($validate);
        }
        $password = encrypt_password($params['password']);
        //1.根据用户名 查询用户表 email 和 phone字段，再比对密码
        $info = \app\common\model\User::where('email', $params['username'])->whereOr('phone', $params['username'])->find();
        if($info && $info['password'] == $password){
            //登录成功
            session('user_info', $info->toArray());
            //迁移cookie购物车数据到数据表
            \app\home\logic\CartLogic::cookieTodb();
            //关联第三方用户
            $open_user_id = session('open_user_id');
            if($open_user_id){
                //关联用户
                \app\common\model\OpenUser::update(['user_id' => $info['id']], ['id' => $open_user_id], true);
                session('open_user_id', null);
            }
            //同步昵称到用户表
            $nickname = session('open_user_nickname');
            if($nickname){
                \app\common\model\User::update(['nickname' => $nickname], ['id' => $info['id']], true);
                session('open_user_nickname', null);
            }            
            //登录成功后跳转的地址
            //首先从session中取跳转地址
            // dump(session('back_url'));
            // die;
            // $back_url = session('back_url') ?: 'home/index/index';
            // //跳转的时候就使用（$back_url）的地址
            // $this->redirect('$back_url');
            $this->redirect('home/index/index');
        }else{
            //用户名或密码错误
            $this->error('用户名或密码错误');
        }
        //2.根据用户名密码查询用户表  email  phone  password
        //SELECT * FROM `pyg_user` where (phone = '13312349999' or email='13312349999') and password = '12345613';
        /*$info = \app\common\model\User::where(function($query)use($params){
                $query->where('email', $params['username'])->whereOr('phone', $params['username']);
        })->where('password', $password)->find();
        if($info){
            //登录成功
            session('user_info', $info->toArray());
            //跳转首页
            $this->redirect('home/index/index');
        }else{
            //用户名或密码错误
            $this->error('用户名或密码错误');
        }*/
        

    }
    public function register()
    {
        //临时关闭模板布局
        $this->view->engine->layout(false);
        return view();
    }

    public function logout()
    {
        //清空session
        session(null);
        //跳转到登录页
        $this->redirect('home/login/login');
    }

    /**
     * 手机号注册
     */
    public function phone()
    {
        //接收参数
        $params = input();
        //dump($params);die;
        //参数检测
        $validate = $this->validate($params, [
            'phone|手机号' => 'require|regex:1[3-9]\d{9}|unique:user,phone',
            'password|密码' => 'require|length:6,20|confirm:repassword',
            //'repassword|确认密码' => 'require|length:6,20',
            'code|短信验证码' => 'require|length:4'
        ], [
            'phone.regex' => '手机号格式不正确'
        ]);
        if($validate !== true){
            $this->error($validate);
        }
        //验证码校验
        $code = cache('register_code_' . $params['phone']);
        if($code != $params['code']){
            $this->error('验证码错误');
        }
        //验证码使用一次后失效
        cache('register_code_' . $params['phone'], null);
        //注册用户 添加数据
        $params['password'] = encrypt_password($params['password']);
        $params['username'] = $params['phone'];
        $params['nickname'] = encrypt_phone($params['phone']);
        $res = \app\common\model\User::create($params, true);
        //1.跳转到登录页(让用户再次登录)
        $this->redirect('home/login/login');

        //2.注册后自动登录
        /*//设置登录标识
        $info = \app\common\model\User::find($res['id']);
        session('user_info', $info->toArray());
        //跳转到首页
        $this->redirect('home/index/index');*/
    }

    /**
     * 发送短信验证码接口
     */
    public function sendcode()
    {
        //接收参数
        $params = input();
        //参数检测
        $validate = $this->validate($params, [
            'phone|手机号' => 'require|regex:1[3-9]\d{9}'
        ]);
        if($validate !== true){
            return json(['code' => 400, 'msg' => $validate]);
            //echo json_encode(['code' => 400, 'msg' => $validate], JSON_UNESCAPED_UNICODE);
        }
        //检测发送频率
        $last_time = cache('register_time_' . $params['phone']) ?: 0;
        if(time() - $last_time < 60){
            return json(['code' => 400, 'msg' => '发送太频繁']);
        }
        //发送短信
        $code = mt_rand(1000,9999);
        $msg = '【创信】你的验证码是：' . $code . '，3分钟内有效！';
        //$res = send_msg($params['phone'], $msg);
        $res = true; //开发测试过程，假装短信发送成功
        //返回数据
        if($res === true){
            //发送成功
            //将验证码保存在缓存中
            cache('register_code_' . $params['phone'], $code, 180);
            cache('register_time_' . $params['phone'], time(), 180);
            //return json(['code' => 200, 'msg' => '短信发送成功']);
            return json(['code' => 200, 'msg' => '短信发送成功', 'data'=>$code]);//开发测试过程
        }else{
            //发送失败
            return json(['code' => 401, 'msg' => $res]);
        }
    }

    /**
     * qq登录回调地址
     */
    public function qqcallback()
    {
        require_once("./plugins/qq/API/qqConnectAPI.php");
        $qc = new \QC();
        $access_token = $qc->qq_callback(); //接口调用过程中的临时令牌
        $openid = $qc->get_openid(); //第三方帐号在本应用中的唯一标识
        //获取第三方帐号用户信息（比如昵称、头像。。。）
        $qc = new \QC($access_token, $openid);
        $info = $qc->get_user_info();
        //dump($info);die;
        //接下来就是自动登录和注册流程
        //判断是否已经关联绑定用户
        $open_user = \app\common\model\OpenUser::where('open_type', 'qq')->where('openid', $openid)->find();
        if($open_user && $open_user['user_id']){
            //已经关联过用户，直接登录成功
            //同步用户信息到用户表
            $user = \app\common\model\User::find($open_user['user_id']);
            $user->nickname = $info['nickname'];
            $user->save();
            //设置登录标识
            session('user_info', $user->toArray());
            //迁移cookie购物车数据到数据表
            \app\home\logic\CartLogic::cookieTodb();
              //登录成功后跳转的地址
            //首先从session中取跳转地址
            $back_url = session('back_url') ? : 'home/index/index';
            //跳转的时候就使用（$back_url）的地址
            $this->redirect('$back_url');
        }
        if(!$open_user){
            //第一次登录，没有记录，添加一条记录到open_user表
            $open_user = \app\common\model\OpenUser::create(['open_type' => 'qq', 'openid' => $openid]);
        }
        //让第三方帐号去关联用户（可能是注册，可能是登录）
        //记录第三方帐号到session中，用于后续关联用户
        session('open_user_id', $open_user['id']);
        session('open_user_nickname', $info['nickname']);
        $this->redirect('home/login/login');
    }

    public function alicallback()
    {
        require_once('./plugins/alipay/oauth/service/AlipayOauthService.php');
        require_once('./plugins/alipay/oauth/config.php');
        $AlipayOauthService = new \AlipayOauthService($config);
        //获取auth_code
        $auth_code = $AlipayOauthService->auth_code();
        //获取access_token
        $access_token = $AlipayOauthService->get_token($auth_code);
        //获取用户信息  user_id  nick_name
        $info = $AlipayOauthService->get_user_info($access_token);
        $openid = $info['user_id'];
        //dump($info);die;
        //接下来就是关联绑定用户的过程
        //判断是否已经关联绑定用户
        $open_user = \app\common\model\OpenUser::where('open_type', 'alipay')->where('openid', $openid)->find();
        if($open_user && $open_user['user_id']){
            //已经关联过用户，直接登录成功
            //同步用户信息到用户表
            $user = \app\common\model\User::find($open_user['user_id']);
            $user->nickname = $info['nick_name'];
            $user->save();
            //设置登录标识
            session('user_info', $user->toArray());
            //迁移cookie购物车数据到数据表
            \app\home\logic\CartLogic::cookieTodb();
            //登录成功后跳转的地址
            //首先从session中取跳转地址
            $back_url = session('back_url') ? : 'home/index/index';
            //跳转的时候就使用（$back_url）的地址
            $this->redirect('$back_url');
            //$this->redirect('home/index/index');
        }
        if(!$open_user){
            //第一次登录，没有记录，添加一条记录到open_user表
            $open_user = \app\common\model\OpenUser::create(['open_type' => 'alipay', 'openid' => $openid]);
        }
        //让第三方帐号去关联用户（可能是注册，可能是登录）
        //记录第三方帐号到session中，用于后续关联用户
        session('open_user_id', $open_user['id']);
        session('open_user_nickname', $info['nick_name']);
        $this->redirect('home/login/login');
    }
    public function test(){
        $phone = '15313139033';
        $msg = '【创信】你的验证码是：8888，3分钟内有效！';
        //$msg = '【品优购】你用于注册的验证码是：1234，3分钟内有效！';
        $res = send_msg($phone, $msg);
        dump($res);die;
    }
}
