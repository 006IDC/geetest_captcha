# 部署说明

## 一、下载正确文件

用于 V10 后台直接导入的文件是：

```text
geetest_captcha_v1.3.0_zjmf_v10_import.zip
```

不要解压，不要重新压缩，不要上传代码托管平台自动生成的源码 ZIP。

正确安装包的 ZIP 第一层必须是：

```text
geetest_captcha/
├── GeetestCaptcha.php
├── README.md
├── config.php
├── logic/
│   └── GeetestCaptchaLogic.php
└── service/
    └── GeetestValidator.php
```

## 二、V10 后台安装

1. 登录 V10 管理后台，例如 `https://console.example.com/`。
2. 打开插件管理中的验证码插件页面。
3. 上传 `geetest_captcha_v1.3.0_zjmf_v10_import.zip`。
4. 安装并启用“极验行为验证第四代”。
5. 打开插件配置。
6. 填写同一个 GT4 应用的 Captcha ID 和 Captcha Key。
7. 首次部署保持“10 分钟”和“智能自适应”。
8. 保存配置。
9. 在系统安全设置中选择本插件，并勾选需要保护的业务场景。

不同 V10 版本提供的场景开关可能不同。插件只响应宿主系统实际提供的统一验证码契约，不修改 Core。

## 三、凭据配置

推荐从极验第四代控制台复制同一个应用的 ID 和 Key。不要混用第三代凭据。

如果不希望在 V10 插件配置表中保存密钥，可以在 PHP-FPM、Apache 或容器环境中设置：

```text
GEETEST_CAPTCHA_ID=examplecaptchaidexamplecaptchaid
GEETEST_CAPTCHA_KEY=examplekeyexamplekeyexamplekeyex
```

这里的 `example...` 是无效占位符。部署时必须分别替换为自己的 32 位 GT4 值，不能把示例值用于生产。环境变量优先于后台配置。

## 四、网络与域名

项目自己的站点、反向代理和回调域名示例统一使用：

```text
console.example.com
```

插件运行时还必须访问极验官方域名：

```text
浏览器：static.geetest.com
服务器：gcaptcha4.geetest.com
```

极验 SDK 可能继续请求其官方资源域名，应以极验当前控制台和官方部署文档为准。这些域名属于第三方验证码供应商，不能替换为 `example.com`。

服务器二次校验必须使用 HTTPS，并保持系统 CA 证书可用。不要关闭 TLS 证书或主机名校验。

## 五、反向代理

示例站点：`console.example.com`。

反向代理必须删除客户端直接提交的转发头，再由代理正确设置或追加：

```text
X-Forwarded-For
X-Forwarded-Proto
Host
```

插件仅在 `REMOTE_ADDR` 为私网或保留地址时读取 `X-Forwarded-For`，并从右侧选择最近的有效公网地址。错误的代理链会导致验证码 Token 绑定失败或限流效果下降。

## 六、多节点部署

本地 Token 和预检摘要依赖 V10 缓存。多台 Web 节点必须使用同一个共享缓存，例如共享 Redis。节点本地文件缓存会导致请求在不同节点之间切换时验证失败。

负载均衡器不应在验证码弹出到业务提交之间改写 User-Agent，也应避免不必要地改变客户端出口地址。

## 七、安全设置建议

- 登录、注册、重置密码、修改联系方式使用“智能自适应”或交互挑战。
- 不建议在高风险业务中固定使用“一点即过”。
- 对支持“失败若干次后显示验证码”的 V10 版本启用该策略。
- 同时配置 CDN/WAF、登录失败频率、账号级封禁和短信供应商额度保护。
- 不在日志中打印验证负载、Captcha Key 或本地 Token。

## 八、上线验收

至少验证以下场景：

1. 前台验证码能正常弹出。
2. 后台登录验证码能正常渲染。
3. 完成验证码后业务操作成功。
4. 取消或失败不会提交业务。
5. 同一验证结果不能再次使用。
6. 超过配置有效期后必须重新验证。
7. 断开服务器到极验的网络时，高风险业务不会被放行。
8. 浏览器源代码中找不到 Captcha Key。
9. 多节点环境下跨节点提交仍正常。
10. 实际选择的验证形态与极验控制台能力一致。

## 九、卸载与回滚

插件不创建业务表。卸载不会删除用户业务数据，但会移除插件登记。升级时使用稳定身份和幂等 `upgrade()`，正常情况下无需卸载重装。

回滚前备份 V10 文件和数据库，并确保旧版本仍兼容当前 GT4 配置。不要通过直接覆盖单个 PHP 文件实施长期升级。
