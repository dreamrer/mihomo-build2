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

Airport operators (机场主) drop [`server/Apex.php`](server/Apex.php) into their panel's `app/Protocols/` directory to enable encrypted subscription delivery for the Apex client. **The same file works on both Xboard and V2Board** — it auto-detects the panel type at load time.

```bash
wget https://raw.githubusercontent.com/dreamrer/mihomo-build2/main/server/Apex.php \
     -O /www/wwwroot/your-panel/app/Protocols/Apex.php
```

Then open the file and fill in `private $encryptKey = '';` with the value from your build-bot config (Telegram bot → 「查看加密密钥」). Without the key the protocol throws on every request — clients will see an empty node list.

The client appends `?flag=apex` to subscription URLs automatically, so panel admins do not need to expose any new endpoint.

## Build

Builds are triggered via GitHub Actions:

- **Tag push** (`v*`) - builds all platforms
- **Manual dispatch** - select specific platforms, customize app name / logo / config

## Contact

Purchase & support: [@teka0pu](https://t.me/teka0pu)
