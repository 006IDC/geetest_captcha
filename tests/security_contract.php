<?php

require_once __DIR__ . '/../geetest_captcha/service/GeetestValidator.php';

use captcha\geetest_captcha\service\GeetestValidator;

function gcAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$valid = json_encode([
    'provider'       => 'geetest4',
    'lot_number'     => str_repeat('a', 32),
    'captcha_output' => str_repeat('x', 64),
    'pass_token'     => str_repeat('b', 32),
    'gen_time'       => '1786250000',
]);
$decoded = GeetestValidator::decodePayload($valid);
gcAssert(is_array($decoded), '合法的极验 GT4 负载应通过结构校验');
gcAssert(count($decoded) === 4, '服务端只保留四个必要字段');
gcAssert(GeetestValidator::decodePayload('{}') === null, '缺少字段必须拒绝');
gcAssert(GeetestValidator::decodePayload(['lot_number' => 'x']) === null, '不允许跳过 JSON 字符串契约');
gcAssert(GeetestValidator::decodePayload(str_repeat('x', 16385)) === null, '超大输入必须拒绝');

$invalidProvider = json_decode($valid, true);
$invalidProvider['provider'] = 'geetest3';
gcAssert(GeetestValidator::decodePayload(json_encode($invalidProvider)) === null, '错误代际 provider 必须拒绝');

$invalidOutput = json_decode($valid, true);
$invalidOutput['captcha_output'] = str_repeat('x', 32) . "\n";
gcAssert(GeetestValidator::decodePayload(json_encode($invalidOutput)) === null, '控制字符必须拒绝');

gcAssert(
    GeetestValidator::signToken(str_repeat('a', 32), str_repeat('b', 32))
        === '0c5260d6aab2ecf23021100097dec084e5031dcc59dad4104177c4cae4625468',
    'sign_token 必须按 HMAC-SHA256(key, lot_number) 生成'
);
$riskType = GeetestValidator::buildRiskType(
    'slide',
    '1786250000.123456',
    str_repeat('c', 32),
    str_repeat('b', 32)
);
gcAssert(
    $riskType === 'slide|1786250000.123456|' . str_repeat('c', 32)
        . '|eb5e9a3292af4cf970e8faf60e7a115d3e9c3e84bc51cd09533183e349b41e28',
    '指定验证形态必须按官方格式签名'
);
gcAssert(
    GeetestValidator::buildRiskType('smart', '1786250000.123456', str_repeat('c', 32), str_repeat('b', 32)) === '',
    '智能模式不得伪造 riskType'
);
gcAssert(
    GeetestValidator::buildRiskType('slide', 'bad', str_repeat('c', 32), str_repeat('b', 32)) === '',
    '非法时间戳必须拒绝'
);
gcAssert(
    GeetestValidator::payloadDigest($decoded) === GeetestValidator::payloadDigest(array_reverse($decoded, true)),
    '负载摘要必须与输入键顺序无关'
);

$entry = file_get_contents(__DIR__ . '/../geetest_captcha/GeetestCaptcha.php');
$logic = file_get_contents(__DIR__ . '/../geetest_captcha/logic/GeetestCaptchaLogic.php');
$service = file_get_contents(__DIR__ . '/../geetest_captcha/service/GeetestValidator.php');
$config = file_get_contents(__DIR__ . '/../geetest_captcha/config.php');

