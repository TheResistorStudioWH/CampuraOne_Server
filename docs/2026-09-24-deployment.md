# 课表文件与通知摘要

## 2026-10-03 广告位置恢复（当前版本）

- 用户澄清广告必须保持原位置，因此恢复首页原有 ZStack、标题底部留白和通知栏 offset；广告不再随通知展开下移。下方“流式排版”记录仅描述上一版，已撤回。
- 收起态摘要恢复单行，展开摘要最多三行；缩短内容而非移动广告。
- 提示词正文目标 10–16 字，硬上限 24 字，优先一个重点或一组同类事项，总正文含遗漏条数提示不超过 36 字。缓存版本 prompt-v4-grouped24，不复用上一版长摘要。
- helper/API 已部署且与本地 SHA-256 一致，PHP 语法及摘要计数/长度/来源校验测试通过。iOS 模拟器 Debug 编译通过。部署前文件备份在 `/www/server/campura-summary-20261003-compact/before-helper.php` 和 `before-api.php`，回滚时需成对恢复。

## 2026-10-03 紧凑摘要更新（已部署）

- 提示词改为先合并同类事项，再按紧急风险与行动截止时间选取重点；正文目标 18–28 字，硬上限 34 字，总显示文本含遗漏提示不超过 50 字。
- 模型内部返回 JSON 正文与实际涵盖的来源编号；服务端校验来源并去重，用全部可见通知数减去已涵盖的原始通知数，追加“还有另外 X 条通知”。来源编号不显示在正文，公开响应仍为原有 summary/sourceIDs/isAI 合约。
- 缓存版本改为 prompt-v3-grouped34，并纳入可见通知总数，旧的长摘要不再复用。失败时 iOS 显示“摘要不可用”，原文仍可打开。
- includes 与 API 文件已部署，线上 SHA-256 与本地一致。PHP 8.3 语法与合并/遗漏数量/来源去重/来源越界/长度限制测试通过。真实演示账号请求 HTTP 200、isAI=true，摘要 42 字（含“还有另外14条通知”），重复请求缓存验证通过。
- 部署前文件保存在 `/www/server/campura-summary-20261003/before-helper.php` 和 `before-api.php`；回滚需一起恢复这两个文件，避免 helper 与 API 返回类型不匹配。未改密钥、账户、数据库或 PHP 隔离配置。
- iOS 通知栏改为正常流式排版，最多三行摘要，展开内容推动广告下移而非覆盖广告。课表日期轴以今天为初始中心，滑动时保持日期窗口稳定，并向下调整。模拟器 Debug 编译通过；Mac 原生控制仍报告锁屏，界面触控未验收。

## 2026-09-30 收尾复核

- 真实学生登录后的摘要接口再次返回 HTTP 200、isAI=true。首次约 2070 ms，重复请求约 22 ms；generatedAt 相同，确认缓存复用。
- 临时传输公钥的原公开 URL 返回 404，未继续保留公开下载入口。
- 当前 iOS 源码重新编译通过（含姓名行天气组件），未修改其他 UI。Mac 再次锁屏，模拟器显示／点击验收及面板最终权限读取仍未完成，不能视为已完成界面验收。

## 2026-09-25 Qwen 配置更新（当前部署）

此前未配置密钥的阻塞已解除。当前使用北京业务空间专属 HTTPS 接口、`qwen3.7-flash`，通过 PHP-FPM 环境变量读取密钥；没有将密钥写入网站目录、iOS 或 Git。

- 私有配置：`/www/server/campura-private/qwen-fpm.conf`，目录 root:root 0700，文件 root:root 0600；由 PHP 8.3 的 www pool include。只提供 CAMPURA_QWEN_API_KEY、CAMPURA_QWEN_ENDPOINT、CAMPURA_QWEN_MODEL、CAMPURA_QWEN_DAILY_LIMIT 四个环境变量。
- PHP 原配置备份：`/www/server/campura-private/php-fpm-before-qwen-20260925-230721.conf`。语法测试通过后使用 USR2 平滑重载。
- 缓存与按日计数：`/tmp/campura-one-summary`，www:www 0700，位于 webroot 外且符合原有 open_basedir。保持 6 小时缓存／每日 30 次上游尝试。系统清理 /tmp 会重置缓存和本地计数；它不是供应商账户的硬金额上限，正式运营前需加持久化额度控制。
- 未关闭或放宽 open_basedir。上传时仅传 RSA-OAEP 加密的密钥，服务端解密后写入私有配置；临时公开的传输公钥已移回私有暂存目录。
- 当前该 PHP pool 仅用于已核对的一个站点。以后新增站点时必须隔离 PHP pool，不能让其他应用共享含密钥的环境；不要部署 phpinfo 或环境变量调试输出。
- 登录后的真实总结返回 HTTP 200；同一班级重复请求命中缓存（首次约 1.9 秒，缓存约 0.1 秒）。密钥只用于上游服务端请求，不返回给调用方。
- 回滚时恢复上述 PHP 配置备份及暂存目录 `/www/server/campura-qwen-20260925/summary-before.php`，校验 PHP-FPM 后平滑重载。不要打印私有配置或将其上传 Git。

