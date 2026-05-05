<?php

/*
|==============================================================================
|  ★★★  机场主请先看这里：你只需要改 1~2 个地方  ★★★
|==============================================================================
|
|  【必改 1】加密密钥  ($encryptKey)
|     ★ 跳到第 71 行 ★    private $encryptKey = '';
|     去 Telegram 打包机器人 → 选你的配置 → 「查看加密密钥」复制粘贴。
|     不改 → 客户端拉到的订阅解不出 → 节点列表为空。
|
|  【必改 2，仅当你在打包机器人改过 UA】协议 flag  ($flag + $flags)
|     ★ 跳到第 61 行 ★    public $flag = 'apex';
|     ★ 跳到第 810 行 ★   public $flags = ['apex'];
|     ⚠ 这两处必须同时改成同一个值！
|     - 用打包机器人**默认 UA**（Apex/v版本号） → 都填 'apex'，已经填好，不动
|     - 自定义 UA 例 `MyVPN/v1`               → 都填 'myvpn'
|     - 不知道填什么 → 打包机器人「查看加密密钥」会同时显示 flag 值，照抄即可。
|
|  【可选】CDN 中转域名  ($customHost / $customHostByType)
|     ★ 跳到第 83 行 ★   private $customHost = '';
|     ★ 跳到第 89 行 ★   private $customHostByType = [...];
|     99% 机场不用，留空即可。要用 CDN 中转才填。
|
|  其他所有代码无需修改——v2board 和 Xboard 面板自动适配。
|==============================================================================
*/

/**
 * Apex 协议处理器（双面板兼容：Xboard / V2Board）
 *
 * Xboard：通过 ProtocolManager 反射 $flags 数组匹配
 * V2Board：通过 ClientController 遍历 glob，匹配 $flag 字符串
 *
 * 客户端请求时附带 ?flag=<APEX_FLAG>（默认 apex），两种面板都能命中本协议。
 */

namespace App\Protocols;

use App\Utils\Helper;
use Symfony\Component\Yaml\Yaml;

trait ApexCore
{
    /*
    |--------------------------------------------------------------------------
    | 必填：客户端 flag（必须与 Telegram 打包机器人里 APEX_FLAG 一致）
    |--------------------------------------------------------------------------
    | 取值规则：
    |   • 用打包机器人**默认 UA**（`Apex/v{版本号}`）→ 这里填 `'apex'`（已经填好）
    |   • 自定义 UA 例 `MyVPN/v1.0`             → 这里填 `'myvpn'`
    |   • 自定义 UA 例 `SpeedUp 2024`           → 这里填 `'speedup'`
    |
    | 不知道填什么 → 打开 Telegram 打包机器人 → 「查看加密密钥」里也会同时
    | 显示当前配置对应的 flag 值，照着粘进去即可。
    |
    | flag 值与客户端不一致 → 服务端会 fallback 到通用订阅（base64 URI 列表），
    | 客户端拉到非加密内容报错，节点列表为空。
    */
    public $flag = 'apex';

    /*
    |--------------------------------------------------------------------------
    | 必填：加密密钥
    |--------------------------------------------------------------------------
    | 打开 Telegram 打包机器人 → 选你的配置 → 「查看加密密钥」，
    | 复制那串值粘到下面 $encryptKey 的空引号里。
    | 不填或填错 → 客户端拉到的订阅解不出，节点列表为空。
    */
    private $encryptKey = '';

    /*
    |--------------------------------------------------------------------------
    | 可选：连接地址改写（不用就留空，99% 的机场不需要填）
    |--------------------------------------------------------------------------
    | 把客户端实际连接的地址，从「节点真实 IP/域名」换成「另一个域名」
    | （如 Cloudflare CDN 中转）。这个域名必须真实存在、能解析到能转发到你
    | 节点的地方。不需要 CDN/中转的整段留空，客户端按真实地址直连。
    */
    // 方式一：所有协议共用一个域名（最常见）
    // 例：private $customHost = 'gtm-sg.mycdn.com';
    private $customHost = '';

