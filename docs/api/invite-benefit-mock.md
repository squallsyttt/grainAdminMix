# Invite Benefit Mock Responses

Base logic:
- Rebate rate by level: 0 = 1.2%, 1 = 1.5%, 2 = 2.0%.
- Invite code format: 8 uppercase alphanumeric characters (e.g., `AB12CD34`).
- Upgrade rule: triggered when an invitee completes a write-off; at most 2 upgrades.
- Envelope: `code` (1 success, 0 failure), `msg`, `time` (unix timestamp), `data`.

## GET /api/miniprogramauth/inviteInfo — Personal invite info

### Success example
```json
{
  "code": 1,
  "msg": "ok",
  "time": 1710000000,
  "data": {
    "inviteCode": "AB12CD34",
    "level": 1,
    "levelName": "Level 1",
    "rebateRate": 1.5,
    "rebateText": "Level 1 rebate 1.5%",
    "invitedTotal": 12,
    "verifiedInvitees": 8,
    "pendingInvitees": 4,
    "upgradeableTimes": 2,
    "nextLevel": 2,
    "nextRebateRate": 2.0,
    "upgradeRule": "Invitee write-off triggers level up, max 2 upgrades",
    "recentRebates": [
      {
        "inviteeId": 10201,
        "inviteeNickname": "mini-user-01",
        "orderId": "WX202403010001",
        "amount": 128.50,
        "rebateAmount": 1.93,
        "writeoffAt": "2024-03-01 10:12:30"
      }
    ]
  }
}
```

### Failure example (not logged in)
```json
{
  "code": 0,
  "msg": "please login first",
  "time": 1710000000,
  "data": null
}
```

### Fields
| Field | Type | Description | Example |
| --- | --- | --- | --- |
| code | int | Status code, 1 success / 0 failure | 1 |
| msg | string | Message | ok |
| time | int | Unix timestamp | 1710000000 |
| data.inviteCode | string | Invite code, uppercase alphanumeric length 8 | AB12CD34 |
| data.level | int | Current level (0/1/2) | 1 |
| data.levelName | string | Level label for display | Level 1 |
| data.rebateRate | number | Rebate percent for current level | 1.5 |
| data.rebateText | string | Readable rebate description | Level 1 rebate 1.5% |
| data.invitedTotal | int | Total invitees bound to this code | 12 |
| data.verifiedInvitees | int | Invitees who completed write-off | 8 |
| data.pendingInvitees | int | Invitees bound but not written off | 4 |
| data.upgradeableTimes | int | Remaining upgrade opportunities (max 2) | 2 |
| data.nextLevel | int | Next level after upgrade (null if max) | 2 |
| data.nextRebateRate | number | Rebate percent of next level | 2.0 |
| data.upgradeRule | string | Upgrade trigger and cap | Invitee write-off triggers level up, max 2 upgrades |
| data.recentRebates[] | array | Latest rebate records | - |
| data.recentRebates[].inviteeId | int | Invitee user id | 10201 |
| data.recentRebates[].inviteeNickname | string | Invitee nickname | mini-user-01 |
| data.recentRebates[].orderId | string | Order id that produced rebate | WX202403010001 |
| data.recentRebates[].amount | number | Order amount | 128.5 |
| data.recentRebates[].rebateAmount | number | Rebate amount calculated by rate | 1.93 |
| data.recentRebates[].writeoffAt | string | Write-off time | 2024-03-01 10:12:30 |

## GET /api/miniprogramauth/inviteeList — My invited users

### Success example
```json
{
  "code": 1,
  "msg": "ok",
  "time": 1710000050,
  "data": {
    "page": 1,
    "perPage": 10,
    "total": 2,
    "list": [
      {
        "userId": 20101,
        "nickname": "mini-user-02",
        "avatar": "https://cdn.example.com/avatar/20101.png",
        "inviteCode": "AB12CD34",
        "bindAt": "2024-02-28 09:15:00",
        "writeoffCount": 3,
        "lastWriteoffAt": "2024-03-02 14:22:10",
        "status": "verified",
        "rebateRate": 1.5,
        "upgradeCount": 1
      },
      {
        "userId": 20102,
        "nickname": "mini-user-03",
        "avatar": "https://cdn.example.com/avatar/20102.png",
        "inviteCode": "AB12CD34",
        "bindAt": "2024-03-02 16:45:30",
        "writeoffCount": 0,
        "lastWriteoffAt": null,
        "status": "pending",
        "rebateRate": 1.2,
        "upgradeCount": 0
      }
    ]
  }
}
```

### Failure example (token expired)
```json
{
  "code": 0,
  "msg": "token expired",
  "time": 1710000050,
  "data": null
}
```

### Fields
| Field | Type | Description | Example |
| --- | --- | --- | --- |
| code | int | Status code | 1 |
| msg | string | Message | ok |
| time | int | Unix timestamp | 1710000050 |
| data.page | int | Current page index (1-based) | 1 |
| data.perPage | int | Page size | 10 |
| data.total | int | Total invitees | 2 |
| data.list[] | array | Invitee list | - |
| data.list[].userId | int | Invitee user id | 20101 |
| data.list[].nickname | string | Invitee nickname | mini-user-02 |
| data.list[].avatar | string | Avatar URL | https://cdn.example.com/avatar/20101.png |
| data.list[].inviteCode | string | Invite code they bound | AB12CD34 |
| data.list[].bindAt | string | Bind time | 2024-02-28 09:15:00 |
| data.list[].writeoffCount | int | Number of write-offs that count toward upgrades | 3 |
| data.list[].lastWriteoffAt | string/null | Last write-off time | 2024-03-02 14:22:10 |
| data.list[].status | string | Invitee status: `pending` (bound, not written off) / `verified` (has write-off) | verified |
| data.list[].rebateRate | number | Rebate percent applied to their write-offs | 1.5 |
| data.list[].upgradeCount | int | How many times this invitee contributed to level upgrades | 1 |

## POST /api/miniprogramauth/bindInviteCode — Bind invite code

### Success example
```json
{
  "code": 1,
  "msg": "bind success",
  "time": 1710000100,
  "data": {
    "inviteCode": "CD56EF78",
    "inviterLevel": 0,
    "rebateRate": 1.2,
    "boundAt": "2024-03-03 08:00:00",
    "isFirstBind": true,
    "upgradeHint": "Write-off will upgrade inviter level, capped at 2 upgrades"
  }
}
```

### Failure examples
- Invalid format (not 8 uppercase alphanumeric):
```json
{
  "code": 0,
  "msg": "invite code format invalid",
  "time": 1710000100,
  "data": null
}
```
- Already bound to another inviter:
```json
{
  "code": 0,
  "msg": "invite code already bound",
  "time": 1710000101,
  "data": null
}
```

### Fields
| Field | Type | Description | Example |
| --- | --- | --- | --- |
| code | int | Status code | 1 |
| msg | string | Message | bind success |
| time | int | Unix timestamp | 1710000100 |
| data.inviteCode | string | Invite code that was bound | CD56EF78 |
| data.inviterLevel | int | Inviter level after binding | 0 |
| data.rebateRate | number | Rebate percent for inviter at current level | 1.2 |
| data.boundAt | string | Bind timestamp | 2024-03-03 08:00:00 |
| data.isFirstBind | boolean | Whether this is the first bind for the invitee | true |
| data.upgradeHint | string | How the write-off affects inviter upgrades | Write-off will upgrade inviter level, capped at 2 upgrades |
