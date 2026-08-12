<?php

namespace captcha\geetest_captcha;

use app\common\lib\Plugin;
use captcha\geetest_captcha\logic\GeetestCaptchaLogic;
use think\facade\Db;

/**
 * GeeTest CAPTCHA v4 integration for ZJMF-CBAP V10.
 */
class GeetestCaptcha extends Plugin
{
    public $info = [
        'name'        => 'GeetestCaptcha',
        'title'       => '极验行为验证第四代',
        'description' => '极验行为验证第四代，支持 V10 统一验证码场景、服务端二次校验、一次性 Token 与限流防爆破',
        'author'      => 'Open Source Community',
        'version'     => '1.3.0',
    ];

    public function install()
    {
        return true;
    }

    public function upgrade()
    {
        try {
            if (!class_exists(Db::class)) {
                return true;
            }
            $row = Db::name('plugin')
                ->where('module', 'captcha')
                ->where('name', 'GeetestCaptcha')
                ->field('id,config')
                ->find();
            if (!$row) {
                return true;
            }
            $config = json_decode((string) ($row['config'] ?? ''), true);
            if (!is_array($config)) {
                $config = [];
            }
            foreach ([
                'language',
                'client_timeout',
                'server_timeout',
                'issue_rate_limit',
                'verify_rate_limit',
                'security_policy',
            ] as $obsolete) {
                unset($config[$obsolete]);
            }
            $modes = ['smart', 'ai', 'slide', 'match', 'winlinze', 'nine', 'word', 'phrase', 'icon'];
            if (!in_array((string) ($config['verification_mode'] ?? ''), $modes, true)) {
                $config['verification_mode'] = 'smart';
            }
            $tokenTtls = ['120', '300', '600'];
            if (!in_array((string) ($config['token_ttl'] ?? ''), $tokenTtls, true)) {
                $config['token_ttl'] = '600';
            } else {
                $config['token_ttl'] = (string) $config['token_ttl'];
            }
            $normalizedConfig = json_encode(
                $config,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if (!is_string($normalizedConfig)) {
                return false;
            }
            if ($normalizedConfig === (string) ($row['config'] ?? '')) {
                return true;
            }
            Db::name('plugin')->where('id', (int) $row['id'])->update([
                'config'      => $normalizedConfig,
                'update_time' => time(),
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function uninstall()
    {
        return true;
    }

    public function GeetestCaptchaDescribe()
    {
        return $this->logic()->describe(false);
    }

    public function GeetestCaptchaDescribeAdmin()
    {
        return $this->logic()->describe(true);
    }

    public function GeetestCaptchaVerify($param)
    {
        return $this->logic()->verify(is_array($param) ? $param : []);
    }

    private function logic()
    {
        return new GeetestCaptchaLogic($this->getConfig());
    }
}