    // 方式二：按协议分别设置不同域名（少见，比如 vmess 走 CF、hysteria 走直连边缘）
    // 和上面 $customHost 是「两套独立设置」，不是同一个值。
    // 这里如果设了某协议，对该协议优先生效；没设的协议回退用 $customHost。
    // 用法：删掉行首的 //，把右边的域名换成你自己的真实域名。不需要就整段留注释。
    private $customHostByType = [
        // 'vmess'       => 'vmess.example.com',
        // 'vless'       => 'vless.example.com',
        // 'trojan'      => 'trojan.example.com',
        // 'shadowsocks' => 'ss.example.com',
        // 'hysteria'    => 'hy.example.com',
        // 'hysteria2'   => 'hy2.example.com',
        // 'tuic'        => 'tuic.example.com',
        // 'anytls'      => 'anytls.example.com',
    ];

    // ─── 以下为协议实现，无需修改 ───────────────────────────────────
    // ─── 以下为协议实现，无需修改 ───────────────────────────────────
    // ─── 以下为协议实现，无需修改 ───────────────────────────────────
    private $enableEncryption = true;

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;
        $appName = config('v2board.app_name', 'Apex');

        header("subscription-userinfo: upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}");
        header('profile-update-interval: 24');
        header("content-disposition:attachment;filename*=UTF-8''" . rawurlencode($appName));
        header('profile-web-page-url:' . config('v2board.app_url'));

        // 模板加载优先级：
        //   1. Xboard 数据库里的 clashmeta 模板（机场主在管理后台编辑的版本）
        //   2. resources/rules/custom.clash.yaml（v2board / Xboard 通用，机场主自己放的）
        //   3. resources/rules/default.clash.yaml（v2board / Xboard 出厂模板）
        $config = null;
        if (class_exists('App\\Models\\SubscribeTemplate')) {
            // Xboard：从 v2_subscribe_templates 表读机场主在后台编辑的 clashmeta 模板
            $template = \App\Models\SubscribeTemplate::getContent('clashmeta');
            if (!empty(trim($template ?? ''))) {
                $config = Yaml::parse($template);
            }
        }
        if ($config === null) {
            $customConfig = base_path() . '/resources/rules/custom.clash.yaml';
            $defaultConfig = base_path() . '/resources/rules/default.clash.yaml';
            $path = \File::exists($customConfig) ? $customConfig : $defaultConfig;
            $config = Yaml::parseFile($path);
        }

        $servers = $this->applyCustomHost($servers);

        $proxy = [];
        $proxies = [];

        foreach ($servers as $item) {
            // Xboard 把 tls / network / network_settings / tls_settings 等嵌套在
            // $item['protocol_settings'] 下，v2board 全平铺到 $item 顶层。
            // 为了让下面的 build* 函数（按 v2board 平铺结构写的）两边都跑，
            // 把 protocol_settings 平铺到顶层一份，已有 key 不覆盖。
            if (isset($item['protocol_settings']) && is_array($item['protocol_settings'])) {
                $item = $item + $item['protocol_settings'];
            }

            // Xboard 给每个节点预计算了 $item['password'] 作为认证凭据；
            // v2board 不预算，所有节点共用 $user['uuid']。
            $secret = $item['password'] ?? $user['uuid'];

            if (($item['type'] ?? null) === 'v2node' && isset($item['protocol'])) {
                $item['type'] = $item['protocol'];
            }
            switch ($item['type']) {
                case 'shadowsocks':
                    $proxy[] = self::buildShadowsocks($secret, $item);
                    $proxies[] = $item['name'];
                    break;
                case 'vmess':
                    $proxy[] = self::buildVmess($secret, $item);
                    $proxies[] = $item['name'];
                    break;
                case 'vless':
                    $built = self::buildVless($secret, $item);
                    if ($built !== null) {
                        $proxy[] = $built;
                        $proxies[] = $item['name'];
                    }
                    break;
                case 'trojan':
                    $proxy[] = self::buildTrojan($secret, $item);
                    $proxies[] = $item['name'];
                    break;
                case 'tuic':
                    $proxy[] = self::buildTuic($secret, $item);
                    $proxies[] = $item['name'];
                    break;
                case 'anytls':
                    $proxy[] = self::buildAnyTLS($secret, $item);
                    $proxies[] = $item['name'];
                    break;
                case 'hysteria':
                    $proxy[] = self::buildHysteria($secret, $item);
                    $proxies[] = $item['name'];
                    break;
                case 'hysteria2':
                    $proxy[] = $this->buildHysteria2($secret, $item);
                    $proxies[] = $item['name'];
                    break;
                // Xboard 独有的 3 种协议；v2board 没有这几种节点类型，case 永远不命中也无害。
                case 'socks':
                    $proxy[] = self::buildSocks5($secret, $item);
                    $proxies[] = $item['name'];
                    break;
                case 'http':
                    $proxy[] = self::buildHttp($secret, $item);
                    $proxies[] = $item['name'];
                    break;
                case 'mieru':
                    $proxy[] = self::buildMieru($secret, $item);
                    $proxies[] = $item['name'];
                    break;
            }
        }

