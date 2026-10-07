# 学校管理接口（本地实现，未部署）

本轮只修改 Server。Web 接线、iOS UI、线上部署、支付和商户申请入口不在本轮范围内。接口入口及请求字段见 [OpenAPI](openapi-school.json)，手动请求见 [HTTP 示例](school-console-examples.http)，自动化请求见 tests/integration.php；实际执行结果见 [本地验收记录](school-console-validation.md)。

## 环境与验证

PHP 8.3 + MySQL 5.7.44；Composer 锁定 sabre/vobject。MySQL 5.7 已停止常规维护，此配置用于匹配现有服务器；不要将测试数据库暴露到公网。

也可在 Server 目录用 sh tests/run.sh 一次执行构建、安装、迁移、语法和集成测试；它不会自动停止容器，检查完成后运行下方 down 命令。

在 Server 目录运行（macOS 需先启动 Docker/Colima，Apple Silicon 使用 Rosetta 或 amd64 模拟运行 MySQL）：

    docker compose -f compose.test.yml up -d --build
    docker compose -f compose.test.yml exec -T php composer install --no-interaction
    docker compose -f compose.test.yml exec -T php php tests/init_database.php
    docker compose -f compose.test.yml exec -T php php scripts/migrate_school_console.php
    docker compose -f compose.test.yml exec -T php php tests/integration.php
    docker compose -f compose.test.yml exec -T php php scripts/test_helpers.php
    docker compose -f compose.test.yml down

如使用 Homebrew 的独立 Compose，将 docker compose 换成 docker-compose。此工作区专用 Colima 配置名为 campura-test，命令前加 DOCKER_CONTEXT=colima-campura-test。不要使用生产连接配置执行 tests。

测试服务只绑定 127.0.0.1:18083；数据库无宿主机端口，使用独立命名卷。测试只导入已核实的旧表结构，不导入备份中的账号、密码或其他真实数据。每次验收创建新的测试学校；演示申请脚本对同一学校和商户可重复执行，不重置已有审核状态。停止容器保留测试卷，便于排查。

### 配置

运行 PHP 的进程需提供以下环境变量（.env 不会自动加载）：

| 变量 | 含义 |
| --- | --- |
| CAMPURA_DB_HOST / PORT / NAME / USER | 数据库连接，端口默认 3306 |
| CAMPURA_DB_PASSWORD | 必填；只在隔离测试库允许空字符串 |
| CAMPURA_BASE_URL | 公开资源 URL 前缀，不以 / 结尾 |
| CAMPURA_UPLOAD_ROOT | 上传文件根目录，默认 Server/uploads |
| CAMPURA_ADMIN_ORIGINS | 允许的 Web Origin，逗号分隔；缺省不允许跨域浏览器请求 |

数据库密码不再内置于代码。将来部署前必须给 PHP-FPM 和 CLI 都配置私密环境，确认 FPM 的 clear_env 策略；不能直接覆盖线上代码后再补配置。所有数据库日期按 Asia/Shanghai，连接 SET time_zone='+08:00'。

上传要求 upload_max_filesize >= 6M、post_max_size >= 8M；应用层限制 ICS 原文件 2 MiB、Logo 5 MiB。Web 服务器只公开 API 和允许的 uploads 资源，禁止访问 config、includes、scripts、tests、migrations、vendor、.git、Composer 文件及日志。上传目录禁止执行脚本、关闭目录索引。设置 CAMPURA_UPLOAD_ROOT 时，需要把公开 /uploads 路径正确映射到它。

## 迁移与回退

