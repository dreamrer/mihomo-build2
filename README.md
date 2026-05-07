# mihomo-build

Multi-platform build pipeline for Apex VPN client. Releases are published here automatically when a new version tag is pushed.

## Platforms

| Platform | Architecture | Format |
|----------|-------------|--------|
| Android | arm64-v8a / armeabi-v7a | APK |
| Android TV | arm64-v8a / armeabi-v7a | APK |
| Windows | x86_64 | EXE (Inno Setup) |
| macOS | Universal (Apple Silicon + Intel) | DMG |
| Linux | x86_64 | DEB / RPM / AppImage |
| iOS | arm64 | IPA |
| OpenWrt | amd64 / arm64 / armv7 / armv5 / mips / mipsle | ipk / .run |

## OpenWrt

OpenWrt packages contain the pre-compiled mihomo core binary along with init scripts, UCI config, and nftables firewall rules for transparent proxy.

### Install

**Option A — opkg (ipk, recommended):**
```bash
# Install core (pick the .ipk matching your router arch)
opkg install mihomo_*_aarch64_generic.ipk
# Install LuCI web UI (architecture-independent)
opkg install luci-app-mihomo_*_all.ipk
```
After install: LuCI → Services → Mihomo

**Option B — self-extracting (.run):**
```bash
chmod +x mihomo-openwrt-arm64-*.run
./mihomo-openwrt-arm64-*.run
```

**Configure:**
```bash
uci set mihomo.config.enabled='1'
uci set mihomo.config.subscription_url='https://your-panel.com/api/v1/client/subscribe?token=xxx'
uci commit mihomo
/etc/init.d/mihomo start
```

### Web Dashboard

```
http://<router-ip>:9090/ui
```

## Server (Xboard / V2Board)

Airport operators drop [`server/Apex.php`](server/Apex.php) into their panel's `app/Protocols/` directory to enable encrypted subscription delivery for the Apex client. **The same file works on both Xboard and V2Board** — it extends the panel's existing `ClashMeta.php` and just wraps the output in XOR encryption, so all protocol field handling (Vless / Trojan / AnyTLS / Hysteria2 / Reality / xhttp / ECH / utls) automatically inherits whatever ClashMeta does.

The client appends `?flag=apex` to subscription URLs automatically, so panel admins do not need to expose any new endpoint.

> **`$encryptKey` is mandatory.** Telegram build-bot → 选你的配置 → 「查看加密密钥」copy the value. The protocol throws `RuntimeException` on every request when this is empty — clients will see an empty node list.

### Three supported deployment paths

Xboard officially runs in docker (Swoole + Octane image). The three setups differ only in **how you edit compose** and **where the Apex.php source file lives on the host**; the in-container path is always `/www/app/Protocols/Apex.php`, and the reload command is always `php artisan octane:reload`.

| # | Topology | What to edit | Reload |
|---|----------|--------------|--------|
| 1 | **Plain Docker Compose** (`compose.sample.yaml`) | The `compose.yaml` file you wrote | `docker compose exec xboard php artisan octane:reload` |
| 2 | **aaPanel + Docker** (recommended) | aaPanel → 容器 → 编辑 compose | aaPanel → 重启容器, then `docker exec <c> php artisan octane:reload` |
| 3 | **1Panel** (`compose.1panel.sample.yaml`) | 1Panel → 容器 → 编辑 compose | 1Panel → 重启容器, then `docker exec <c> php artisan octane:reload` |

#### 1) Plain Docker Compose

Add one line to the `volumes` block in your `compose.yaml`:

```yaml
services:
  xboard:
    image: ghcr.io/cedar2025/xboard:latest
    volumes:
      - ./.env:/www/.env
      - ./.docker/.data/:/www/.docker/.data
      - ./storage/logs:/www/storage/logs
      - ./storage/theme:/www/storage/theme
      - ./plugins:/www/plugins
      - redis-data:/data
      - ./Apex.php:/www/app/Protocols/Apex.php:ro    # ← add this
```

```bash
# Place Apex.php next to compose.yaml on the host
wget -O ./Apex.php \
     https://raw.githubusercontent.com/dreamrer/mihomo-build/main/server/Apex.php

# Fill in $encryptKey (replace YOUR_XOR_KEY with the value from build-bot)
sed -i "s|private \$encryptKey = '';|private \$encryptKey = 'YOUR_XOR_KEY';|" ./Apex.php

# Apply
docker compose up -d
docker compose exec xboard php artisan octane:reload
```

> **Why volume mount, not `docker exec ... vi`?**
> The Xboard Dockerfile `git clone`s master at image build time, so any in-container edits are wiped on `docker pull` + recreate. The volume keeps Apex.php on the host filesystem so the file survives container rebuilds.

#### 2) aaPanel + Docker (recommended)

aaPanel manages docker through its UI but the underlying compose is the same. Two paths:

**Via aaPanel docker UI:**
1. 容器管理 → 找到 xboard 容器 → 编辑（or 容器编排 → 编辑你的 compose 文件）
2. 在 `volumes` 段加：`- /www/wwwroot/xboard-files/Apex.php:/www/app/Protocols/Apex.php:ro`
   （`/www/wwwroot/xboard-files/` 改成你 aaPanel 上放 compose.yaml 的目录）
