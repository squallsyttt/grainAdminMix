# 小程序前端 - 邀请增益页面对接指南

## 🎯 问题已解决!

### 正确的接口地址

❌ **错误**: `https://grain.griffithres.top/api/miniprogramauth/inviteInfo`

✅ **正确**: `https://grain.griffithres.top/api/mini_program_auth/inviteInfo`

### 原因说明

ThinkPHP 框架会自动将驼峰命名的控制器类名转换为下划线格式的 URL:
- 类名: `MiniProgramAuth`
- URL: `mini_program_auth`

### 其他相关接口

- ✅ 获取邀请信息: `GET /api/mini_program_auth/inviteInfo`
- ✅ 邀请用户列表: `GET /api/mini_program_auth/inviteeList`
- ✅ 绑定邀请码: `POST /api/mini_program_auth/bindInviteCode`

---

## 📱 页面功能说明

这是一个显示在**个人中心**的邀请增益模块,用户可以:
- 查看自己的邀请码
- 查看是否被别人邀请
- 查看当前的返利等级和返利比例
- 查看已邀请多少人
- 绑定别人的邀请码(如果还没被邀请)

---

## 🔌 需要对接的接口

只需要对接 **1 个接口**:

### **GET /api/mini_program_auth/inviteInfo**

**接口地址**: `GET /api/mini_program_auth/inviteInfo`
**是否需要登录**: ✅ 是(需要在 Header 中传递 token)
**请求方式**: GET
**请求参数**: 无

---

## 📤 请求示例

```javascript
// 微信小程序示例
wx.request({
  url: 'https://你的域名/api/mini_program_auth/inviteInfo',
  method: 'GET',
  header: {
    'token': wx.getStorageSync('token') // 从缓存获取登录token
  },
  success(res) {
    console.log(res.data)
    // 处理返回数据
  }
})
```

---

## 📥 返回数据结构 (Mock 示例)

### ✅ 成功返回 - 未被邀请的用户

```json
{
  "code": 1,
  "msg": "ok",
  "time": 1733097600,
  "data": {
    "inviteCode": "A3F8NK2P",
    "level": 0,
    "levelName": "Level 0",
    "rebateRate": 1.2,
    "rebateText": "Level 0 返利 1.20%",
    "invitedTotal": 3,
    "verifiedInvitees": 1,
    "pendingInvitees": 2,
    "nextLevel": 1,
    "nextRebateRate": 1.5,
    "upgradeRule": "被邀请人核销触发升级,最多2次升级",
    "recentRebates": []
  }
}
```

### ✅ 成功返回 - 已被邀请的用户

```json
{
  "code": 1,
  "msg": "ok",
  "time": 1733097600,
  "data": {
    "inviteCode": "B7H2MK9Q",
    "level": 1,
    "levelName": "Level 1",
    "rebateRate": 1.5,
    "rebateText": "Level 1 返利 1.50%",
    "invitedTotal": 8,
    "verifiedInvitees": 5,
    "pendingInvitees": 3,
    "nextLevel": 2,
    "nextRebateRate": 2.0,
    "upgradeRule": "被邀请人核销触发升级,最多2次升级",
    "recentRebates": []
  }
}
```

### ✅ 成功返回 - 已满级的用户

```json
{
  "code": 1,
  "msg": "ok",
  "time": 1733097600,
  "data": {
    "inviteCode": "C9K4LP6T",
    "level": 2,
    "levelName": "Level 2",
    "rebateRate": 2.0,
    "rebateText": "Level 2 返利 2.00%",
    "invitedTotal": 15,
    "verifiedInvitees": 12,
    "pendingInvitees": 3,
    "nextLevel": null,
    "nextRebateRate": null,
    "upgradeRule": "被邀请人核销触发升级,最多2次升级",
    "recentRebates": []
  }
}
```

### ❌ 失败返回 - 未登录

```json
{
  "code": 0,
  "msg": "请先登录",
  "time": 1733097600,
  "data": null
}
```

---

## 📋 返回字段说明

| 字段名 | 类型 | 说明 | 示例值 |
|--------|------|------|--------|
| **code** | number | 状态码,1=成功,0=失败 | 1 |
| **msg** | string | 提示消息 | "ok" |
| **time** | number | 服务器时间戳(秒) | 1733097600 |
| **data** | object | 数据对象(失败时为null) | {...} |

### data 对象字段说明

