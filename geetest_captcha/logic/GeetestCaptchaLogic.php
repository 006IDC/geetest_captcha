<?php

namespace captcha\geetest_captcha\logic;

use captcha\geetest_captcha\service\GeetestValidator;
use think\facade\Cache;

class GeetestCaptchaLogic
{
    private $config;

    private $validator;

    private $defaults = [
        'captcha_id'        => '',
        'captcha_key'       => '',
        'verification_mode' => 'smart',
        'language'          => 'zho',
        'client_timeout'    => 10000,
        'server_timeout'    => 5,
        'token_ttl'         => 600,
        'issue_rate_limit'  => 20,
        'verify_rate_limit' => 30,
    ];

    public function __construct(array $config = [], ?GeetestValidator $validator = null)
    {
        $this->config = $this->defaults;
        foreach (['captcha_id', 'captcha_key', 'verification_mode', 'token_ttl'] as $name) {
            if (array_key_exists($name, $config)) {
                $this->config[$name] = $config[$name];
            }
        }
        $this->validator = $validator ?: new GeetestValidator();
    }

    public function describe($isAdmin = false)
    {
        $scope = $isAdmin ? 'admin' : 'front';
        if (!$this->isConfigured()) {
            return $this->renderUnavailable($isAdmin, '极验参数尚未配置，请联系管理员');
        }

        if ($this->rateLimitExceeded('issue', $scope, $this->intConfig('issue_rate_limit', 5, 120))) {
            return $this->renderUnavailable($isAdmin, '验证请求过于频繁，请稍后再试');
        }

        try {
            $token = bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            $this->safeLog('token_generation_failed', []);
            return $this->renderUnavailable($isAdmin, '验证服务暂时不可用，请稍后再试');
        }

        $ttl = $this->intConfig('token_ttl', 120, 600);
        $state = [
            'scope'            => $scope,
            'client_hash'      => $this->clientHash(),
            'issued_at'        => time(),
            'validated_digest' => '',
        ];
        if (!Cache::set($this->challengeKey($token), $state, $ttl)) {
            return $this->renderUnavailable($isAdmin, '验证服务暂时不可用，请稍后再试');
        }

        return $this->renderCaptcha($isAdmin, $token);
    }

    public function verify(array $param)
    {
        if (!$this->isConfigured()) {
            return $this->failure('not_configured');
        }

        $scope = $this->requestScope();
        if ($this->rateLimitExceeded('verify', $scope, $this->intConfig('verify_rate_limit', 5, 180))) {
            return $this->failure('rate_limited');
        }

        $token = isset($param['token']) && is_string($param['token']) ? $param['token'] : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return $this->failure('invalid_token');
        }

        $payload = GeetestValidator::decodePayload($param['captcha'] ?? null);
        if ($payload === null) {
            return $this->failure('invalid_payload');
        }
        $digest = GeetestValidator::payloadDigest($payload);
        $baseCheck = !empty($param['base']);
        $cacheKey = $this->challengeKey($token);

        if ($baseCheck) {
            $state = Cache::get($cacheKey);
        } else {
            $state = Cache::store()->pull($cacheKey);
        }

        if (!is_array($state)
            || ($state['scope'] ?? '') !== $scope
            || ($state['client_hash'] ?? '') !== $this->clientHash()
        ) {
            return $this->failure('challenge_mismatch');
        }

        $issuedAt = (int) ($state['issued_at'] ?? 0);
        $ttl = $this->intConfig('token_ttl', 120, 600);
        if ($issuedAt < 1 || time() - $issuedAt > $ttl || $issuedAt - time() > 30) {
            if ($baseCheck) {
                Cache::delete($cacheKey);
            }
            return $this->failure('challenge_expired');
        }

        $validatedDigest = (string) ($state['validated_digest'] ?? '');
        if ($validatedDigest !== '') {
            if (!hash_equals($validatedDigest, $digest)) {
                if ($baseCheck) {
                    Cache::delete($cacheKey);
                }
                return $this->failure('payload_changed');
            }

            if ($baseCheck) {
                Cache::set($cacheKey, $state, max(1, $ttl - (time() - $issuedAt)));
            }
            return ['status' => 200, 'msg' => '验证成功'];
        }