## 兼容接口

`course_tables.content` 迁移为站点相对路径 `uploads/course_tables/course-ID-HASH.ics`。暂保留原列类型，避免破坏旧写入方及回滚；它不再保存新迁移记录的正文。无须更改学生端 SwiftData 模型。

`course_table.php` 默认仍返回 `{code,message,data:{content,...}}`，其中 content 是读取文件后的 ICS 文本，新增 icsPath；旧内联 ICS 仍能读。相同请求加 `download=1` 返回 text/calendar 附件。现有 EventKit 导出不变。绝对路径、远程 URL、目录穿越和大于 5 MB 的文件均拒绝。当前接口仍沿用原有公开班级课表访问范围，私有个人课表上线前必须另行加鉴权。

## 通知摘要

`GET /api/download/announcement_summary.php` 使用现有 Bearer JWT，服务端查学生所属学校与班级。只发送可见通知正文、编号、时间和类别，不发送学生姓名、账号、口令或定位。原文当作数据而非指令。失败时客户端显示“摘要不可用”，原文仍可查看。

当前部署使用上文的 FPM 环境变量。下面是代码保留的替代文件配置格式（本机宝塔 open_basedir 不允许读取此路径，因此当前未使用），禁止进入 Git：

```json
{"apiKey":"在服务器填写","model":"qwen3.7-flash","endpoint":"https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions","dailyCallLimit":30}
```

仅允许大陆百炼 HTTPS 域名。相同输入缓存 6 小时、全局生成互斥锁、每天最多 30 次上游尝试（硬上限 100），最多 30 条／18000 字输入、320 tokens 输出，不自动升级模型或联网搜索。来源编号在响应中供客户端验证，不显示在摘要正文。缓存与计数置于同一私有目录，目录需授予 PHP 用户写权限。未配置密钥返回 503，不产生账单。

## 部署顺序与回滚

1. 核对线上表结构，备份将替换的 API 和 includes 文件到 webroot 外。
2. 部署 helper，再部署兼容 API；先 `php -l` 和 `scripts/test_helpers.php`。
3. `php scripts/migrate_course_files.php /absolute/site/root` 先 dry run，然后加 `--apply`。自动备份原行到 `/www/backup/campura-one/`，原 ICS 保留，仅给已核对的演示班级追加 69 节新课程。文件校验成功才提交 DB 事务；重复运行不会重复追加。
4. `seed_summary_demo.php` 同样先 dry run 再 `--apply`。仅新增 6 个 `campura_demo_XX` 学生账号和 18 条带演示标记的通知，包含未生效与已过期测试。保留 linxistudent 哈希不动，密码收据保存在私有备份目录，禁止提交 Git。
5. 校验 JSON API、download=1、学生登录、通知筛选和摘要。密钥配置后再做小额真实调用测试。
6. 回滚课表时，在事务内按 tableID 恢复备份 JSON 中的 content、updatedAt；仍兼容内联内容。保留生成文件直至确认无引用。测试数据回滚需按收据的新增 ID 精确删除，勿覆盖已变化的整库。服务器文件使用部署前备份恢复。

当前未全面修理历史演示库：假哈希账号、未知结构与目标关系不能擅改；保护原账户，使用新增账号测试。

## 2026-09-24 实际部署结果

- 4 条课表已迁移至文件；class 1、3 各 80 个 VEVENT，301、302 各 70 个（包含原课程，各追加 69 节）。默认 JSON 与 download=1 均线上 HTTP 200。
- 课表备份：`/www/backup/campura-one/20260924-133919-f67447/course_tables.json`。
- 6 个新增学生全部通过真实登录 API 验证；18 条通知已提交。账号回执与导入前备份：`/www/backup/campura-one/seed-20260924-140542-7ff361/`。不要公开该目录或把回执提交仓库。
- 线上 students.studentID 并非自增，导入脚本使用事务锁定最高 ID 后显式分配。首次失败已回滚；未修改数据库结构。
- 摘要的客户端和服务端代码已接好，但用户提供的 CSV 本地路径不存在，密钥未配置、真实模型调用未验证，也没有产生此次测试的模型账单。接口已验证返回合法 JSON 503，客户端回退原文。
- 宝塔 open_basedir 当前只允许站点目录和 /tmp；私有配置默认路径不可读。后续接入密钥时需要确定合规私有配置方案（例如 PHP 环境注入），不能直接关闭目录隔离。当前读取失败被安全处理，不输出 PHP 路径警告。
- iOS 模拟器目标编译通过。横向广告手势和搜索动画尚需真机交互验收。天气图标组件未放进现有界面。
