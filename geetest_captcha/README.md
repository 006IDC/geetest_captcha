# 极验行为验证第四代

ZJMF-CBAP V10 图形验证码插件。稳定身份为：

```text
captcha / GeetestCaptcha / geetest_captcha
```

## 保护场景

插件使用 V10 统一验证码契约。系统在安全设置中为某项操作启用图形验证后，该操作会调用本插件。具体可选场景取决于 V10 版本，通常包括注册、会员登录、后台登录、重置密码、验证或修改手机/邮箱等。

插件不会修改 V10 Core，也不会自行增加安全设置中不存在的业务开关。

## GT4 校验流程

1. 浏览器使用公开的 Captcha ID 初始化极验第四代 Web SDK。
2. 用户完成验证后，浏览器取得 `lot_number`、`captcha_output`、`pass_token` 和 `gen_time`。
3. V10 业务接口把上述字段和本地 Token 传给插件 `Verify()`。
4. 插件在服务端用 Captcha Key 对 `lot_number` 生成 HMAC-SHA256 `sign_token`。
5. 插件通过固定 HTTPS 端点调用极验 `/validate` 二次校验。
6. 只有供应商返回成功时才允许业务继续。

Captcha Key 不会进入浏览器。网络、TLS、初始化、响应解析或二次校验失败时均拒绝业务。

## 防爆破与重放保护

- 本地 Token 使用 256 位安全随机数，绑定前台/后台、客户端地址和 User-Agent。
- V10 基础预检会保存已校验负载摘要，最终校验一次性消费 Token。
- 预检后替换负载、最终校验后重放、跨环境使用或超时均拒绝。
- 每客户端每分钟最多初始化 20 次、校验 30 次。
- 位于反向代理后方时，仅在直连地址属于私网或保留地址时读取 `X-Forwarded-For`，并从代理链右侧选择最近的有效公网地址。
- 密钥、完整验证结果和本地 Token 不写入日志。

插件内限流只作为纵深保护，不能替代 CDN/WAF、V10 登录失败策略、账号级风控或短信供应商限流。

## 有效期

配置页可选择 2、5 或 10 分钟，默认 10 分钟。该值只控制本地一次性 Token，不延长极验凭据自身的有效期。

## 验证形态

- 智能自适应：默认推荐，由极验策略选择交互形式。
- 一点即过：交互最轻，不建议作为登录、注册、找回密码等高风险操作的固定模式。
- 可指定滑动拼图、消消乐、五子棋、九宫格、文字点选、语序点选或图标点选。

强制指定形态时，插件会在服务端签名 `riskType`，Captcha Key 不会下发。使用前必须在极验控制台为当前应用开通对应能力；否则使用智能自适应。

## 配置

必须填写来自同一个 GT4 应用的 Captcha ID 和 Captcha Key。GT3 与 GT4 凭据不能混用。

也可通过 PHP 运行环境设置：

```text
GEETEST_CAPTCHA_ID=examplecaptchaidexamplecaptchaid
GEETEST_CAPTCHA_KEY=examplekeyexamplekeyexamplekeyex
```

以上只是格式占位符，不能用于真实验证。环境变量优先于后台保存值。

## 运行要求

- PHP 7.3+；
- `curl`、`json`、`hash` 扩展；
- 服务端可以通过 HTTPS 访问极验二次校验端点；
- 浏览器可以加载极验第四代 Web SDK；
- 多节点部署使用共享缓存。

## 升级

插件支持从旧版本原位升级。升级会保留现有 Captcha ID、Captcha Key 和其他有效配置，无需卸载重装。
