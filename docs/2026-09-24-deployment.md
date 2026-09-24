# 课表文件与通知摘要

## 兼容接口

`course_tables.content` 迁移为站点相对路径 `uploads/course_tables/course-ID-HASH.ics`。暂保留原列类型，避免破坏旧写入方及回滚；它不再保存新迁移记录的正文。无须更改学生端 SwiftData 模型。

`course_table.php` 默认仍返回 `{code,message,data:{content,...}}`，其中 content 是读取文件后的 ICS 文本，新增 icsPath；旧内联 ICS 仍能读。相同请求加 `download=1` 返回 text/calendar 附件。现有 EventKit 导出不变。绝对路径、远程 URL、目录穿越和大于 5 MB 的文件均拒绝。当前接口仍沿用原有公开班级课表访问范围，私有个人课表上线前必须另行加鉴权。

## 通知摘要

`GET /api/download/announcement_summary.php` 使用现有 Bearer JWT，服务端查学生所属学校与班级。只发送可见通知正文、编号、时间和类别，不发送学生姓名、账号、口令或定位。原文当作数据而非指令。失败时客户端保留原有通知计数摘要与原文，不改变通知 UI。

配置存放在 webroot 外 `/www/server/campura-private/ai.json`，由 PHP 用户读取，禁止进入 Git：

```json
{"apiKey":"在服务器填写","model":"qwen3.7-flash","endpoint":"https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions","dailyCallLimit":30}
```

仅允许大陆百炼 HTTPS 域名。相同输入缓存 6 小时、全局生成互斥锁、每天最多 30 次上游尝试（硬上限 100），最多 30 条／18000 字输入、320 tokens 输出，不自动升级模型或联网搜索。摘要有来源编号并提醒以原文为准。缓存与计数置于同一私有目录，目录需授予 PHP 用户写权限。未配置密钥返回 503，不产生账单。

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
