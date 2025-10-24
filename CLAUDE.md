# GrainAdminMix 开发指南

最后更新时间：2025-10-24

## ⚠️ 重要：语言要求

**所有 Claude Code 的回答和生成的文档必须使用中文。**

- ✅ 代码注释：中文
- ✅ 文档说明：中文
- ✅ 提交信息：中文
- ✅ 变量命名：英文（遵循编程规范）
- ✅ 对话回复：中文

**例外情况**：代码本身（变量名、函数名、类名等）仍使用英文，遵循 PSR-2 规范。

## 项目概述

GrainAdminMix 是基于 FastAdmin（ThinkPHP + Bootstrap）的多商户后台管理系统。

### 技术栈

- **后端框架**：ThinkPHP 5.x（PSR-4 规范）
- **前端框架**：RequireJS + Bootstrap + AdminLTE
- **数据库**：MySQL 5.7+ (InnoDB 引擎)
  - **数据库名**：grainPro
  - **表前缀**：`grain_`（⚠️ 所有表必须使用此前缀）
- **构建工具**：Grunt（JS/CSS 压缩）
- **代码规范**：PSR-2 (PHP)、ESLint (JavaScript)

### 目录结构

```
application/
├── admin/              # 后台管理模块
│   ├── controller/     # 控制器（需Auth验证）
│   ├── model/          # 数据模型（含验证规则）
│   ├── view/           # 视图模板
│   └── validate/       # 请求验证规则
├── api/                # 公开API接口
├── common/             # 共享模块（模型、库、异常处理）
└── [自定义模块]/       # 功能模块
```

## 核心开发原则

遵循 `.specify/memory/constitution.md` 中定义的 5 大原则：

### 1. 模块优先架构
- 所有新功能必须作为独立模块开发
- 模块路径：`application/[模块名]/`
- 避免修改 FastAdmin 核心文件

### 2. 后端优先开发
- 开发顺序：数据库 → 模型 → 控制器 → 视图
- 先定义 API 接口，后实现前端
- 权限规则在设计阶段就要规划

### 3. MVP 快速交付（外包模式）
- 优先实现核心功能，快速验证需求
- 避免过度设计，聚焦业务价值
- 代码以可读性和可维护性为主，不强制要求单元测试
- 通过人工测试和演示验收功能

### 4. 权限感知开发
- 所有控制器操作必须集成 Auth 系统
- 菜单项必须注册到 `grain_auth_rule` 表
- 考虑多租户权限隔离

### 5. 性能与可扩展性
- 列表操作必须分页（默认 10 条）
- 优先使用模型关联而非原始 SQL
- 为常查询字段添加索引

## 常用命令

### 安装依赖
```bash
# PHP 依赖
composer install

# Node 依赖
npm ci
```

### 构建前端资源

⚠️ **重要**：仅修改页面模板、HTML、样式表不需要构建。只有修改了 `public/assets/js/**/*.js` 源文件或 `public/assets/less/**/*.less` 源文件才需要构建。

```bash
# 完整构建
npm run build

# 单独构建
npx grunt frontend:js    # 构建前端 JS
npx grunt backend:js     # 构建后台 JS
npx grunt frontend:css   # 构建前端 CSS
npx grunt backend:css    # 构建后台 CSS
```

### 启动开发服务器
```bash
php -S 127.0.0.1:8000 -t public public/index.php

# 访问地址：
# 前端：http://127.0.0.1:8000/
# 后台：http://127.0.0.1:8000/admin.php
# 安装：http://127.0.0.1:8000/install.php
```

### 数据库安装（CLI）
```bash
php think install \
  --hostname=127.0.0.1 \
  --hostport=3306 \
  --database=grainPro \
  --prefix=grain_ \
  --username=数据库用户 \
  --password=数据库密码
```

## 命名规范

- **控制器**：大驼峰 + `Controller` 后缀（如 `UserController`）
- **模型**：大驼峰，与表名对应（如 `AdminLog` 对应 `grain_admin_log`）
- **数据表**：蛇形命名 + `grain_` 前缀（如 `grain_user_profile`）
- **API 路由**：短横线命名（如 `/api/user-profile`）

⚠️ **重要**：所有数据库表必须使用 `grain_` 前缀，配置在 `.env` 文件中。

## 质量检查清单

### 提交前检查
- [ ] 新增的迁移文件已测试
- [ ] 权限规则已添加到 `grain_auth_rule`
- [ ] 未绕过模型层直接查询数据库
- [ ] 修改 JS/Less 源文件后已编译（`npm run build`）；仅修改模板/HTML 不需编译
- [ ] 未提交敏感信息（密钥、密码）

### 合并前检查
- [ ] 代码通过 PSR-2 检查
- [ ] 功能已通过人工测试
- [ ] API 文档已更新
- [ ] 语言文件已更新（zh-cn + en）
- [ ] 数据库迁移可逆（有 up/down 方法）

## 开发工作流

1. **规划阶段**：使用 `/specify` 命令创建功能规格
2. **设计阶段**：使用 `/plan` 命令生成实现计划
3. **任务阶段**：使用 `/tasks` 命令生成任务列表
4. **实现阶段**：按任务列表执行（MVP 优先，快速迭代）
5. **验证阶段**：人工测试、演示验收

## Git 工作流

⚠️ **重要规则**：

- **Claude Code 不自动提交代码**：用户会使用 `claude commit` 命令自行提交
- Claude Code 只负责修改文件，不执行 git add/commit 操作
- 完成修改后，提醒用户使用 `claude commit` 提交更改

## 文档和测试脚本生成规则

⚠️ **重要规则**：

- **不主动生成 docs/ 目录下的任何文档**：除非用户明确要求，否则不生成 API 文档、开发指南等文档
- **不主动生成测试脚本**：除非用户明确要求，否则不生成 tests/ 目录下的测试文件
- **只生成核心代码**：默认只生成必要的业务代码（模型、控制器、视图、数据库迁移等）
- **用户主动要求时**：如果用户明确说"生成文档"或"生成测试脚本"，则按要求生成

## 最近更新

- 2025-10-24：澄清前端编译规则，仅修改模板/HTML 不需要构建，只有修改 JS/Less 源文件才需编译
- 2025-10-15：添加文档和测试脚本生成规则（默认不生成，需用户主动要求）
- 2025-10-02：更新宪章 v1.1.0（原则三改为 MVP 快速交付，移除强制测试要求）
- 2025-10-02：配置数据库表前缀为 `grain_`（数据库：grainPro）
- 2025-10-02：建立中文优先的开发规范

---

*参考文档：`.specify/memory/constitution.md` (v1.1.0)*