3. 把 Apex.php 上传到那个目录、填 `$encryptKey`
4. aaPanel 重启容器
5. SSH 到服务器跑 `docker exec <container_name> php artisan octane:reload`

**Via SSH (faster for ops):** same as Plain Docker Compose above. aaPanel doesn't lock you out of the underlying docker CLI.

#### 3) 1Panel

跟 aaPanel 几乎一样，1Panel 也提供容器管理 UI + compose 编辑：

1. 容器 → 编排 → 选你的 xboard 编排 → 编辑
2. `volumes` 段加：`- /opt/1panel/apps/xboard/Apex.php:/www/app/Protocols/Apex.php:ro`
   （路径按你 1Panel 实际项目目录调整）
3. 把 Apex.php 放到该目录、填 `$encryptKey`
4. 1Panel UI 重启容器
5. 终端：`docker exec <container_name> php artisan octane:reload`

> 1Panel 的官方 compose 模板（`compose.1panel.sample.yaml`）跟默认 compose 唯一区别是加入了 `1panel-network`（让 xboard 容器能访问 1Panel 管理的 MySQL/Redis 容器）。Apex.php 挂载方式跟默认完全一样。

### Verification (any deployment)

```bash
# 1. File present and reasonable size (~9KB)
docker compose exec xboard ls -la /www/app/Protocols/Apex.php
# or for non-docker: ls -la /www/wwwroot/<panel>/app/Protocols/Apex.php

# 2. PHP syntax clean
docker compose exec xboard php -l /www/app/Protocols/Apex.php

# 3. Class registers correctly (should print ["apex"])
docker compose exec xboard php artisan tinker --execute="echo json_encode((new ReflectionClass('App\\\\Protocols\\\\Apex'))->getProperty('flags')->getDefaultValue());"

# 4. ?flag=apex actually routes to Apex.php (not falling back to General.php)
curl -A 'Apex/v3.5.x' 'https://your-panel/api/v1/client/subscribe?token=xxx&flag=apex' | head -c 30 | xxd
```

The last command's output tells you everything:
- **Random-looking binary or `1a 20 5f ...`** → encrypted blob, Apex.php is alive ✅
- **`64 6d 78 6c 63 33 4d 36`** (= base64 of `vless:`) or **`73 73 3a 2f ...`** (`ss://`) → fell back to General.php's URI list, Apex.php not loaded ❌
- **`6d 69 78 65 64 2d 70 6f 72 74`** (`mixed-port`) → fell back to ClashMeta.php's plain YAML ❌

### Custom UA — change the flag

If your build-bot is configured with a non-default `APEX_FLAG` (e.g., the client's User-Agent is `MyVPN/v1.0` instead of `Apex/v...`), the `$flag` and `$flags` constants in Apex.php must be changed to the same value or the panel will fall back to General.php.

```php
public $flag  = 'myvpn';        // line 71
public $flags = ['myvpn'];      // line 72
```

The Telegram bot's "查看加密密钥" view shows the current flag value alongside the key — copy both, change both.

### Recommended Clash template (强烈建议替换)

The stock `default.clash.yaml` shipped with v2board / Xboard has three problems that visibly hurt user experience:

1. **DNS uses plain UDP-53** (223.5.5.5 / 119.29.29.29 / 8.8.8.8). ISP DNS hijacking strips SVCB records → ECH nodes can't resolve → timeout. UDP responses get truncated at 512B → large records lost.
2. **Fallback group interval 7200s** (2 hours). When a proxy goes down, users sit on a dead node for up to 2 hours before mihomo retests.
3. **Hundreds of hardcoded domain rules** that drift out of date. Modern mihomo supports `geosite`/`geoip` categories that auto-update.

[`server/default.clash.yaml`](server/default.clash.yaml) is a drop-in replacement:

```bash
# Backup the existing template, then replace
cp /www/wwwroot/v2board/resources/rules/default.clash.yaml{,.bak}
wget https://raw.githubusercontent.com/dreamrer/mihomo-build/main/server/default.clash.yaml \
     -O /www/wwwroot/v2board/resources/rules/default.clash.yaml
# Xboard same file path:
#   /www/wwwroot/xboard/resources/rules/default.clash.yaml
```

Highlights:
- DNS via DoH/DoT (doh.pub, dns.alidns.com, 1.1.1.1, dns.google) — ECH-friendly
- Sniffer enabled — required for Reality / xhttp / fakeip + domain rule matching
- Fakeip filter covering Apple Push, Microsoft, gaming services, NTP, Stun
- url-test/fallback intervals shortened to 300s
- `geosite:cn / geolocation-!cn / category-ads-all` instead of 500+ hand-maintained lines

The `$app_name` placeholder stays — Apex.php / ClashMeta.php replace it with the panel's brand name on each subscription request.

## Build

Builds are triggered via GitHub Actions:

- **Tag push** (`v*`) - builds all platforms
- **Manual dispatch** - select specific platforms, customize app name / logo / config

## Contact

Purchase & support: [@teka0pu](https://t.me/teka0pu)
