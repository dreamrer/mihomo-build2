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
| OpenWrt | amd64 / arm64 / armv7 / armv5 / mips / mipsle | tar.gz |

## OpenWrt

OpenWrt packages contain the pre-compiled mihomo core binary along with init scripts, UCI config, and nftables firewall rules for transparent proxy.

### Install

```bash
# Download the package for your router architecture
tar xzf mihomo-openwrt-arm64-*.tar.gz -C /

# Enable and configure
uci set mihomo.config.enabled='1'
uci set mihomo.config.subscription_url='https://your-panel.com/api/v1/client/subscribe?token=xxx'
uci commit mihomo
/etc/init.d/mihomo enable
/etc/init.d/mihomo start
```

### Web Dashboard

```
http://<router-ip>:9090/ui
```

## Build

Builds are triggered via GitHub Actions:

- **Tag push** (`v*`) - builds all platforms
- **Manual dispatch** - select specific platforms, customize app name / logo / config

## Contact

Purchase & support: [@teka0pu](https://t.me/teka0pu)
