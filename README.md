# GeeTest CAPTCHA v4 for ZJMF-CBAP V10

[![CI](https://github.com/006IDC/geetest_captcha/actions/workflows/ci.yml/badge.svg)](https://github.com/006IDC/geetest_captcha/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/006IDC/geetest_captcha)](https://github.com/006IDC/geetest_captcha/releases)
[![License](https://img.shields.io/github/license/006IDC/geetest_captcha)](LICENSE)

由 [零零陆云计算（006IDC）](https://github.com/006IDC) 独立维护。

适用于 ZJMF-CBAP V10 的极验行为验证第四代（GT4）插件，为登录、注册、后台登录、重置密码以及验证或修改联系方式等安全操作提供人机验证。

当前版本：`1.3.0`

## 主要功能

- GeeTest CAPTCHA v4 Web SDK；
- 服务端 HTTPS 二次校验；
- HMAC-SHA256 `sign_token`；
- 智能自适应、一点即过、滑动拼图、消消乐、五子棋、九宫格、文字点选、语序点选和图标点选；
- 2、5、10 分钟验证结果有效期；
- 256 位一次性 Token；
- 前台、后台和客户端环境绑定；
- 初始化与验证频率限制；
- 验证结果防重放；
- 供应商异常时失败关闭；
- 支持原位升级，无需卸载重装。

## 下载与安装

请从仓库的 Releases 页面下载：

```text
geetest_captcha_v1.3.0_zjmf_v10_import.zip
```

该 ZIP 已按 V10 插件导入格式打包完成。不要解压或重新压缩，直接在 V10 后台的验证码插件管理页面上传、安装并启用。

> GitHub 自动生成的 Source code ZIP 不是 V10 插件安装包，请勿用于后台导入。

安装后填写同一个 GT4 应用的 Captcha ID 和 Captcha Key，并在系统安全设置中选择需要启用人机验证的业务场景。完整步骤见 [部署说明](DEPLOYMENT.md)。

“智能自适应”直接使用极验控制台为当前应用配置的验证策略。选择滑动拼图、一点即过、文字点选等指定形态前，必须先在极验控制台为该应用启用“风控融合模式”并开通对应形态；否则初始化可能失败或返回 `-50001`。

## 系统要求

- ZJMF-CBAP V10；
- PHP 7.3 或更高版本；
- PHP 扩展：`curl`、`json`、`hash`；
- 正常工作的 V10 缓存；多节点部署应使用共享缓存；
- 浏览器和服务器能够访问极验官方服务。

插件运行需要访问极验官方端点 `static.geetest.com` 和 `gcaptcha4.geetest.com`。站点、反向代理和隐私政策中的示例域名统一使用 `example.com`。

## 安全说明

- Captcha ID 可用于浏览器初始化；Captcha Key 始终保留在服务端。
- 浏览器完成挑战后，插件仍会调用极验服务端接口进行二次校验。
- 只有极验同时返回请求成功和验证成功时，业务才会继续。
- 网络超时、TLS 错误、异常响应或供应商拒绝均不会放行业务。
- 本地 Token 在最终校验时一次性消费，不能重复使用。
- 插件限流是纵深防护，不能代替 CDN/WAF、登录失败限制、短信频率限制和账号级风控。

更多信息：

- [部署说明](DEPLOYMENT.md)
- [安全策略](SECURITY.md)
- [隐私与第三方服务](PRIVACY.md)
- [V10 兼容性](V10-COMPATIBILITY.md)
- [第三方项目声明](NOTICE.md)

## 开源许可

本项目采用 [MIT License](LICENSE)。本项目是独立的开源集成，不是 GeeTest 或 ZJMF-CBAP 官方项目。