| 字段名 | 类型 | 说明 | 示例值 |
|--------|------|------|--------|
| **inviteCode** | string | 我的邀请码(8位大写字母数字) | "A3F8NK2P" |
| **level** | number | 当前返利等级(0/1/2) | 1 |
| **levelName** | string | 等级显示名称 | "Level 1" |
| **rebateRate** | number | 当前返利比例(百分比) | 1.5 |
| **rebateText** | string | 返利比例描述文案 | "Level 1 返利 1.50%" |
| **invitedTotal** | number | 我邀请的总人数 | 8 |
| **verifiedInvitees** | number | 已核销的邀请人数 | 5 |
| **pendingInvitees** | number | 未核销的邀请人数 | 3 |
| **nextLevel** | number\|null | 下一等级(满级时为null) | 2 |
| **nextRebateRate** | number\|null | 下一等级返利比例(满级时为null) | 2.0 |
| **upgradeRule** | string | 升级规则说明 | "被邀请人核销触发升级,最多2次升级" |
| **recentRebates** | array | 最近返利记录(暂时为空数组) | [] |

---

## 🎨 页面展示建议

### 基础信息展示

```
┌─────────────────────────────────┐
│   🎁 我的邀请码                  │
│   A3F8NK2P         [复制]       │
├─────────────────────────────────┤
│   👤 我是否被邀请                │
│   是 / 否                        │
├─────────────────────────────────┤
│   💰 当前返利等级                │
│   Level 1 (返利 1.50%)          │
├─────────────────────────────────┤
│   📊 我的邀请统计                │
│   总邀请: 8人                    │
│   已核销: 5人                    │
│   待核销: 3人                    │
├─────────────────────────────────┤
│   ⬆️ 升级提示                    │
│   再邀请1人核销升到 Level 2      │
│   (2.00% 返利)                   │
└─────────────────────────────────┘
```

### 判断是否被邀请的逻辑

**后端会自动处理**,前端不需要额外调用接口判断。

但如果你想在数据库层面确认,可以通过以下方式:
- 用户的 `inviter_id` 字段如果有值,说明被邀请了
- 用户的 `inviter_id` 字段如果是 `null`,说明还没被邀请

**前端建议**:如果 `invitedTotal > 0` 说明该用户有邀请能力,但这个接口**无法直接判断自己是否被邀请**。

---

## 🔄 如果需要"我是否被邀请"功能

### 方案1: 扩展现有接口 (推荐)

在 `inviteInfo` 接口返回中增加一个字段:

```json
{
  "data": {
    "inviteCode": "A3F8NK2P",
    "isInvited": true,  // 新增: 是否被邀请
    "inviterInfo": {    // 新增: 邀请人信息(如果被邀请)
      "id": 100,
      "nickname": "张三",
      "inviteCode": "X1Y2Z3A4"
    },
    // ...其他字段
  }
}
```

**后端修改**: 在 `MiniProgramAuth.php` 的 `inviteInfo()` 方法中增加查询:

```php
// 在 line 475 附近的查询改为:
$user = Db::name('user')
    ->where('id', $userId)
    ->field('id, invite_code, bonus_level, bonus_ratio, inviter_id')
    ->find();

// 在 line 497 附近增加邀请人信息查询:
$inviterInfo = null;
if (!empty($user['inviter_id'])) {
    $inviter = Db::name('user')
        ->where('id', $user['inviter_id'])
        ->field('id, nickname, invite_code')
        ->find();
    if ($inviter) {
        $inviterInfo = [
            'id' => (int)$inviter['id'],
            'nickname' => $inviter['nickname'],
            'inviteCode' => $inviter['invite_code']
        ];
    }
}

// 在返回的 data 中增加:
$this->success('ok', [
    'inviteCode' => $user['invite_code'],
    'isInvited' => !empty($user['inviter_id']), // 新增
    'inviterInfo' => $inviterInfo,              // 新增
    // ...其他字段
]);
```

### 方案2: 前端根据业务逻辑判断

如果暂时不想改后端,可以这样判断:
- 用户注册时默认没有邀请人 → 显示"绑定邀请码"入口
- 用户绑定邀请码后 → 隐藏绑定入口,显示"已被 XXX 邀请"

---

## 🔗 绑定邀请码接口 (可选)

如果你的页面需要让用户绑定邀请码,可以调用这个接口:

### **POST /api/mini_program_auth/bindInviteCode**

**请求参数**:

```json
{
  "invite_code": "A3F8NK2P"
}
```

**成功返回**:

```json
{
  "code": 1,
  "msg": "绑定成功",
  "time": 1733097600,
  "data": {
    "inviteCode": "A3F8NK2P",
    "inviterLevel": 1,
    "rebateRate": 1.5,
    "boundAt": "2024-12-02 10:30:00",
    "isFirstBind": true,
    "upgradeHint": "核销后可为邀请人升级,最多2级"
  }
}
```

**失败返回**:

```json
{
  "code": 0,
  "msg": "邀请码格式不正确",
  "time": 1733097600,
  "data": null
}
```

**常见错误消息**:
- "邀请码格式不正确" - 必须是8位大写字母数字
- "邀请码不存在" - 输入的邀请码没有对应用户
- "不能绑定自己的邀请码" - 自己绑定自己
- "已经绑定过邀请码" - 重复绑定

---

## 📊 完整的前端调用流程