        $config['proxies'] = array_merge($config['proxies'] ? $config['proxies'] : [], $proxy);
        foreach ($config['proxy-groups'] as $k => $v) {
            if (!is_array($config['proxy-groups'][$k]['proxies'])) {
                $config['proxy-groups'][$k]['proxies'] = [];
            }
            $isFilter = false;
            foreach ($config['proxy-groups'][$k]['proxies'] as $src) {
                foreach ($proxies as $dst) {
                    if (!$this->isRegex($src)) continue;
                    $isFilter = true;
                    $config['proxy-groups'][$k]['proxies'] = array_values(
                        array_diff($config['proxy-groups'][$k]['proxies'], [$src])
                    );
                    if ($this->isMatch($src, $dst)) {
                        array_push($config['proxy-groups'][$k]['proxies'], $dst);
                    }
                }
                if ($isFilter) continue;
            }
            if ($isFilter) continue;
            $config['proxy-groups'][$k]['proxies'] = array_merge(
                $config['proxy-groups'][$k]['proxies'],
                $proxies
            );
        }

        $config['proxy-groups'] = array_filter($config['proxy-groups'], function ($group) {
            return $group['proxies'];
        });
        $config['proxy-groups'] = array_values($config['proxy-groups']);

        $yaml = Yaml::dump($config, 2, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $yaml = str_replace('$app_name', $appName, $yaml);

        return $this->encryptContent($yaml);
    }

    private function encryptContent(string $content): string
    {
        if (!$this->enableEncryption) {
            return $content;
        }
        if ($this->encryptKey === '') {
            throw new \RuntimeException(
                'Apex.php: $encryptKey 未配置。请打开 Telegram 打包机器人 → '
                . '选你的配置 → 「查看加密密钥」，把那串值复制到 Apex.php 中 '
                . 'private $encryptKey = \'\'; 的空引号里。'
            );
        }

        $inner = base64_encode($content);

        $keyBytes = $this->encryptKey;
        $keyLen = strlen($keyBytes);
        $xored = '';
        for ($i = 0, $n = strlen($inner); $i < $n; $i++) {
            $xored .= chr(ord($inner[$i]) ^ ord($keyBytes[$i % $keyLen]));
        }

        return base64_encode($xored);
    }

    /**
     * 把 tls_settings.ech 的 'cloudflare' / 'custom' 配置翻译成 mihomo 的 ech-opts 字段。
     * 不开 ECH 时不写任何字段。
     */
    private static function applyEch(array &$array, ?array $tlsSettings): void
    {
        if (empty($tlsSettings['ech'])) {
            return;
        }
        if ($tlsSettings['ech'] === 'cloudflare') {
            $array['ech-opts'] = [
                'enable' => true,
                'query-server-name' => 'cloudflare-ech.com',
            ];
        } elseif ($tlsSettings['ech'] === 'custom' && !empty($tlsSettings['ech_config'])) {
            $array['ech-opts'] = [
                'enable' => true,
                'config' => is_array($tlsSettings['ech_config'])
                    ? $tlsSettings['ech_config']
                    : [$tlsSettings['ech_config']],
            ];
        }
    }