1. 停止写入，备份完整数据库、现有代码（包括私密配置）、uploads 文件夹，记录备份时间及校验值。备份必须存非公开目录，限制读取权限。
2. 先在恢复出来的隔离库核对实际结构，运行 php scripts/migrate_school_console.php。预检缺列、类型不兼容、重复索引数据时会停止；不要强行删除原数据。
3. 迁移增加学校账号/会话、学期、版本/覆盖、审核/报价、操作日志表；旧表仅新增 schools.version、students.studentNumber、announcements.title/revokedAt/version、course_tables.semesterID/baseVersionID/version 及两个唯一索引。MySQL 5.7 DDL 隐式提交，不承诺整份迁移事务回退；失败后修正结构再重跑。
4. 学号在学校内唯一，可空，字符串保留前导零。原 studentID 和所有原账号密码不改动。school_admin_accounts 独立于原 schools.passwordHash。
5. 旧广告仅在 main_shops.adIDs 指向唯一商户时建立 approved 审核记录；未知/多归属输出 UNASSIGNED 报告，不猜学校。未管理的旧广告继续按原日期规则展示。已有审核记录不被重跑脚本覆盖。
6. 旧课表 semesterID 为空；旧内联 ICS、uploads/course_tables 和 uploads/school_calendars 路径继续读取。需管理员明确上传到学期后才归档，不自动猜学期。
7. 部署窗口内迁移成功后才启用新代码；本轮没有对线上执行以上步骤。

回退优先方案：停止写入 → 保留失败版本及新增上传文件的副本 → 从同一时点恢复完整数据库、uploads、旧代码和旧配置 → 验证学生登录、通知、广告、课表 → 恢复流量。不要把旧代码与已改变的管理数据混搭（旧代码不懂撤回/下架语义）。不提供一键 DROP 回退，避免删除新产生的业务历史。如果已经接收新业务数据，必须先导出增量并人工制定合并方案，不能直接覆盖。

文件采用随机文件名、临时写入后原子重命名、最后提交数据库指针。解析/写入/事务失败不替换生效文件。数据库回滚可能留下未引用文件；这些文件不是生效数据。维护时对所有原始版本、有效路径和学校 Logo 做引用清单后，人工归档未引用文件；不能只按“当前版本”清理历史。

## 账号开通与学号补录

每校一个账号，平台 CLI 开通，学校不自助注册。用安全输入/密码管理器向标准输入提供密码，禁止把密码写入命令参数、文档、Git 或日志：

    php scripts/provision_school_admin.php SCHOOL_ID LOGIN_NAME < /受保护的临时密码文件

密码 12–72 字节，首尾空格有意义；脚本不重置已有账号。读入时仅移除 stdin 结尾的换行。临时密码文件由操作者安全保管并在使用后移除。正式登录传原始密码字符串。普通会话 12 小时，rememberMe=true 为 30 天，不滑动续期；只有登录响应含原始 token，数据库只存 SHA-256 摘要。退出撤销当前 token，改密撤销该管理员全部 token。登录名+来源 IP 连续失败限 15 分钟 10 次。学校从会话取得，请求传 schoolID 不会改变管理范围。

学号补录 CSV（UTF-8，无 BOM；以文本保存，不能让表格软件去掉前导零）：

    studentID,studentNumber
    123,0000123

    php scripts/import_student_numbers.php SCHOOL_ID /私密路径/student-numbers.csv

最多 10000 行，整批事务，同一值重复导入不变；不覆盖已有不同学号、不接收跨校学生，碰到重复号整批失败。

## HTTP 约定

- 成功 HTTP 200，body {code:200,message:"success",data:...}；错误 HTTP/code 一致，data 可包含冲突 version。
- 学校接口必须 Authorization: Bearer sc_…；学生 JWT 不能登录后台。
- GET 查询、POST 创建/动作、PATCH 编辑。分页 page=1、limit=20，最多 100。
- 修改必须带 version。新班级课表 version=0；校历使用所属 semester.version；409 时重新读取，不自动覆盖。
- JSON POST/PATCH；上传 POST multipart，文件字段 file，数组字段如 weeks 为 JSON 字符串。
- 通知日期使用带时区 ISO 8601 输入。普通响应中的数据库日期为上海时间 YYYY-MM-DD HH:mm:ss；学期为 YYYY-MM-DD。

## 资源规则

### 通知

title 和 content 分别保存（content 是正文，重要通知为 Markdown）。type 为 0/1/2。发布需要 endTime，publishMode=scheduled 还需要 startTime；now 把未来通知立即发布，已发布通知编辑保留原开始时间。撤回不可再编辑，但详情和修改记录保留。

target.allSchool=true 不能与其他选择器混用；否则 compoundIDs、departmentIDs、classScopes、studentIDs 为“或”关系。classScopes 每项必须含 compoundID/departmentID/classID，不能用一个裸 classID 代表所有院系的 1 班。姓名重名不会自动合并学生；学生选择使用 studentID，查找可用 studentNumber 或 name。学号查询为精确匹配，姓名为包含查询。

