# GrainAdminMix 代理工作约定（精简版）

最后更新时间：2025-10-26

## 作用域与优先级

- 作用域：本文件所在目录为根，向下整棵子目录生效；更深层级的 `AGENTS.md` 优先级更高。
- 优先级：系统/开发者/用户指令 > AGENTS.md。发生冲突时以前者为准。

## 项目要点

- 框架：ThinkPHP 5.x + FastAdmin
- 前端：RequireJS + Bootstrap + AdminLTE
- 数据库：MySQL（库名：`grainPro`，表前缀：`grain_`）
- 构建：Grunt（仅当修改 JS/Less 源文件时需要构建）

## 本地运行

- 安装依赖：
  - `composer install`
  - `npm ci`
- 构建资源（按需）：
  - `npm run build`
  - 或 `npx grunt frontend:js | backend:js | frontend:css | backend:css`
- 启动开发服务：
  - `php -S 127.0.0.1:8000 -t public public/index.php`
- 数据库安装（按需）：
  - `php think install --hostname=127.0.0.1 --hostport=3306 --database=grainPro --prefix=grain_ --username=... --password=...`

## 代码规范

- PHP 遵循 PSR-2；JavaScript 遵循 ESLint 约定。
- 变量/函数/类命名使用英文；注释与文档使用中文。
- 数据表：蛇形命名且必须使用 `grain_` 前缀；模型名与表对应（驼峰）。
- 控制器需接入 Auth；接口优先设计再实现。

## 变更规则

- 新功能以模块形式放在 `application/[模块名]/`。
- 不修改 FastAdmin 核心文件；避免随意重命名/移动现有文件。
- 列表接口默认分页；常查询字段请加索引。
- 仅在修改 JS/Less 源文件时才进行构建；仅改模板/HTML 不需构建。

## API 开发与测试约定

- API 控制器路径：`application/api/controller/wanlshop/`。
  - 控制器命名：大驼峰（如 `Category.php`），方法名使用小驼峰（如 `lists`、`detail`）。
  - 路由前缀：`/api/wanlshop/{resource}`（与控制器对应）。
- API 测试文件路径：`api-tests/wanlshop/`（注意是 api-tests 复数）。
  - 文件命名建议：`{resource}.http`（如 `category.http`、`advert.http`）。
  - 推荐在文件头部声明：
    - `@baseUrl`（如 `http://127.0.0.1:8000` 或本地域名）
    - `@apiPrefix = /api/wanlshop/{resource}`
  - 每个接口包含：正常用例 + 边界/异常用例（缺参、非法值、无权限等）。
  - 参考现有示例：`api-tests/wanlshop/category.http`, `api-tests/wanlshop/advert.http`。

## Agent 行为

- 采用最小必要改动，聚焦需求；不要引入无关依赖。
- 不进行 git 提交/分支操作，除非用户明确要求。
- 生成/修改文件时遵循本文件与 `CLAUDE.md` 的约定。
- 默认不生成 docs/tests，除非用户明确要求。例外：当新增 `wanlshop` 下的 API 时，必须同时在 `api-tests/wanlshop/` 新增对应 `.http` 测试文件并覆盖主要用例。

## 提交前速查

- [ ] 权限规则（如需）已注册到相应表。
- [ ] 数据库变更已执行且可回滚（如有）。
- [ ] 若改动 JS/Less 源码已构建；仅改模板无需构建。
- [ ] 未提交任何敏感信息（密钥、密码等）。

参考：`CLAUDE.md`、`.specify/memory/constitution.md`