    /**
     * xhttp 传输层（VLESS Reality / VLESS XTLS Vision over HTTP/2 多路复用）。
     */
    private static function applyXhttp(array &$array, $server): void
    {
        $xhttpSettings = $server['network_settings'] ?? ($server['networkSettings'] ?? null);
        if (!$xhttpSettings) {
            $array['xhttp-opts'] = [];
            return;
        }
        $array['xhttp-opts'] = [];
        if (isset($xhttpSettings['path'])) $array['xhttp-opts']['path'] = $xhttpSettings['path'];
        if (isset($xhttpSettings['host'])) $array['xhttp-opts']['host'] = $xhttpSettings['host'];
        if (isset($xhttpSettings['mode'])) $array['xhttp-opts']['mode'] = $xhttpSettings['mode'];
    }

    private function applyCustomHost(array $servers): array
    {
        if (empty($this->customHost) && empty($this->customHostByType)) {
            return $servers;
        }
        foreach ($servers as $key => $server) {
            $newHost = null;
            if (isset($this->customHostByType[$server['type']]) &&
                !empty($this->customHostByType[$server['type']])) {
                $newHost = $this->customHostByType[$server['type']];
            } elseif (!empty($this->customHost)) {
                $newHost = $this->customHost;
            }
            if ($newHost && $newHost !== $server['host']) {
                $servers[$key]['host'] = $newHost;
            }
        }
        return $servers;
    }

    private function isMatch($exp, $str)
    {
        return @preg_match($exp, $str);
    }

    private function isRegex($exp)
    {
        return @preg_match($exp, null) !== false;
    }

    public static function buildShadowsocks($password, $server)
    {
        if ($server['cipher'] === '2022-blake3-aes-128-gcm') {
            $serverKey = Helper::getServerKey($server['created_at'], 16);
            $userKey = Helper::uuidToBase64($password, 16);
            $password = "{$serverKey}:{$userKey}";
        }
        if ($server['cipher'] === '2022-blake3-aes-256-gcm') {
            $serverKey = Helper::getServerKey($server['created_at'], 32);
            $userKey = Helper::uuidToBase64($password, 32);
            $password = "{$serverKey}:{$userKey}";
        }
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'ss';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['cipher'] = $server['cipher'];
        $array['password'] = $password;
        $array['udp'] = true;
        if (isset($server['obfs']) && $server['obfs'] === 'http') {
            $array['plugin'] = 'obfs';
            $plugin_opts = ['mode' => 'http'];
            if (isset($server['obfs-host'])) {
                $plugin_opts['host'] = $server['obfs-host'];
            } else {
                $plugin_opts['host'] = '';
            }
            if (isset($server['obfs-path'])) {
                $plugin_opts['path'] = $server['obfs-path'];
            }
            $array['plugin-opts'] = $plugin_opts;
        } elseif ((($server['network'] ?? null) === 'http')
                  && isset(($server['network_settings'] ?? [])['Host'])) {
            $array['plugin'] = 'obfs';
            $networkSettings = $server['network_settings'];
            $plugin_opts = [
                'mode' => 'http',
                'host' => ($networkSettings['Host'] ?? ''),
            ];
            if (isset($networkSettings['path'])) {
                $plugin_opts['path'] = $networkSettings['path'];
            }
            $array['plugin-opts'] = $plugin_opts;
        }
        return $array;
    }

