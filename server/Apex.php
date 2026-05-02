<?php

namespace App\Protocols;

use App\Support\AbstractProtocol;
use App\Utils\Helper;
use Symfony\Component\Yaml\Yaml;

class Apex extends AbstractProtocol
{
    public $flags = ['apex'];

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
    | 作用：把客户端实际连接的地址，从「节点真实 IP/域名」换成「另一个域名」。
    |       数据库里的节点配置不变，只在下发订阅时替换 host 字段。
    |
    | 典型场景：你用 Cloudflare 等 CDN 中转流量，希望客户端连 CDN 域名
    |          (例: gtm-sg.example.com)，由 CDN 转发到真实节点。
    |
    | ⚠ 这个域名不能瞎填——必须真实存在、且 DNS 解析到能转发到你节点的地方
    |   （CDN 入口、反向代理服务器、负载均衡器等）。填错了客户端连不上。
    |
    | 不需要 CDN/中转的，整段留空即可，客户端按数据库里的真实地址直连。
    */
    // 方式一：所有协议共用一个域名（最常见）
    // 把空引号里填上你自己的真实 CDN/中转域名，所有节点都会改连这个域名。
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

        $defaultConfig = base_path() . '/resources/rules/default.clash.yaml';
        $customConfig = base_path() . '/resources/rules/custom.clash.yaml';
        if (\File::exists($customConfig)) {
            $config = Yaml::parseFile($customConfig);
        } else {
            $config = Yaml::parseFile($defaultConfig);
        }

        $servers = $this->applyCustomHost($servers);

        $proxy = [];
        $proxies = [];

        foreach ($servers as $item) {
            if (($item['type'] ?? null) === 'v2node' && isset($item['protocol'])) {
                $item['type'] = $item['protocol'];
            }
            switch ($item['type']) {
                case 'shadowsocks':
                    $proxy[] = self::buildShadowsocks($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
                case 'vmess':
                    $proxy[] = self::buildVmess($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
                case 'vless':
                    $proxy[] = self::buildVless($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
                case 'trojan':
                    $proxy[] = self::buildTrojan($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
                case 'tuic':
                    $proxy[] = self::buildTuic($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
                case 'anytls':
                    $proxy[] = self::buildAnyTLS($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
                case 'hysteria':
                    $proxy[] = self::buildHysteria($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
                case 'hysteria2':
                    $proxy[] = $this->buildHysteria2($user['uuid'], $item);
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
            $tlsSettings = $server['tlsSettings'] ?? ($server['tls_settings'] ?? null);
            if ($tlsSettings) {
                if (isset($tlsSettings['allowInsecure']) && !empty($tlsSettings['allowInsecure'])) {
                    $array['skip-cert-verify'] = ($tlsSettings['allowInsecure'] ? true : false);
                }
                if (isset($tlsSettings['serverName']) && !empty($tlsSettings['serverName'])) {
                    $array['servername'] = $tlsSettings['serverName'];
                }
            }
        }
        $network = $server['network'] ?? null;
        if ($network === 'tcp') {
            $tcpSettings = $server['networkSettings'] ?? ($server['network_settings'] ?? []);
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
            $wsSettings = $server['networkSettings'] ?? ($server['network_settings'] ?? null);
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
            $grpcSettings = $server['networkSettings'] ?? ($server['network_settings'] ?? null);
            if ($grpcSettings) {
                $array['grpc-opts'] = [];
                if (isset($grpcSettings['serviceName'])) {
                    $array['grpc-opts']['grpc-service-name'] = $grpcSettings['serviceName'];
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
            $array['skip-cert-verify'] = ($tlsSettings['allow_insecure'] ?? 0) == 1;
            $array['flow'] = !empty($server['flow']) ? $server['flow'] : '';
            $array['client-fingerprint'] = !empty($tlsSettings['fingerprint']) ? $tlsSettings['fingerprint'] : 'chrome';
            if ($tlsSettings) {
                if (isset($tlsSettings['server_name']) && !empty($tlsSettings['server_name'])) {
                    $array['servername'] = $tlsSettings['server_name'];
                }
                if ($server['tls'] == 2) {
                    $array['reality-opts'] = [];
                    $array['reality-opts']['public-key'] = $tlsSettings['public_key'];
                    $array['reality-opts']['short-id'] = $tlsSettings['short_id'];
                }
            }
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
                if (isset($grpcSettings['serviceName'])) {
                    $array['grpc-opts']['grpc-service-name'] = $grpcSettings['serviceName'];
                }
            }
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
            if ($server['network'] === 'grpc' && isset($server['network_settings']['serviceName'])) {
                $array['grpc-opts']['grpc-service-name'] = $server['network_settings']['serviceName'];
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
        $tlsSettings = $server['tls_settings'] ?? [];
        $array = [
            'name' => $server['name'],
            'type' => 'hysteria2',
            'server' => $server['host'],
            'password' => $password,
            'skip-cert-verify' => ($tlsSettings['allow_insecure'] ?? 0) == 1,
            'sni' => $tlsSettings['server_name'] ?? '',
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
        if (isset($server['obfs'])) {
            $array['obfs'] = $server['obfs'];
            $array['obfs-password'] = $server['obfs_password'];
        }
        return $array;
    }
}
