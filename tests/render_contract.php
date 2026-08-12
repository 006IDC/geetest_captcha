<?php

namespace think\facade {
    class Cache
    {
        private static $items = [];

        public static function all()
        {
            return self::$items;
        }

        public static function set($key, $value, $ttl = null)
        {
            self::$items[$key] = $value;
            return true;
        }

        public static function get($key, $default = null)
        {
            return array_key_exists($key, self::$items) ? self::$items[$key] : $default;
        }

        public static function delete($key)
        {
            unset(self::$items[$key]);
            return true;
        }

        public static function store()
        {
            return new class {
                public function pull($key)
                {
                    $value = Cache::get($key);
                    Cache::delete($key);
                    return $value;
                }
            };
        }
    }
}

namespace {
    require_once __DIR__ . '/../geetest_captcha/service/GeetestValidator.php';
    require_once __DIR__ . '/../geetest_captcha/logic/GeetestCaptchaLogic.php';

    use captcha\geetest_captcha\logic\GeetestCaptchaLogic;
    use captcha\geetest_captcha\service\GeetestValidator;
    use think\facade\Cache;

    class StubGeetestValidator extends GeetestValidator
    {
        public $verifyCalls = 0;
        public $verifySuccess = true;

        public function verify(array $payload, $captchaId, $captchaKey, $timeoutSeconds)
        {
            $this->verifyCalls++;
            return $this->verifySuccess
                ? ['success' => true, 'code' => 'success']
                : ['success' => false, 'code' => 'provider_rejected'];
        }
    }

    function renderAssert($condition, $message)
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    function extractToken($html)
    {
        preg_match('/var token = "([a-f0-9]{64})";/', $html, $match);
        return (string) ($match[1] ?? '');
    }

    function validPayload($suffix = '')
    {
        return json_encode([
            'provider'       => 'geetest4',
            'lot_number'     => str_repeat('a', 31) . ($suffix !== '' ? substr($suffix, 0, 1) : 'a'),
            'captcha_output' => str_repeat('x', 64),
            'pass_token'     => str_repeat('b', 32),
            'gen_time'       => '1786250000',
        ]);
    }

    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    $_SERVER['HTTP_USER_AGENT'] = 'V10 validation';
    $_SERVER['REQUEST_URI'] = '/login';

    $exampleId = 'examplecaptchaidexamplecaptchaid';
    $exampleKey = 'examplekeyexamplekeyexamplekeyex';
    $baseConfig = [
        'captcha_id'       => $exampleId,
        'captcha_key'      => $exampleKey,
        'verification_mode'=> 'smart',
        'token_ttl'        => '120',
    ];
    $validator = new StubGeetestValidator();
    $logic = new GeetestCaptchaLogic($baseConfig + [
        'language'          => 'eng',
        'client_timeout'    => 30000,
        'server_timeout'    => 10,
        'issue_rate_limit'  => 1,
    ], $validator);
    $frontHtml = $logic->describe(false);
    renderAssert(strpos($frontHtml, 'var language = "zho";') !== false, '语言必须使用固定简体中文默认值');
    renderAssert(strpos($frontHtml, 'var timeout = 10000;') !== false, '前端超时必须使用固定 10 秒默认值');
    renderAssert(strpos($frontHtml, 'var captchaId = "' . $exampleId . '";') !== false, 'GT4 ID 必须注入浏览器');
    renderAssert(strpos($frontHtml, $exampleKey) === false, 'Captcha Key 不得进入浏览器');
    renderAssert(strpos($frontHtml, "product: isAdmin ? 'float' : 'bind'") !== false, '前台和后台必须使用正确的 GT4 展示产品');
    renderAssert(strpos($frontHtml, 'var riskType = "";') !== false, '智能模式不得发送 riskType');
    renderAssert(strpos($frontHtml, 'static.geetest.com/v4/gt4.js') !== false, '页面必须加载 GT4 SDK');
    renderAssert(strpos($frontHtml, 'initGeetest4') !== false, '页面必须调用 GT4 API');
    renderAssert(strpos($frontHtml, 'gt.0.5.0.js') === false, '页面不得加载 GT3 SDK');
    renderAssert(strpos($frontHtml, '.v10-geetest-front { display: none; }') !== false, '前台加载宿主必须不可见，避免重复空白弹窗');
    renderAssert(strpos($frontHtml, '.v10-geetest-front.v10-geetest-fallback') !== false, '初始化失败必须保留可重试错误层');

    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.10, 203.0.113.20';
    $proxyHtml = $logic->describe(false);
    $proxyToken = extractToken($proxyHtml);
    $proxyKey = 'v10_geetest_challenge_' . hash('sha256', $proxyToken);
    $proxyState = Cache::all()[$proxyKey] ?? [];
    renderAssert(
        ($proxyState['client_hash'] ?? '') === substr(hash('sha256', '203.0.113.20|V10 validation'), 0, 32),
        '私网代理链必须使用最右侧有效公网地址，不能信任客户端伪造的左侧地址'
    );
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';

