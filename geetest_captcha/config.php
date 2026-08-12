<?php

return [
    'module_name' => [
        'title' => '名称',
        'type'  => 'text',
        'value' => '极验行为验证第四代',
        'tip'   => '验证码插件显示名称',
        'size'  => 300,
    ],
    'captcha_id' => [
        'title'   => 'Captcha ID',
        'type'    => 'text',
        'value'   => '',
        'tip'     => '极验第四代后台获取的 32 位验证 ID（可在服务器使用 GEETEST_CAPTCHA_ID 环境变量覆盖）',
        'size'    => 360,
        'rule'    => [
            'require'  => true,
            'alphaNum' => true,
        ],
        'message' => [
            'require'  => 'Captcha ID 不能为空',
            'alphaNum' => 'Captcha ID 只能包含字母和数字',
        ],
    ],
    'captcha_key' => [
        'title'   => 'Captcha Key',
        'type'    => 'password',
        'value'   => '',
        'tip'     => '极验第四代后台获取的 32 位服务端密钥，严禁放到前端（可使用 GEETEST_CAPTCHA_KEY 环境变量覆盖）',
        'size'    => 360,
        'rule'    => [
            'require'  => true,
            'alphaNum' => true,
        ],
        'message' => [
            'require'  => 'Captcha Key 不能为空',
            'alphaNum' => 'Captcha Key 只能包含字母和数字',
        ],
    ],
    'token_ttl' => [
        'title'   => '验证结果有效期',
        'type'    => 'select',
        'value'   => '600',
        'tip'     => '本地一次性 Token 的有效期，不超过极验 lot_number 约 10 分钟的有效窗口。',
        'options' => [
            '120' => ['value' => '120', 'label' => '2 分钟'],
            '300' => ['value' => '300', 'label' => '5 分钟'],
            '600' => ['value' => '600', 'label' => '10 分钟（默认）'],
        ],
    ],
    'verification_mode' => [
        'title'   => '前端应用样式',
        'type'    => 'select',
        'value'   => 'smart',
        'tip'     => '默认由极验智能自适应；指定形态前须在极验后台开启风控融合强校验。一点即过不适合高风险登录。',
        'options' => [
            'smart'    => ['value' => 'smart', 'label' => '智能自适应（默认、推荐）'],
            'ai'       => ['value' => 'ai', 'label' => '一点即过验证（低安全）'],
            'slide'    => ['value' => 'slide', 'label' => '滑动拼图验证'],
            'match'    => ['value' => 'match', 'label' => '消消乐验证'],
            'winlinze' => ['value' => 'winlinze', 'label' => '五子棋验证'],
            'nine'     => ['value' => 'nine', 'label' => '九宫格验证'],
            'word'     => ['value' => 'word', 'label' => '文字点选验证'],
            'phrase'   => ['value' => 'phrase', 'label' => '语序点选验证'],
            'icon'     => ['value' => 'icon', 'label' => '图标点选验证'],
        ],
    ],
];
