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
|  机场主部署只需改 1 处：
|     ★ 第 34 行 ★   private $encryptKey = '';
|     去 Telegram 打包机器人 → 选你的配置 → 「查看加密密钥」复制粘贴。
|     不填或填错 → 客户端解不出节点列表为空。
|
|==============================================================================
*/

namespace App\Protocols;

class Apex extends ClashMeta
{
    // Xboard 的 ProtocolManager 用 $flags 数组；V2Board 的 ClientController 用 $flag 字符串。两个都设。
    public $flag = 'apex';
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