学生通知从认证学生记录取范围，不信任 URL 的 studentID/classID；标题合入原 content 字段，旧无标题内容原样返回。为兼容旧 iOS 的本地二次过滤，匹配新范围的通知 target.studentIDs 会带当前学生 ID；这不改变数据库 target。通知列表与 AI 摘要共同应用范围、撤回和日期规则，不消耗 AI 额度做验收。

### 学期与课表

schoolYear 如 2026-2027，season=spring/autumn；同校学期不能重叠，最长 371 天（含首尾）。日期范围为 1900-01-01 至 2099-12-31（Sabre 安全范围）；firstMonday 必须是周一，可以晚于开学日（例如报到后才开始教学），但不能早于开学前 6 天或晚于学期结束。当前学期为最近已开始的学期（假期沿用）；从未开始过学期时兼容读取旧数据。显式 semesterID 不回退旧版本。

常规课表 upload_base；临时 upload_temporary + weeks=[3,5]，源 ICS 只描述一个星期，可为空表示所选周无课。它按上海时区星期/时间和时长映射到各教学周，**替换整周而不是追加**。同周再次上传覆盖原覆盖指针，历史文件保留；cancel_temporary 多选撤销；restore_base 恢复旧常规版本，不移除临时覆盖。

最多 1000 个原始事件、10000 个展开课次，编译结果 5 MiB。支持 Sabre 的 DAILY/WEEKLY/MONTHLY/YEARLY、INTERVAL、COUNT、UNTIL、RDATE、EXDATE 和 RECURRENCE-ID；不接收小时/分钟级循环、BYSECOND/BYMINUTE/BYHOUR 或 RDATE 的 PERIOD 形式。浮动时间和全天日期按上海时区解释，未知时区及非法日期会拒绝。要求 UID、SUMMARY、DTSTART；定时事件需 DTEND 或 DURATION，全天事件默认一天。编译只保留学期内开始的课次，原始文件仍保留。临时事件不能跨源周边界。

新结果把循环展开成独立 DTSTART/DTEND，删除客户端不支持的排除/例外规则及嵌套 VALARM（原始文件保留），UID 由课表 ID、源 UID、具体开始时刻确定（相同课次重新编译稳定，改时间会改变 UID）。调用者应为不同课程提供不同源 UID。下载返回 text/calendar，其他查询仍为 JSON。

### 广告与仪表盘

管理动作：pending → approve/reject；rejected → edit_reason/undo_rejection；approved → withdraw（必须 reason）；withdrawn → resubmit。撤销驳回、重新送审都进入 pending，不直接上线。公开列表只返回有效日期内 approved 广告，兼容未归属的旧广告；携带学生认证时进一步按学校过滤。

脚本创建演示申请（不会修改已有申请状态）：

    php scripts/seed_school_demo.php SCHOOL_ID EXISTING_SHOP_ID --demo

生成 3 条明确 isDemo=1 的 pending 记录，稳定 DEMO 订单号及演示套餐报价，总报价不等于收入；不创建商户账号、不调用支付、不提交 Windows 申请。示例图只是带 DEMO 水印的占位 SVG，不是正式商业素材。

仪表盘审核通过率=当前 approved/(approved+rejected)，无审核为 null；趋势统计最近 7 个自然日的 approve/reject 操作，processedThisWeek 是这 7 天的操作次数（不是独立广告数）。待审时长按最初提交时间计算。课表预览为当前学期选定班级接下来最多 5 节课，班级每天稳定选择；校历最多 5 个后续事件。报价字段 quoteTotal 独立；在线、曝光、点击、到账收入均为 null，并附“尚未接入”。

## 仍需单独推进

- Web 页面联调、正式部署、正式环境 HTTPS/限流/WAF/备份演练。
- 现有学生 JWT 的开发密钥轮换是既有安全债务，本轮学校后台不复用它；上线前需另立兼容迁移计划。
- 商户申请入口、Windows 客户端、真实付款/退款、流量统计、真实在线人数。
- 未归属旧广告和旧课表学期归档需人工确认，不能由脚本猜测。
