# 📡 广告接口快速测试

## 🎯 三个核心接口

### 1️⃣ 列表查询 (lists)
```
GET /api/wanlshop/advert/lists
参数: module, type, category_id, limit, page
```

### 2️⃣ 快速获取 (position)
```
GET /api/wanlshop/advert/position
参数: module(必填), category_id, limit
```

### 3️⃣ 详情查询 (detail)
```
GET /api/wanlshop/advert/detail
参数: id(必填)
```

---

## ⚡ 快速开始

1. 安装 VSCode 插件：**REST Client**
2. 打开文件：`api-test.http`
3. 点击接口上方的 `Send Request`
4. 查看右侧响应结果

---

## 🔗 访问路径规则

```
http://grain.local.com/模块/控制器/方法
                     ↓
http://grain.local.com/api/wanlshop/advert/lists
```

---

## 📋 广告位置类型

| module | 说明 |
|--------|------|
| open | 开屏广告 |
| page | 页面轮播 |
| category | 分类页 |
| first | 首页推荐 |
| other | 其他 |

---

## 🚀 常用测试场景

```http
# 首页轮播（前5个）
GET http://grain.local.com/api/wanlshop/advert/position?module=page&limit=5

# 开屏广告（1个）
GET http://grain.local.com/api/wanlshop/advert/position?module=open&limit=1

# 分类1的广告
GET http://grain.local.com/api/wanlshop/advert/lists?module=category&category_id=1

# 广告详情
GET http://grain.local.com/api/wanlshop/advert/detail?id=1
```

---

完整文档：`.vscode/REST-CLIENT-GUIDE.md`