    $token = extractToken($frontHtml);
    renderAssert($token !== '', '渲染结果必须包含本地一次性 Token');
    $payload = validPayload();
    $base = $logic->verify(['token' => $token, 'captcha' => $payload, 'base' => true]);
    renderAssert(($base['status'] ?? 0) === 200, 'V10 基础预检必须进入 GT4 服务端二次校验');
    renderAssert($validator->verifyCalls === 1, '基础预检必须调用极验 validate 一次');
    $final = $logic->verify(['token' => $token, 'captcha' => $payload]);
    renderAssert(($final['status'] ?? 0) === 200, '相同负载的最终校验必须成功');
    renderAssert($validator->verifyCalls === 1, '最终校验必须复用已验证摘要，禁止重复消费上游凭据');
    $replay = $logic->verify(['token' => $token, 'captcha' => $payload]);
    renderAssert(($replay['status'] ?? 0) === 400, '最终校验后 Token 重放必须拒绝');

    $changedHtml = $logic->describe(false);
    $changedToken = extractToken($changedHtml);
    renderAssert($changedToken !== '', '变更负载测试必须取得 Token');
    $changedBase = $logic->verify(['token' => $changedToken, 'captcha' => validPayload('c'), 'base' => true]);
    renderAssert(($changedBase['status'] ?? 0) === 200, '变更负载测试的基础预检应成功');
    $changedFinal = $logic->verify(['token' => $changedToken, 'captcha' => validPayload('d')]);
    renderAssert(($changedFinal['status'] ?? 0) === 400, '基础预检后替换 GT4 负载必须拒绝');

    $expiredHtml = $logic->describe(false);
    $expiredToken = extractToken($expiredHtml);
    $cacheKey = 'v10_geetest_challenge_' . hash('sha256', $expiredToken);
    $expiredState = Cache::get($cacheKey);
    $expiredState['issued_at'] = time() - 121;
    Cache::set($cacheKey, $expiredState, 120);
    $expired = $logic->verify(['token' => $expiredToken, 'captcha' => $payload]);
    renderAssert(($expired['status'] ?? 0) === 400, '配置为 2 分钟时过期 Token 必须拒绝');

    $slideLogic = new GeetestCaptchaLogic(
        array_merge($baseConfig, ['verification_mode' => 'slide']),
        new StubGeetestValidator()
    );
    $slideHtml = $slideLogic->describe(false);
    preg_match('/var riskType = "([^"]+)";/', $slideHtml, $riskMatch);
    $renderedRiskType = (string) ($riskMatch[1] ?? '');
    $riskParts = explode('|', $renderedRiskType);
    renderAssert(count($riskParts) === 4 && $riskParts[0] === 'slide', '滑动拼图必须生成签名 riskType');
    $riskMessage = implode('|', array_slice($riskParts, 0, 3));
    renderAssert(
        hash_equals(hash_hmac('sha256', $riskMessage, $baseConfig['captcha_key']), $riskParts[3]),
        '浏览器 riskType 签名必须可用服务端 Key 独立复算'
    );
    renderAssert(strpos($slideHtml, $baseConfig['captcha_key']) === false, '签名形态不得泄漏 Captcha Key');

    $failedValidator = new StubGeetestValidator();
    $failedValidator->verifySuccess = false;
    $failedLogic = new GeetestCaptchaLogic($baseConfig, $failedValidator);
    $failedHtml = $failedLogic->describe(false);
    $failed = $failedLogic->verify([
        'token' => extractToken($failedHtml),
        'captcha' => $payload,
    ]);
    renderAssert(($failed['status'] ?? 0) === 400, '极验拒绝时必须失败关闭');

    fwrite(STDOUT, "PASS: geetest_captcha render contract\n");
}
