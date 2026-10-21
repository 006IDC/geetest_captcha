# V10 极验 GT4 插件（开源版）

## 产品介绍

适用于 ZJMF-CBAP V10 的公开极验第四代插件，为启用的安全业务场景提供人机验证。

## 版本概览

| 项目 | 公开说明 |
| --- | --- |
| 公开发行版本 | [v1.3.0](https://github.com/006IDC/v10-geetest-gt4-public/releases/tag/v1.3.0) |
| 适用环境 | ZJMF-CBAP V10 / PHP 7.3+；curl、json、hash 与 V10 缓存 |
| 使用边界 | 此页对应公开 v1.3.0。公开说明要求多节点使用共享缓存，浏览器与服务器均需能访问极验官方服务；目标部署兼容性仍需核对。 |

版本以本仓公开 Release 为准；完整技术说明见[运行与维护说明](docs/repository-guide.md)。

## 主要功能

- 极验 v4 Web SDK、服务端 HTTPS 二次校验及 HMAC-SHA256 sign_token。
- 多种验证形态，以及 2、5、10 分钟结果有效期。
- 公开版说明中的一次性 Token、环境绑定、限流、防重放与供应商异常失败关闭。

## 使用与升级

从 [Releases](https://github.com/006IDC/v10-geetest-gt4-public/releases) 下载 `geetest_captcha_v1.3.0_zjmf_v10_import.zip`，不要解压或重新压缩，直接在 V10 验证码插件管理页上传、安装并启用。配置同一应用的 Captcha ID 和 Captcha Key；指定验证形态须先在极验控制台开通。升级和系统要求见[技术说明](docs/repository-guide.md)及[部署说明](DEPLOYMENT.md)。

## 版本演进

版本变化见[变更记录](CHANGELOG.md)。适用环境和部署检查以对应版本文档为准。

## 许可证与维护

由[零零陆云计算（006IDC）](https://github.com/006IDC)独立维护。采用 [MIT License](LICENSE)。这是独立开源集成，并非 GeeTest 或 ZJMF-CBAP 官方项目；第三方声明见 [NOTICE](NOTICE.md)，另见[安全策略](SECURITY.md)与[隐私说明](PRIVACY.md)。
