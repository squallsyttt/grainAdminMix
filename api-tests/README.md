# API 测试文件目录

## 📁 目录结构

```
api-tests/
├── README.md                    # 本文件
└── wanlshop/                    # WanlShop 模块测试
    ├── advert.http             # 广告接口测试
    └── category.http           # 商品类目接口测试
```

## 🚀 使用说明

### 1. 安装 VSCode 插件
插件名称：**REST Client**

### 2. 打开对应的测试文件
- 广告接口：[wanlshop/advert.http](wanlshop/advert.http)
- 商品类目接口：[wanlshop/category.http](wanlshop/category.http)

### 3. 发送请求
点击请求上方的 `Send Request` 按钮，或使用快捷键 `Cmd+Alt+R` (Mac) / `Ctrl+Alt+R` (Windows)

---

## 📋 测试文件对应关系

| 测试文件 | 控制器路径 | 后台管理页面 |
|---------|-----------|-------------|
| [wanlshop/advert.http](wanlshop/advert.http) | `application/api/controller/wanlshop/Advert.php` | - |
| [wanlshop/category.http](wanlshop/category.http) | `application/api/controller/wanlshop/Category.php` | [商品类目管理](http://grain.local.com/FZqKhTezgP.php/wanlshop/category/goods) |

---

## 💡 添加新的测试文件

当你开发新的接口时，在对应的模块目录下创建 `.http` 文件即可。

例如：处理订单接口时，创建 `wanlshop/order.http`

---

**创建时间**：2025-10-15