        $result = $this->validator->verify(
            $payload,
            $this->captchaId(),
            $this->captchaKey(),
            $this->intConfig('server_timeout', 2, 10)
        );
        if (empty($result['success'])) {
            $this->safeLog('provider_verify_failed', [
                'code'      => (string) ($result['code'] ?? 'unknown'),
                'http_code' => (int) ($result['http_code'] ?? 0),
                'curl_code' => (int) ($result['curl_code'] ?? 0),
                'lot_hash'  => substr(hash('sha256', $payload['lot_number']), 0, 16),
            ]);
            return $this->failure('provider_failed');
        }

        if ($baseCheck) {
            $state['validated_digest'] = $digest;
            $remainingTtl = max(1, $ttl - (time() - $issuedAt));
            Cache::set($cacheKey, $state, $remainingTtl);
        }

        return ['status' => 200, 'msg' => '验证成功'];
    }

    private function renderCaptcha($isAdmin, $token)
    {
        $suffix = substr($token, 0, 12);
        $rootId = 'v10-geetest-' . $suffix;
        $mountId = 'v10-geetest-mount-' . $suffix;
        $statusId = 'v10-geetest-status-' . $suffix;
        $retryId = 'v10-geetest-retry-' . $suffix;
        $cancelId = 'v10-geetest-cancel-' . $suffix;
        $language = 'zho';
        $timeout = $this->intConfig('client_timeout', 3000, 30000);
        $mode = $this->verificationMode();
        $riskType = '';
        if ($mode !== 'smart') {
            $riskType = GeetestValidator::buildRiskType(
                $mode,
                number_format(microtime(true), 6, '.', ''),
                substr($token, 0, 32),
                $this->captchaKey()
            );
            if ($riskType === '') {
                return $this->renderUnavailable($isAdmin, '验证形式签名失败，请联系管理员');
            }
        }

        $className = $isAdmin ? 'v10-geetest-admin' : 'v10-geetest-front';
        $title = $isAdmin ? '完成人机验证' : '安全验证';

        $html = <<<'HTML'
<style>
  #__ROOT_ID__ { box-sizing: border-box; font-family: -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif; }
  #__ROOT_ID__ *, #__ROOT_ID__ *::before, #__ROOT_ID__ *::after { box-sizing: border-box; }
  #__ROOT_ID__.v10-geetest-front { display: none; }
  #__ROOT_ID__.v10-geetest-front.v10-geetest-fallback { position: fixed; inset: 0; z-index: 3000; display: flex; align-items: center; justify-content: center; padding: 20px; background: rgba(15,23,42,.48); }
  #__ROOT_ID__ .v10-geetest-card { width: min(420px, 100%); padding: 24px; border-radius: 12px; background: #fff; box-shadow: 0 20px 60px rgba(15,23,42,.24); }
  #__ROOT_ID__.v10-geetest-front .v10-geetest-card { display: none; }
  #__ROOT_ID__.v10-geetest-front.v10-geetest-fallback .v10-geetest-card { display: block; }
  #__ROOT_ID__.v10-geetest-admin .v10-geetest-card { width: 100%; padding: 0; border-radius: 0; background: transparent; box-shadow: none; }
  #__ROOT_ID__ .v10-geetest-title { margin: 0 0 14px; color: #1f2937; font-size: 18px; font-weight: 600; line-height: 1.4; }
  #__ROOT_ID__.v10-geetest-admin .v10-geetest-title { display: none; }
  #__ROOT_ID__ .v10-geetest-mount { min-height: 44px; }
  #__ROOT_ID__ .v10-geetest-status { min-height: 22px; margin-top: 10px; color: #64748b; font-size: 13px; line-height: 22px; }
  #__ROOT_ID__ .v10-geetest-status[data-state="error"] { color: #dc2626; }
  #__ROOT_ID__ .v10-geetest-status[data-state="success"] { color: #059669; }
  #__ROOT_ID__ .v10-geetest-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px; }
  #__ROOT_ID__.v10-geetest-admin .v10-geetest-actions { justify-content: flex-start; margin-top: 8px; }
  #__ROOT_ID__.v10-geetest-admin .v10-geetest-cancel { display: none; }
  #__ROOT_ID__ .v10-geetest-button { min-width: 84px; height: 36px; padding: 0 16px; border: 1px solid #d1d5db; border-radius: 6px; color: #374151; background: #fff; cursor: pointer; }
  #__ROOT_ID__ .v10-geetest-button:hover { border-color: #2563eb; color: #2563eb; }
  #__ROOT_ID__ .v10-geetest-button:disabled { cursor: not-allowed; opacity: .55; }
  #__ROOT_ID__ .v10-geetest-retry { color: #fff; border-color: #2563eb; background: #2563eb; }
  #__ROOT_ID__ .v10-geetest-retry:hover { color: #fff; background: #1d4ed8; }
  @media (max-width: 750px) {
    #__ROOT_ID__ .v10-geetest-card { padding: 20px; }
    #__ROOT_ID__ .v10-geetest-actions { flex-direction: column; }
    #__ROOT_ID__ .v10-geetest-button { width: 100%; height: 44px; }
  }
</style>
<div id="__ROOT_ID__" class="__ROOT_CLASS__">
  <div class="v10-geetest-card" role="dialog" aria-modal="__ARIA_MODAL__" aria-labelledby="__ROOT_ID__-title">
    <div id="__ROOT_ID__-title" class="v10-geetest-title">__TITLE__</div>
    <div id="__MOUNT_ID__" class="v10-geetest-mount"></div>
    <div id="__STATUS_ID__" class="v10-geetest-status" data-state="loading" aria-live="polite">正在加载人机验证…</div>
    <div class="v10-geetest-actions">
      <button id="__RETRY_ID__" class="v10-geetest-button v10-geetest-retry" type="button" hidden>重试</button>
      <button id="__CANCEL_ID__" class="v10-geetest-button v10-geetest-cancel" type="button">取消</button>
    </div>
  </div>
</div>
<script>
(function () {
  'use strict';
  var root = document.getElementById(__ROOT_JSON__);
  if (!root) return;
  var mount = document.getElementById(__MOUNT_JSON__);
  var status = document.getElementById(__STATUS_JSON__);
  var retry = document.getElementById(__RETRY_JSON__);
  var cancel = document.getElementById(__CANCEL_JSON__);
  var token = __TOKEN_JSON__;
  var captchaId = __CAPTCHA_ID_JSON__;
  var language = __LANGUAGE_JSON__;
  var riskType = __RISK_TYPE_JSON__;
  var timeout = __TIMEOUT__;
  var isAdmin = __IS_ADMIN__;
  var instance = null;
  var finished = false;
  var attemptFailed = false;
  var destroying = false;
  var initializationTimer = null;
  var windowErrorAttached = false;

  function setStatus(message, state) {
    if (!status) return;
    status.textContent = message;
    status.setAttribute('data-state', state || 'loading');
  }

  function notifyFailure(message) {
    if (finished || attemptFailed) return;
    attemptFailed = true;
    clearInitializationWatchdog();
    destroyInstance();
    setStatus(message || '验证加载失败，请重试', 'error');
    if (!isAdmin && root) root.classList.add('v10-geetest-fallback');
    if (retry) { retry.hidden = false; retry.disabled = false; }
  }

  function destroyInstance() {
    if (instance && typeof instance.destroy === 'function') {
      destroying = true;
      try { instance.destroy(); } catch (ignore) {}
      destroying = false;
    }
    instance = null;
  }

  function clearInitializationWatchdog() {
    if (initializationTimer !== null) {
      window.clearTimeout(initializationTimer);
      initializationTimer = null;
    }
    if (windowErrorAttached) {
      window.removeEventListener('error', handleGeeTestWindowError, true);
      windowErrorAttached = false;
    }
  }

  function handleGeeTestWindowError(event) {
    var source = String(event && event.filename ? event.filename : '');
    if (/^https:\/\/[^/]*(?:geetest|geevisit|gsensebot)\.com\//i.test(source)) {
      notifyFailure('极验参数无效或验证服务不可用，请重试');
    }
  }

  function armInitializationWatchdog() {
    clearInitializationWatchdog();
    window.addEventListener('error', handleGeeTestWindowError, true);
    windowErrorAttached = true;
    initializationTimer = window.setTimeout(function () {
      notifyFailure('人机验证初始化超时，请重试');
    }, timeout);
  }

  function cancelCaptcha() {
    if (finished) return;
    finished = true;
    clearInitializationWatchdog();
    destroyInstance();
    if (typeof window.captchaCheckCancel === 'function') {
      window.captchaCheckCancel();
    } else if (root) {
      root.style.display = 'none';
    }
  }

  function loadSdk() {
    if (typeof window.initGeetest4 === 'function') return Promise.resolve();
    if (window.__v10Geetest4LoaderPromise) return window.__v10Geetest4LoaderPromise;
    window.__v10Geetest4LoaderPromise = new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      var settled = false;
      var timer = window.setTimeout(function () {
        if (settled) return;
        settled = true;
        reject(new Error('sdk_timeout'));
      }, timeout);
      script.src = 'https://static.geetest.com/v4/gt4.js';
      script.async = true;
      script.setAttribute('data-v10-geetest4-sdk', '1');
      script.onload = function () {
        if (settled) return;
        settled = true;
        window.clearTimeout(timer);
        if (typeof window.initGeetest4 === 'function') resolve();
        else reject(new Error('sdk_invalid'));
      };
      script.onerror = function () {
        if (settled) return;
        settled = true;
        window.clearTimeout(timer);
        reject(new Error('sdk_load_failed'));
      };
      document.head.appendChild(script);
    }).catch(function (error) {
      window.__v10Geetest4LoaderPromise = null;
      throw error;
    });
    return window.__v10Geetest4LoaderPromise;
  }

  function startCaptcha() {
    clearInitializationWatchdog();
    finished = false;
    attemptFailed = false;
    destroyInstance();
    if (!isAdmin && root) root.classList.remove('v10-geetest-fallback');
    if (mount) mount.innerHTML = '';
    if (retry) { retry.hidden = true; retry.disabled = true; }
    setStatus('正在加载人机验证…', 'loading');
    armInitializationWatchdog();
    loadSdk().then(function () {
      if (finished || attemptFailed) return;
      var options = {
        captchaId: captchaId,
        product: isAdmin ? 'float' : 'bind',
        language: language,
        protocol: 'https://',
        timeout: timeout
      };
      if (riskType) options.riskType = riskType;
      window.initGeetest4(options, function (captcha) {
        if (finished || attemptFailed) {
          if (captcha && typeof captcha.destroy === 'function') captcha.destroy();
          return;
        }
        if (!captcha
            || typeof captcha.onReady !== 'function'
            || typeof captcha.onSuccess !== 'function'
            || typeof captcha.onError !== 'function') {
          notifyFailure('人机验证初始化失败，请重试');
          return;
        }
        instance = captcha;
        if (isAdmin && mount && typeof captcha.appendTo === 'function') {
          captcha.appendTo(mount);
        }
        captcha.onReady(function () {
          if (finished || attemptFailed) return;
          clearInitializationWatchdog();
          setStatus(isAdmin ? '请点击上方验证按钮' : '请完成人机验证', 'ready');
          if (!isAdmin) {
            if (typeof captcha.showCaptcha === 'function') captcha.showCaptcha();
            else notifyFailure('当前极验第四代 SDK 不支持绑定式验证');
          }
        });
        captcha.onSuccess(function () {
          if (finished || attemptFailed) return;
          var result = captcha.getValidate();
          if (!result || !result.lot_number || !result.captcha_output || !result.pass_token || !result.gen_time) {
            notifyFailure('验证结果不完整，请重试');
            return;
          }
          var payload = JSON.stringify({
            provider: 'geetest4',
            lot_number: String(result.lot_number),
            captcha_output: String(result.captcha_output),
            pass_token: String(result.pass_token),
            gen_time: String(result.gen_time)
          });
          finished = true;
          clearInitializationWatchdog();
          setStatus('验证已通过', 'success');
          if (retry) retry.hidden = true;
          if (typeof window.captchaCheckSuccsss === 'function') {
            window.captchaCheckSuccsss(true, payload, token, false);
            window.setTimeout(destroyInstance, 0);
          } else {
            finished = false;
            notifyFailure('系统验证回调不存在');
          }
        });
        captcha.onError(function () {
          notifyFailure('极验参数无效或验证服务不可用，请重试');
        });
        if (typeof captcha.onClose === 'function') {
          captcha.onClose(function () {
            if (!destroying && !attemptFailed) cancelCaptcha();
          });
        }
        if (typeof captcha.onFail === 'function') {
          captcha.onFail(function () { setStatus('未通过验证，请再试一次', 'error'); });
        }
      });
    }).catch(function () {
      notifyFailure('人机验证服务加载失败，请检查网络后重试');
    });
  }

  if (retry) retry.onclick = startCaptcha;
  if (cancel) cancel.onclick = cancelCaptcha;
  startCaptcha();
})();
</script>
HTML;

        return strtr($html, [
            '__ROOT_ID__'         => $rootId,
            '__ROOT_CLASS__'      => $className,
            '__ARIA_MODAL__'      => $isAdmin ? 'false' : 'true',
            '__TITLE__'           => $title,
            '__MOUNT_ID__'        => $mountId,
            '__STATUS_ID__'       => $statusId,
            '__RETRY_ID__'        => $retryId,
            '__CANCEL_ID__'       => $cancelId,
            '__ROOT_JSON__'       => $this->jsonForScript($rootId),
            '__MOUNT_JSON__'      => $this->jsonForScript($mountId),
            '__STATUS_JSON__'     => $this->jsonForScript($statusId),
            '__RETRY_JSON__'      => $this->jsonForScript($retryId),
            '__CANCEL_JSON__'     => $this->jsonForScript($cancelId),
            '__TOKEN_JSON__'      => $this->jsonForScript($token),
            '__CAPTCHA_ID_JSON__' => $this->jsonForScript($this->captchaId()),
            '__LANGUAGE_JSON__'   => $this->jsonForScript($language),
            '__RISK_TYPE_JSON__'  => $this->jsonForScript($riskType),
            '__TIMEOUT__'         => (string) $timeout,
            '__IS_ADMIN__'        => $isAdmin ? 'true' : 'false',
        ]);
    }

    private function renderUnavailable($isAdmin, $message)
    {
        $id = 'v10-geetest-unavailable-' . substr(hash('sha256', $message . microtime(true)), 0, 12);
        $messageHtml = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $wrapperStyle = $isAdmin
            ? 'padding:10px 12px;border:1px solid #fca5a5;border-radius:6px;background:#fef2f2;color:#b91c1c;font-size:13px;line-height:20px;'
            : 'position:fixed;inset:0;z-index:3000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(15,23,42,.48);';
        $cardOpen = $isAdmin ? '' : '<div style="width:min(420px,100%);padding:24px;border-radius:12px;background:#fff;color:#b91c1c;box-shadow:0 20px 60px rgba(15,23,42,.24);">';
        $cardClose = $isAdmin ? '' : '</div>';

        return '<div id="' . $id . '" style="' . $wrapperStyle . '">'
            . $cardOpen . $messageHtml
            . '<div style="margin-top:12px;"><button type="button" style="height:36px;padding:0 16px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;" onclick="if(typeof captchaCheckCancel===\'function\'){captchaCheckCancel();}else{document.getElementById(\'' . $id . '\').style.display=\'none\';}">关闭</button></div>'
            . $cardClose . '</div>';
    }

    private function failure($code)
    {
        return [
            'status' => 400,
            'msg'    => '人机验证失败，请重新完成验证',
            'code'   => (string) $code,
        ];
    }

    private function isConfigured()
    {
        return (bool) preg_match('/^[A-Za-z0-9]{32}$/', $this->captchaId())
            && (bool) preg_match('/^[A-Za-z0-9]{32}$/', $this->captchaKey());
    }

    private function captchaId()
    {
        $environment = getenv('GEETEST_CAPTCHA_ID');
        return trim((string) ($environment !== false && $environment !== ''
            ? $environment
            : ($this->config['captcha_id'] ?? '')));
    }

    private function captchaKey()
    {
        $environment = getenv('GEETEST_CAPTCHA_KEY');
        return trim((string) ($environment !== false && $environment !== ''
            ? $environment
            : ($this->config['captcha_key'] ?? '')));
    }

    private function verificationMode()
    {
        $allowed = ['smart', 'ai', 'slide', 'match', 'winlinze', 'nine', 'word', 'phrase', 'icon'];
        $mode = (string) ($this->config['verification_mode'] ?? 'smart');
        return in_array($mode, $allowed, true) ? $mode : 'smart';
    }

    private function intConfig($name, $minimum, $maximum)
    {
        $value = (int) ($this->config[$name] ?? $this->defaults[$name] ?? $minimum);
        return max((int) $minimum, min((int) $maximum, $value));
    }

    private function challengeKey($token)
    {
        return 'v10_geetest_challenge_' . hash('sha256', (string) $token);
    }

    private function rateLimitExceeded($action, $scope, $limit)
    {
        $bucket = (int) floor(time() / 60);
        $key = 'v10_geetest_rate_' . preg_replace('/[^a-z_]/', '', (string) $action)
            . '_' . preg_replace('/[^a-z_]/', '', (string) $scope)
            . '_' . $this->clientHash() . '_' . $bucket;
        $count = (int) Cache::get($key, 0) + 1;
        Cache::set($key, $count, 120);
        return $count > (int) $limit;
    }

    private function clientHash()
    {
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        return substr(hash('sha256', $this->clientIp() . '|' . substr($agent, 0, 256)), 0, 32);
    }

    private function clientIp()
    {
        $peer = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        if (!filter_var($peer, FILTER_VALIDATE_IP)) {
            $peer = '';
        }

        if ($peer !== '' && $this->isPrivateOrReservedIp($peer)) {
            $forwarded = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
                ? explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])
                : [];
            // A trusted reverse proxy normally appends its observed client address.
            // Walk from the right so a caller-controlled leftmost value cannot bypass
            // the supplementary rate limit or change the challenge binding.
            foreach (array_reverse($forwarded) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP)
                    && !$this->isPrivateOrReservedIp($candidate)
                ) {
                    return $candidate;
                }
            }
        }

        if ($peer !== '') {
            return $peer;
        }
        if (function_exists('get_client_ip')) {
            $fallback = (string) get_client_ip();
            if (filter_var($fallback, FILTER_VALIDATE_IP)) {
                return $fallback;
            }
        }
        return '0.0.0.0';
    }

    private function isPrivateOrReservedIp($ip)
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    private function requestScope()
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = '/' . ltrim((string) $path, '/');
        if (defined('DIR_ADMIN')) {
            $adminDirectory = trim((string) DIR_ADMIN, '/');
            if ($adminDirectory !== ''
                && preg_match('#^/' . preg_quote($adminDirectory, '#') . '(?:/|$)#i', $path)
            ) {
                return 'admin';
            }
        }
        return 'front';
    }

    private function jsonForScript($value)
    {
        return json_encode(
            (string) $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
    }

    private function safeLog($event, array $context)
    {
        $parts = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $parts[] = preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) $key)
                    . '=' . substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) $value), 0, 80);
            }
        }
        error_log('[GeetestCaptcha] ' . preg_replace('/[^a-z_]/', '', (string) $event)
            . ($parts ? ' ' . implode(' ', $parts) : ''));
    }
}
