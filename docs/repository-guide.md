# V10 极验 GT4 插件 · 使用说明

面向 ZJMF-CBAP V10 的极验第四代人机验证集成，用于宿主系统实际提供并启用的安全业务场景。

[产品首页](../README.md) · [部署说明](../DEPLOYMENT.md) · [版本下载](https://github.com/006IDC/v10-geetest-gt4-public/releases) · [更新记录](../CHANGELOG.md)

## 使用前确认

- 当前公开发行：`1.3.0`。实际兼容性以[V10 兼容性说明](../V10-COMPATIBILITY.md)和目标实例验证为准。
- 需要 ZJMF-CBAP V10、PHP 7.3+，启用 `curl`、`json`、`hash` 扩展并保证 V10 缓存正常。
- 多 Web 节点必须使用共享缓存；浏览器需能访问 `static.geetest.com`，服务器需能访问 `gcaptcha4.geetest.com` 等极验官方服务。
- 需要同一个 GT4 应用的 Captcha ID 与 Captcha Key，不能混用第三代凭据。

## 安装与启用

1. 从 [Releases](https://github.com/006IDC/v10-geetest-gt4-public/releases) 下载 `geetest_captcha_v1.3.0_zjmf_v10_import.zip`。
2. 不解压、不重新压缩，在 V10 后台验证码插件管理页直接上传、安装并启用。
3. 填写同一个应用的 ID 与 Key；首次部署使用“智能自适应”和“10 分钟”。
4. 在系统安全设置中选择插件，勾选需要保护的场景。不同 V10 版本的开关可能不同，插件不修改 Core。
5. 按[部署说明](../DEPLOYMENT.md)完成成功、取消、过期、重放及供应商异常等检查。

GitHub 自动生成的 Source code ZIP 不是 V10 安装包。登录、注册、后台登录、重置密码及验证或修改联系方式等场景，均以宿主实际提供的验证码契约为准。

## 验证形态与流程

- **智能自适应**：使用极验控制台为当前应用配置的验证组合。
- **指定形态**：支持一点即过、滑动拼图、消消乐、五子棋、九宫格、文字点选、语序点选与图标点选。使用前须在极验控制台启用“风控融合模式”并开通对应形态，否则可能初始化失败或返回 `-50001`。
- **有效期**：可选 2、5、10 分钟。

浏览器加载 GeeTest v4 Web SDK 完成挑战后，服务端通过 HTTPS 二次校验及 HMAC-SHA256 `sign_token` 检查，再消费与前后台和客户端环境绑定的 256 位一次性 Token。结果不能重复使用；插件同时限制初始化与验证频率。

## 安全与使用限制

- Captcha ID 可用于浏览器初始化；Captcha Key 只保留在服务端。
- 只有极验同时返回请求成功和验证成功，业务才能继续。超时、TLS 错误、异常响应或供应商拒绝均失败关闭。
- 一点即过不提供图形交互拦截能力，不建议单独用于高风险业务。
- 插件限流不能代替 CDN/WAF、登录失败限制、短信频率限制与账号级风控。
- 站点和反向代理示例使用 `example.com`；极验官方服务域名不能替换为示例域名。
- 保持稳定身份并原位执行幂等 `upgrade()`；正常升级不需卸载重装，详细步骤见[部署说明](../DEPLOYMENT.md)。

## 参考与许可

- [安全策略](../SECURITY.md)
- [隐私与第三方服务](../PRIVACY.md)
- [V10 兼容性](../V10-COMPATIBILITY.md)
- [第三方声明](../NOTICE.md)

采用 [MIT License](../LICENSE)。由[零零陆云计算（006IDC）](https://github.com/006IDC)独立维护，并非 GeeTest 或 ZJMF-CBAP 官方项目。