```javascript
// 1. 页面加载时获取邀请信息
onLoad() {
  this.getInviteInfo()
},

// 2. 获取邀请信息
getInviteInfo() {
  wx.request({
    url: 'https://你的域名/api/mini_program_auth/inviteInfo',
    method: 'GET',
    header: {
      'token': wx.getStorageSync('token')
    },
    success: (res) => {
      if (res.data.code === 1) {
        const data = res.data.data

        this.setData({
          // 我的邀请码
          myInviteCode: data.inviteCode,

          // 返利等级
          currentLevel: data.level,
          rebateRate: data.rebateRate,
          rebateText: data.rebateText,

          // 邀请统计
          invitedTotal: data.invitedTotal,
          verifiedInvitees: data.verifiedInvitees,
          pendingInvitees: data.pendingInvitees,

          // 升级信息
          nextLevel: data.nextLevel,
          nextRebateRate: data.nextRebateRate,
          isMaxLevel: data.nextLevel === null, // 是否满级

          // 是否被邀请(需要后端扩展接口)
          // isInvited: data.isInvited,
          // inviterInfo: data.inviterInfo
        })
      } else {
        wx.showToast({
          title: res.data.msg,
          icon: 'none'
        })
      }
    }
  })
},

// 3. 复制邀请码
copyInviteCode() {
  wx.setClipboardData({
    data: this.data.myInviteCode,
    success: () => {
      wx.showToast({
        title: '邀请码已复制',
        icon: 'success'
      })
    }
  })
},

// 4. 绑定邀请码(可选)
bindInviteCode(inviteCode) {
  wx.request({
    url: 'https://你的域名/api/mini_program_auth/bindInviteCode',
    method: 'POST',
    header: {
      'token': wx.getStorageSync('token'),
      'content-type': 'application/json'
    },
    data: {
      invite_code: inviteCode
    },
    success: (res) => {
      if (res.data.code === 1) {
        wx.showToast({
          title: '绑定成功',
          icon: 'success'
        })
        // 重新获取邀请信息
        this.getInviteInfo()
      } else {
        wx.showToast({
          title: res.data.msg,
          icon: 'none'
        })
      }
    }
  })
}
```

---

## ⚠️ 注意事项

1. **邀请码格式**: 必须是8位大写字母数字,如 `A3F8NK2P`
2. **返利比例单位**: 后端返回的是百分比数字(如 1.5),显示时需要加上 `%` 符号
3. **等级上限**: 最高 Level 2,当 `nextLevel` 为 `null` 时表示已满级
4. **登录态**: 所有接口都需要在 Header 中传递 `token`
5. **是否被邀请**: 当前接口**不直接返回**这个字段,需要按照"方案1"扩展接口

---

## 🎯 页面展示完整示例(WXML)

```xml
<view class="invite-container">
  <!-- 我的邀请码 -->
  <view class="section">
    <view class="section-title">🎁 我的邀请码</view>
    <view class="invite-code-box">
      <text class="invite-code">{{myInviteCode}}</text>
      <button size="mini" bindtap="copyInviteCode">复制</button>
    </view>
  </view>

  <!-- 返利等级 -->
  <view class="section">
    <view class="section-title">💰 当前返利等级</view>
    <view class="level-info">
      <text class="level">{{rebateText}}</text>
      <view wx:if="{{!isMaxLevel}}" class="next-level">
        升到 Level {{nextLevel}} 可享 {{nextRebateRate}}% 返利
      </view>
      <view wx:else class="max-level">
        🎉 已达最高等级!
      </view>
    </view>
  </view>

  <!-- 邀请统计 -->
  <view class="section">
    <view class="section-title">📊 我的邀请统计</view>
    <view class="stats">
      <view class="stat-item">
        <text class="stat-value">{{invitedTotal}}</text>
        <text class="stat-label">总邀请</text>
      </view>
      <view class="stat-item">
        <text class="stat-value">{{verifiedInvitees}}</text>
        <text class="stat-label">已核销</text>
      </view>
      <view class="stat-item">
        <text class="stat-value">{{pendingInvitees}}</text>
        <text class="stat-label">待核销</text>
      </view>
    </view>
  </view>

  <!-- 升级提示 -->
  <view class="section" wx:if="{{!isMaxLevel}}">
    <view class="upgrade-hint">
      {{upgradeRule}}
    </view>
  </view>
</view>
```

---

## 📞 如果遇到问题

1. **接口返回 "请先登录"**: 检查 token 是否正确传递
2. **显示的返利比例不对**: 检查配置文件 `application/extra/site.php` 中的 `invite_base_ratio`, `invite_level1_ratio`, `invite_level2_ratio`
3. **需要查看被邀请人列表**: 可以调用 `GET /api/miniprogramauth/inviteeList?page=1&limit=10`

---

**文档版本**: v1.0
**最后更新**: 2024-12-01
**接口版本**: 基于 commit `e742ce1`
