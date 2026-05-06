<?php

/*
|==============================================================================
|  Apex 协议处理器（双面板兼容：Xboard / V2Board）
|==============================================================================
|
|  设计：直接继承 ClashMeta，复用其全部 build* 方法（Vmess/Vless/Trojan/
|        AnyTLS/Hysteria2/...），在 handle() 收尾时把输出套一层 XOR 加密。
|
|        - SNI / skip-cert-verify / utls / ECH / Reality / xhttp / grpc-opts
|          等所有协议字段细节全部跟 ClashMeta 行为一致。上游修任何 bug 自动吃。
|        - 客户端 ?flag=apex 拿加密格式；?flag=meta 仍走原 ClashMeta 拿明文。
|        - 部署前提：app/Protocols/ClashMeta.php 必须存在（Xboard / V2Board
|          默认都自带）。找不到 ClashMeta 类时框架会报 "Class not found"。
|
|  机场主部署需要改 1~2 处：
|
|  【必改 1】加密密钥
|     ★ 第 75 行 ★   private $encryptKey = '';
|     去 Telegram 打包机器人 → 选你的配置 → 「查看加密密钥」复制粘贴。
|     不填或填错 → 客户端解不出节点列表为空。
|
|  【必改 2，仅当客户端用了自定义 UA】协议 flag
|     ★ 第 71 行 ★   public $flag  = 'apex';
|     ★ 第 72 行 ★   public $flags = ['apex'];
|     这两处必须**同时改成同一个值**，且与打包机器人里 APEX_FLAG 一致。
|     - 默认 UA（`Apex/v{版本号}`）   → 都填 'apex'，已填好不动
|     - 自定义 UA `MyVPN/v1.0`        → 都填 'myvpn'
|     - 不知道填什么 → 打包机器人「查看加密密钥」也会同时显示当前 flag 值
|     flag 不匹配 → 服务端 fallback 到通用订阅（base64 URI 列表），客户端
|     拉到非加密内容报错，节点为空。
|
|------------------------------------------------------------------------------
|  部署方式（Xboard 官方 docker 跟传统 LNMP 流程不一样，分开看）：
|
|  【A. 传统 LNMP / 宝塔 / 1Panel】
|     1) 把本文件上传到 /www/wwwroot/<panel>/app/Protocols/Apex.php
|     2) 改上面 $encryptKey（必改）+ $flag/$flags（自定义 UA 才改）
|     3) 重启 PHP-FPM 清 OPcache：
|          systemctl restart php-fpm        # 或宝塔/1Panel 控制台点重启 PHP
|          php /www/wwwroot/<panel>/artisan optimize:clear   # 清类映射缓存
|
|  【B. Docker（cedar2025/xboard 官方镜像，使用 Swoole + Octane）】
|     ⚠ 跟 LNMP 完全不同：没 php-fpm 可重启，**Octane worker 把 PHP 代码
|        加载进内存常驻**，不 reload 改了不生效。
|
|     1) 用 volume 挂载本文件，**不要 docker exec 直接改容器内文件**——
|        docker pull 拉新镜像后改动会被覆盖。docker-compose.yml 加：
|          services:
|            xboard:
|              volumes:
|                - ./Apex.php:/www/app/Protocols/Apex.php:ro
|     2) 改本地 ./Apex.php 的 $encryptKey
|     3) reload Octane（**关键步骤，否则 worker 还跑老代码**）：
|          docker exec <container> php artisan octane:reload
|        或暴力重启容器：
|          docker restart <container>
|     4) 验证：
|          docker exec <container> ls -la /www/app/Protocols/Apex.php
|          docker exec <container> php -l /www/app/Protocols/Apex.php
|
|==============================================================================
*/

namespace App\Protocols;

class Apex extends ClashMeta
{
    // Xboard 的 ProtocolManager 用 $flags 数组；V2Board 的 ClientController 用 $flag 字符串。两个都设。
    public $flag  = 'apex';
    public $flags = ['apex'];

    // 必填：加密密钥（必须与客户端打包时 XOR_KEY 完全一致）
    private $encryptKey = '';

    public function handle()
    {
        if ($this->encryptKey === '') {
            throw new \RuntimeException(
                'Apex.php: $encryptKey 未配置。打开 Telegram 打包机器人 → '
                . '「查看加密密钥」，把那串值粘到 Apex.php 的 '
                . "private \$encryptKey = ''; 空引号里。"
            );
        }

        $result = parent::handle();

        // Xboard：parent 返回 Laravel Response（带 headers）→ 替换 body，保留 headers
        // V2Board：parent 返回 string（headers 已通过 header() 全局发出）→ 直接加密返回
        if (is_object($result)
            && method_exists($result, 'getContent')
            && method_exists($result, 'setContent')) {
            $result->setContent($this->encrypt((string) $result->getContent()));
            return $result;
        }

        return $this->encrypt((string) $result);
    }

    private function encrypt(string $content): string
    {
        $inner = base64_encode($content);
        $key = $this->encryptKey;
        $klen = strlen($key);
        $xor = '';
        for ($i = 0, $n = strlen($inner); $i < $n; $i++) {
            $xor .= chr(ord($inner[$i]) ^ ord($key[$i % $klen]));
        }
        return base64_encode($xor);
    }
}