gcAssert(strpos($entry, "'name'        => 'GeetestCaptcha'") !== false, '稳定插件标识不可变更');
gcAssert(strpos($entry, "'version'     => '1.3.0'") !== false, '发布版本必须为 1.3.0');
gcAssert(strpos($entry, 'public function upgrade()') !== false, '必须提供幂等 upgrade()');
gcAssert(strpos($entry, "if (\$normalizedConfig === (string) (\$row['config'] ?? ''))") !== false, '配置未变化时不得重复写库');
gcAssert(strpos($entry, "\$config['verification_mode'] = 'smart'") !== false, '升级必须初始化 GT4 智能模式');
gcAssert(strpos($entry, "\$config['token_ttl'] = '600'") !== false, '升级必须初始化 10 分钟有效期');
gcAssert(strpos($entry, 'GeetestCaptchaDescribeAdmin') !== false, '必须实现后台渲染契约');
gcAssert(strpos($entry, 'GeetestCaptchaVerify') !== false, '必须实现最终校验契约');
gcAssert(strpos($logic, 'captchaCheckSuccsss(true, payload, token, false)') !== false, '必须保持 V10 真实回调拼写和参数');
gcAssert(strpos($logic, 'captchaCheckSuccsss(false)') === false, '初始化失败不得误触发业务验证回调');
gcAssert(strpos($logic, 'Cache::store()->pull($cacheKey)') !== false, '最终校验必须消费本地 Token');
gcAssert(strpos($logic, 'foreach (array_reverse($forwarded) as $candidate)') !== false, '代理链必须从右侧解析，避免信任伪造的左侧地址');
gcAssert(strpos($logic, 'static.geetest.com/v4/gt4.js') !== false, '必须使用极验 GT4 HTTPS SDK');
gcAssert(strpos($logic, 'window.initGeetest4(options') !== false, '必须使用 GT4 initGeetest4 API');
gcAssert(strpos($logic, "if (typeof captcha.showCaptcha === 'function') captcha.showCaptcha();") !== false, '前台 bind 模式必须主动显示 GT4');
gcAssert(strpos($logic, '#__ROOT_ID__.v10-geetest-front { display: none; }') !== false, '前台加载期间不得显示重复空白弹窗');
gcAssert(strpos($logic, "root.classList.add('v10-geetest-fallback')") !== false, '初始化失败时必须提供可重试错误层');
gcAssert(strpos($logic, 'GeetestValidator::buildRiskType') !== false, '指定形态必须由服务端生成签名 riskType');
gcAssert(strpos($logic, 'if (riskType) options.riskType = riskType;') !== false, '智能模式不得发送空 riskType');
gcAssert(strpos($logic, '人机验证初始化超时，请重试') !== false, '必须有初始化看门狗，禁止永久加载');
gcAssert(
    strpos($logic, "foreach (['captcha_id', 'captcha_key', 'verification_mode', 'token_ttl'] as \$name)") !== false,
    '运行期只允许真实业务配置覆盖安全默认值'
);
gcAssert(strpos($logic, 'initGeetest(') === false, '不得残留第三代初始化 API');
gcAssert(strpos($service, "const API_URL = 'https://gcaptcha4.geetest.com/validate'") !== false, '二次校验必须使用固定 GT4 HTTPS 端点');
gcAssert(strpos($service, "'sign_token'     => self::signToken") !== false, '二次校验必须提交服务端签名');
gcAssert(strpos($service, "(\$response['status'] ?? '') !== 'success'") !== false, '必须同时校验供应商请求状态');
gcAssert(strpos($service, "(\$response['result'] ?? '') !== 'success'") !== false, '必须校验供应商二次验证结果');
gcAssert(strpos($service, 'CURLOPT_SSL_VERIFYPEER  => true') !== false, '不得禁用 TLS 证书校验');
gcAssert(strpos($service, 'CURLOPT_SSL_VERIFYHOST  => 2') !== false, '必须校验 TLS 主机名');
gcAssert(strpos($service, 'CURLOPT_FOLLOWLOCATION  => false') !== false, '极验请求不得跟随重定向');
gcAssert(strpos($config, "'type'    => 'password'") !== false, 'Captcha Key 配置必须使用密码输入');
gcAssert(strpos($config, "'verification_mode' =>") !== false, 'GT4 配置页必须提供前端应用样式');
gcAssert(strpos($config, "'token_ttl' =>") !== false, 'GT4 配置页必须提供真实有效期设置');
foreach (['smart', 'ai', 'slide', 'match', 'winlinze', 'nine', 'word', 'phrase', 'icon'] as $mode) {
    gcAssert(
        strpos($config, "'{$mode}'") !== false,
        "配置页缺少 GT4 验证形态 {$mode}"
    );
}
foreach (['120', '300', '600'] as $ttl) {
    gcAssert(strpos($config, "'{$ttl}' =>") !== false, "配置页缺少有效期 {$ttl}");
}
foreach (['language', 'client_timeout', 'server_timeout', 'issue_rate_limit', 'verify_rate_limit', 'security_policy'] as $obsolete) {
    gcAssert(strpos($config, "'{$obsolete}' =>") === false, "配置页不得继续显示 {$obsolete}");
}

fwrite(STDOUT, "PASS: geetest_captcha security contract\n");
