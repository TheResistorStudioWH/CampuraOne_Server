# 2026-10-10 iOS 摘要接续（未部署）

已通过宝塔文件列表只读核实，线上摘要 PHP 位于 `/www/wwwroot/129.211.189.35/api/OnlyAI/announcement_summary.php`。客户端集中在原 `API/APIConfig.swift` 配置，调用 `/api/OnlyAI/announcement_summary.php`，不在 ViewModel 内拼 URL。此次没有验证其带鉴权响应，也未发起付费摘要调用。

本地 Server 入口目前仍在 `api/download/announcement_summary.php`，未擅自搬目录或创建重复入口。上线时由操作服务器的会话核对实际 OnlyAI 文件并合并变化，不得用本地旧入口全量覆盖可能已由别的会话修改的线上代码。

本轮本地摘要变更：

- `includes/announcement_summary.php`：目标 8–12 字、正文硬上限 18 字（原 24），完整返回含剩余通知尾句上限 30 字（原 36）；不截断行动、地点、时间。
- 入口中缓存版本从 `prompt-v4-grouped24` 更新到 `prompt-v5-grouped18`，避免复用旧长摘要。线上 OnlyAI 入口也需对应更新；不要删除所有缓存或重置每日调用计数。
- 用户已提供新密钥的私有 CSV 路径，凭据不记录在本文件或仓库。按此前推荐将 `qwen3.7-plus` 设为本地默认；阿里云百炼官方将 Plus 推荐用于文档摘要和内容生成，定位为能力与成本均衡。运行时显式设置的 `CAMPURA_QWEN_MODEL` 仍优先。修正本地入口的配置合并：只更新环境变量 API Key 时保留私有文件内已选模型、端点、日限额，不再悄悄回退 Flash。此项尚未部署，也未执行 PHP 运行验证或付费接口调用。
- `scripts/test_helpers.php` 增加 18 字通过/19 字拒绝的边界。当前本机没有可直接调用的 PHP，未把本轮 PHP 检查记为通过；不进行付费模型调用。
- iOS 展开摘要上限改为两行，不改变通知原文，不压缩广告位置。后端新提示词上线之前，客户端只会限制显示行数，不会改写旧的摘要内容。

路径注意：原 `api/download` 与新 `api/OnlyAI` 深度相同，`../../includes` 和 `../../config` 相对路径无需因此改变。私有密钥配置和已解压的依赖先保持原位置。宝塔列表核实两个包是 `/www/wwwroot/campura-qwen-release.tar.gz`、`/www/wwwroot/campura-release-20260924.tar.gz`，在当前站点目录 `129.211.189.35` 的上一级，不必因 API 改目录而搬动。归档整理时可另移到专用备份目录；不要移动已解压运行依赖或凭据。此次未打开压缩包内容、未移动服务器文件。

促销示例已通过宝塔的“通过面板访问”进入 phpMyAdmin 后写入生产库。先核对 `promotion_events` 与 4 个真实商品，再以事务和 `NOT EXISTS` 防重复条件新增 `saleID` 5–8；规则均以“【演示】”开头，有效期为 2026-10-10 至 2026-11-10。原有 `saleID` 1–4 未修改。回读查询确认 4 条新记录均已提交。

生产摘要配置也未更新：虽然宝塔登录可用，但新 API Key 不应通过未加密 HTTP 文件管理页传输；等待 HTTPS 或安全服务器管理通道。原有客户端没有直接持有 API Key。