    public static function buildVmess($uuid, $server)
    {
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'vmess';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['uuid'] = $uuid;
        $array['alterId'] = 0;
        $array['cipher'] = 'auto';
        $array['udp'] = true;

        if (!empty($server['tls'])) {
            $array['tls'] = true;
            // v2board 数据库存 snake_case (tls_settings.server_name / allow_insecure)，
            // 老配置可能是 camelCase。两种都兜住，否则 SNI 缺失会导致 TLS 证书校验失败。
            $tlsSettings = $server['tls_settings'] ?? ($server['tlsSettings'] ?? null);
            if ($tlsSettings) {
                $allowInsecure = $tlsSettings['allow_insecure'] ?? $tlsSettings['allowInsecure'] ?? null;
                if ($allowInsecure !== null) {
                    $array['skip-cert-verify'] = ((int) $allowInsecure) === 1;
                }
                $serverName = $tlsSettings['server_name'] ?? $tlsSettings['serverName'] ?? null;
                if (!empty($serverName)) {
                    $array['servername'] = $serverName;
                }
                self::applyEch($array, $tlsSettings);
            }
        }
        $network = $server['network'] ?? null;
        if ($network === 'tcp') {
            $tcpSettings = $server['network_settings'] ?? ($server['networkSettings'] ?? []);
            if (isset($tcpSettings['header']['type']) && $tcpSettings['header']['type'] == 'http') {
                $array['network'] = $tcpSettings['header']['type'];
                if (isset($tcpSettings['header']['request']['headers']['Host'])) {
                    $array['http-opts']['headers']['Host'] = $tcpSettings['header']['request']['headers']['Host'];
                }
                if (isset($tcpSettings['header']['request']['path'])) {
                    $array['http-opts']['path'] = $tcpSettings['header']['request']['path'];
                }
            }
        }
        if ($network === 'ws') {
            $array['network'] = 'ws';
            $wsSettings = $server['network_settings'] ?? ($server['networkSettings'] ?? null);
            if ($wsSettings) {
                $array['ws-opts'] = [];
                if (isset($wsSettings['path']) && !empty($wsSettings['path'])) {
                    $array['ws-opts']['path'] = $wsSettings['path'];
                }
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host'])) {
                    $array['ws-opts']['headers'] = ['Host' => $wsSettings['headers']['Host']];
                }
                if (isset($wsSettings['security'])) {
                    $array['cipher'] = $wsSettings['security'];
                }
            }
        }
        if ($network === 'grpc') {
            $array['network'] = 'grpc';
            $grpcSettings = $server['network_settings'] ?? ($server['networkSettings'] ?? null);
            if ($grpcSettings) {
                $array['grpc-opts'] = [];
                // 同时兜底 snake_case (service_name) 和 camelCase (serviceName)，
                // 不同 v2board 前端版本写库的字段名不一样。
                $serviceName = $grpcSettings['service_name'] ?? $grpcSettings['serviceName'] ?? null;
                if (!empty($serviceName)) {
                    $array['grpc-opts']['grpc-service-name'] = $serviceName;
                }
            }
        }

        return $array;
    }

    public static function buildVless($uuid, $server)
    {
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'vless';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['uuid'] = $uuid;
        $array['udp'] = true;

        if ($server['tls']) {
            $array['tls'] = true;
            $tlsSettings = $server['tls_settings'] ?? [];
            // Xboard 把 Reality 字段放到 protocol_settings.reality_settings；v2board 全在 tls_settings。
            // tls=2 时优先 reality_settings，回落 tls_settings 兼容 v2board。
            $realitySettings = ($server['tls'] == 2)
                ? ($server['reality_settings'] ?? $tlsSettings)
                : $tlsSettings;

            $array['skip-cert-verify'] = ($realitySettings['allow_insecure'] ?? $tlsSettings['allow_insecure'] ?? 0) == 1;
            $array['flow'] = !empty($server['flow']) ? $server['flow'] : '';
            $array['client-fingerprint'] = !empty($tlsSettings['fingerprint']) ? $tlsSettings['fingerprint'] : 'chrome';

            $sni = $realitySettings['server_name'] ?? $tlsSettings['server_name'] ?? '';
            if (!empty($sni)) {
                $array['servername'] = $sni;
            }
            if ($server['tls'] == 2) {
                $publicKey = $realitySettings['public_key'] ?? '';
                // Reality 缺 public_key 写出去也连不上，直接丢弃这条节点，避免整个订阅 500。
                if ($publicKey === '') {
                    return null;
                }
                $array['reality-opts'] = [
                    'public-key' => $publicKey,
                    'short-id'   => $realitySettings['short_id'] ?? '',
                ];
            }
            self::applyEch($array, $tlsSettings);
        }

        if ($server['network'] === 'tcp') {
            $tcpSettings = $server['network_settings'];
            if (isset($tcpSettings['header']['type']) && $tcpSettings['header']['type'] == 'http') {
                $array['network'] = $tcpSettings['header']['type'];
                if (isset($tcpSettings['header']['request']['headers']['Host'])) {
                    $array['http-opts']['headers']['Host'] = $tcpSettings['header']['request']['headers']['Host'];
                }
                if (isset($tcpSettings['header']['request']['path'])) {
                    $array['http-opts']['path'] = $tcpSettings['header']['request']['path'];
                }
            }
        }

        if ($server['network'] === 'ws') {
            $array['network'] = 'ws';
            if ($server['network_settings']) {
                $wsSettings = $server['network_settings'];
                $array['ws-opts'] = [];
                if (isset($wsSettings['path']) && !empty($wsSettings['path'])) {
                    $array['ws-opts']['path'] = $wsSettings['path'];
                }
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host'])) {
                    $array['ws-opts']['headers'] = ['Host' => $wsSettings['headers']['Host']];
                }
            }
        }
        if ($server['network'] === 'grpc') {
            $array['network'] = 'grpc';
            if ($server['network_settings']) {
                $grpcSettings = $server['network_settings'];
                $array['grpc-opts'] = [];
                // 同时兜底 snake_case (service_name) 和 camelCase (serviceName)，
                // 不同 v2board 前端版本写库的字段名不一样。
                $serviceName = $grpcSettings['service_name'] ?? $grpcSettings['serviceName'] ?? null;
                if (!empty($serviceName)) {
                    $array['grpc-opts']['grpc-service-name'] = $serviceName;
                }
            }
        }

        if ($server['network'] === 'xhttp') {
            $array['network'] = 'xhttp';
            self::applyXhttp($array, $server);
        }

        if (isset($server['encryption']) && !empty($server['encryption'])
            && isset($server['encryption_settings']) && !empty($server['encryption_settings'])) {
            $encryptionSettings = $server['encryption_settings'];
            $array['encryption'] = $server['encryption'] ?? 'mlkem768x25519plus';
            $array['encryption'] .= '.' . $encryptionSettings['mode'] ?? 'native';
            $array['encryption'] .= '.' . $encryptionSettings['rtt'] ?? '1rtt';
            if (isset($encryptionSettings['client_padding']) && !empty($encryptionSettings['client_padding'])) {
                $array['encryption'] .= '.' . $encryptionSettings['client_padding'];
            }
            $array['encryption'] .= '.' . $encryptionSettings['password'] ?? '';
        }

        return $array;
    }

    public static function buildTrojan($password, $server)
    {
        $array = [];
        $array['name'] = $server['name'];
        $array['type'] = 'trojan';
        $array['server'] = $server['host'];
        $array['port'] = $server['port'];
        $array['password'] = $password;
        $array['udp'] = true;
        if (isset($server['network']) && in_array($server['network'], ['grpc', 'ws'])) {
            $array['network'] = $server['network'];
            if ($server['network'] === 'grpc') {
                $ns = $server['network_settings'] ?? [];
                $serviceName = $ns['service_name'] ?? $ns['serviceName'] ?? null;
                if (!empty($serviceName)) {
                    $array['grpc-opts']['grpc-service-name'] = $serviceName;
                }
            }
            if ($server['network'] === 'ws') {
                if (isset($server['network_settings']['path'])) {
                    $array['ws-opts']['path'] = $server['network_settings']['path'];
                }
                if (isset($server['network_settings']['headers']['Host'])) {
                    $array['ws-opts']['headers']['Host'] = $server['network_settings']['headers']['Host'];
                }
            }
        }
        $tlsSettings = $server['tls_settings'] ?? [];
        $array['sni'] = $server['server_name'] ?? ($tlsSettings['server_name'] ?? '');
        $array['skip-cert-verify'] = ($server['allow_insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0)) == 1;
        self::applyEch($array, $tlsSettings);
        return $array;
    }

    public static function buildTuic($password, $server)
    {
        $array = [
            'name' => $server['name'],
            'type' => 'tuic',
            'server' => $server['host'],
            'port' => $server['port'],
            'uuid' => $password,
            'password' => $password,
            'alpn' => ['h3'],
            'disable-sni' => $server['disable_sni'] ? true : false,
            'reduce-rtt' => $server['zero_rtt_handshake'] ? true : false,
            'udp-relay-mode' => $server['udp_relay_mode'] ?? 'native',
            'congestion-controller' => $server['congestion_control'] ?? 'cubic',
        ];
        $tlsSettings = $server['tls_settings'] ?? [];
        $array['skip-cert-verify'] = ($server['insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0)) == 1;
        $array['sni'] = $server['server_name'] ?? ($tlsSettings['server_name'] ?? '');
        return $array;
    }

    public static function buildAnyTLS($password, $server)
    {
        $array = [
            'name' => $server['name'],
            'type' => 'anytls',
            'server' => $server['host'],
            'port' => $server['port'],
            'password' => $password,
            'client-fingerprint' => 'chrome',
            'udp' => true,
            'alpn' => ['h2', 'http/1.1'],
        ];
        $tlsSettings = $server['tls_settings'] ?? [];
        $array['sni'] = $server['server_name'] ?? ($tlsSettings['server_name'] ?? '');
        $array['skip-cert-verify'] = ($server['insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0)) == 1;
        self::applyEch($array, $tlsSettings);
        return $array;
    }

    public static function buildHysteria($password, $server)
    {
        $array = [];
        $array['name'] = $server['name'];
        $array['server'] = $server['host'];

        $parts = explode(',', $server['port']);
        $firstPart = $parts[0];
        if (strpos($firstPart, '-') !== false) {
            $range = explode('-', $firstPart);
            $firstPort = $range[0];
        } else {
            $firstPort = $firstPart;
        }
        $array['port'] = (int) $firstPort;
        if (count($parts) !== 1 || strpos($parts[0], '-') !== false) {
            $array['ports'] = $server['port'];
            $array['mport'] = $server['port'];
        }
        $array['udp'] = true;
        $array['skip-cert-verify'] = $server['insecure'] == 1;

        if (isset($server['server_name'])) {
            $array['sni'] = $server['server_name'];
        }

        if ($server['version'] === 2) {
            $array['type'] = 'hysteria2';
            $array['password'] = $password;
            if (isset($server['obfs'])) {
                $array['obfs'] = $server['obfs'];
                $array['obfs-password'] = $server['obfs_password'];
            }
        } else {
            $array['type'] = 'hysteria';
            $array['auth_str'] = $password;
            if (isset($server['obfs']) && isset($server['obfs_password'])) {
                $array['obfs'] = $server['obfs_password'];
            }
            $array['up'] = $server['down_mbps'];
            $array['down'] = $server['up_mbps'];
            $array['protocol'] = 'udp';
        }

        return $array;
    }

    private function buildHysteria2($password, $server)
    {
        // Xboard 的 hy2 把 tls / obfs / bandwidth 都嵌套在 protocol_settings 下：
        //   protocol_settings:
        //     tls:       { server_name, allow_insecure }
        //     obfs:      { open, type, password }
        //     bandwidth: { up, down }
        // 顶层 hoist 后 $server['tls'] / $server['obfs'] 是 array（**不是** scalar）。
        // v2board 把同样字段全平铺到 $server 顶层（tls_settings / obfs / obfs_password）。
        // 这里两套 schema 都要兼容，否则 Xboard 节点 sni/obfs 全空 → QUIC 握手不上来。
        $tls = (is_array($server['tls'] ?? null) ? $server['tls'] : [])
            + ($server['tls_settings'] ?? []);
        $sni = $tls['server_name'] ?? $tls['serverName'] ?? '';
        $skip = (bool) ($tls['allow_insecure'] ?? false);

        $array = [
            'name' => $server['name'],
            'type' => 'hysteria2',
            'server' => $server['host'],
            'password' => $password,
            'skip-cert-verify' => $skip,
            'sni' => $sni,
            'udp' => true,
        ];

        $parts = explode(',', $server['port']);
        $firstPart = $parts[0];
        if (strpos($firstPart, '-') !== false) {
            $range = explode('-', $firstPart);
            $firstPort = $range[0];
        } else {
            $firstPort = $firstPart;
        }
        $array['port'] = (int) $firstPort;
        if (count($parts) !== 1 || strpos($parts[0], '-') !== false) {
            $array['ports'] = $server['port'];
            $array['mport'] = $server['port'];
        }

        // obfs：Xboard = ['open'=>true,'type'=>'salamander','password'=>'xxx']（嵌套）
        //       v2board = 顶层 'obfs' 字符串 + 顶层 'obfs_password' 字符串
        $obfsRaw = $server['obfs'] ?? null;
        if (is_array($obfsRaw)) {
            // Xboard：open=false 时不下发 obfs，否则握手会带上 server 没启用的 obfs 反而失败
            if (!empty($obfsRaw['open']) && !empty($obfsRaw['type'])) {
                $array['obfs'] = $obfsRaw['type'];
                $array['obfs-password'] = $obfsRaw['password'] ?? '';
            }
        } elseif (is_string($obfsRaw) && $obfsRaw !== '') {
            // v2board
            $array['obfs'] = $obfsRaw;
            $array['obfs-password'] = $server['obfs_password'] ?? '';
        }

        // bandwidth：仅 Xboard 有；v2board 没这个字段，hy2 客户端在缺失时会用 brutal 探测
        $bw = $server['bandwidth'] ?? [];
        if (!empty($bw['up'])) {
            $array['up'] = $bw['up'];
        }
        if (!empty($bw['down'])) {
            $array['down'] = $bw['down'];
        }

        // 端口跳跃区间需要客户端定时换端口，Xboard 在 protocol_settings.hop_interval 配置
        if (!empty($server['hop_interval'])) {
            $array['hop-interval'] = (int) $server['hop_interval'];
        }

        return $array;
    }

    // ─── Xboard 独有协议（v2board 没有这几种节点）──────────────────────

    public static function buildSocks5($password, $server)
    {
        $array = [
            'name' => $server['name'],
            'type' => 'socks5',
            'server' => $server['host'],
            'port' => $server['port'],
            'udp' => true,
            'username' => $password,
            'password' => $password,
        ];
        if (!empty($server['tls'])) {
            $array['tls'] = true;
            $tlsSettings = $server['tls_settings'] ?? [];
            $array['skip-cert-verify'] = (bool) ($tlsSettings['allow_insecure'] ?? false);
        }
        return $array;
    }

    public static function buildHttp($password, $server)
    {
        $array = [
            'name' => $server['name'],
            'type' => 'http',
            'server' => $server['host'],
            'port' => $server['port'],
            'username' => $password,
            'password' => $password,
        ];
        if (!empty($server['tls'])) {
            $array['tls'] = true;
            $tlsSettings = $server['tls_settings'] ?? [];
            $array['skip-cert-verify'] = (bool) ($tlsSettings['allow_insecure'] ?? false);
        }
        return $array;
    }

    public static function buildMieru($password, $server)
    {
        $array = [
            'name' => $server['name'],
            'type' => 'mieru',
            'server' => $server['host'],
            'port' => $server['port'],
            'username' => $password,
            'password' => $password,
            'transport' => strtoupper($server['transport'] ?? 'TCP'),
        ];
        // Xboard ServerService 在端口范围模式下会把原始 'a-b' 字符串放到 ports 字段
        if (isset($server['ports'])) {
            $array['port-range'] = $server['ports'];
        }
        return $array;
    }
}

if (class_exists('App\\Support\\AbstractProtocol')) {
    // Xboard：继承 AbstractProtocol，由 ProtocolManager 反射 $flags 数组匹配
    class Apex extends \App\Support\AbstractProtocol
    {
        use ApexCore;
        // ⚠️ 必须和 trait 里 public $flag = '...' 保持一致！
        //   v2board 用 $flag 字符串匹配，Xboard 用 $flags 数组匹配，
        //   两个值不同步会导致只有其中一种面板能命中本协议。
        //   改 $flag 的同时务必把这里也改成同样的值，例如：
        //     trait:  public $flag = 'myvpn';
        //     这里:   public $flags = ['myvpn'];
        public $flags = ['apex'];
    }
} else {
    // V2Board：裸类，由 ClientController 遍历 glob，匹配 $flag 字符串
    class Apex
    {
        use ApexCore;

        protected $user;
        protected $servers;

        public function __construct($user, $servers)
        {
            $this->user = $user;
            $this->servers = $servers;
        }
    }
}
